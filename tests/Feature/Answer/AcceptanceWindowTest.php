<?php

use App\Actions\Game\CancelRound;
use App\Actions\Game\SubmitTextAnswer;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\AcceptanceWindow;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Scoring\TierWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Fenêtre d'acceptation — spec 70 § 7.3, contrat C10 § 4 (R-20), lot L70-5
|--------------------------------------------------------------------------
|
| Une soumission est recevable si la manche annoncée est `running`, si elle
| est reçue à `T₁` ou après, et avant `(ended_at ?? started_at + D) +
| tier_grace_ms` — l'instant même où partent les titres. Hors fenêtre : 409
| `closed`, et RIEN n'est compté.
|
| Les soumissions passent par la route réelle, horloge figée à l'instant de
| réception ; le rattrapage (`CatchUpGame`) exécute à cet instant les
| transitions échues, comme en production. « Accepter » se lit ici au sens
| de la fenêtre : la soumission est JUGÉE (un refus y est compté), et non
| close. Le crédit d'une bonne réponse au dernier palier arrive avec la
| transaction de verrouillage (lot L70-6).
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 14:00:00.250'));
});

/** Les tentatives restantes d'un siège qui vient d'essuyer son premier refus. */
function windowFirstRefusalLeft(Game $game): int
{
    return $game->settings_snapshot->attemptsPerRound - 1;
}

it('accepte une réponse reçue à D + tier_grace_ms − 1 au dernier palier', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);

    $receivedAt = EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms - 1);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $receivedAt)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(windowFirstRefusalLeft($game)));

    // Jugée, donc comptée : la réponse est entrée dans la fenêtre.
    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(1);

    // Rattrapée à l'instant de réception : tous les paliers servis, la manche
    // close à D — mais pas encore révélée : toujours `running`.
    $round->refresh();
    $lastTier = EngineFixtures::tier($round, $game->frames_per_round);

    expect($round->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at?->equalTo(EngineFixtures::durationEnd($round)))->toBeTrue()
        ->and($lastTier->served_at)->not->toBeNull();

    // Au dernier palier : l'instant de réception, corrigé de la grâce, tombe
    // dans la fenêtre du palier N, jamais au-delà de D.
    $corrected = RoundClock::offsetMs($round, $receivedAt) - $game->tier_grace_ms;

    expect(TierWindow::fromRoundTier($lastTier)->contains($corrected))->toBeTrue();

    // Le prédicat, sur la manche relue : la dernière milliseconde de la
    // fenêtre en est, la suivante non.
    expect(AcceptanceWindow::admits($round, $game, $receivedAt))->toBeTrue()
        ->and(AcceptanceWindow::admits($round, $game, $receivedAt->addMillisecond()))->toBeFalse();
});

it('refuse comme manche close une réponse reçue à D + tier_grace_ms, sans la compter', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);

    $receivedAt = EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms);

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $receivedAt)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French)));

    // Rien de compté, et aucune écriture de `round_player`.
    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0)
        ->and(array_filter(
            SubmissionFixtures::writes($queries),
            static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'round_player'),
        ))->toBe([]);

    // À cet instant, le rattrapage a révélé la manche : les titres partent.
    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    // La borne haute tient seule, sans l'état : une manche encore `running`
    // (révélation pas encore appliquée) est close au même instant.
    $unrevealed = $round->replicate();
    $unrevealed->status = RoundStatus::Running;

    expect(AcceptanceWindow::admits($unrevealed, $game, $receivedAt))->toBeFalse()
        ->and(AcceptanceWindow::admits($unrevealed, $game, $receivedAt->subMillisecond()))->toBeTrue()
        ->and(AcceptanceWindow::closesAt($unrevealed, $game)?->equalTo($receivedAt))->toBeTrue();
});

it('refuse une réponse sur une manche qui n\'est pas running', function (): void {
    // Programmée, pas encore démarrée : `pending` avant `T₁`.
    $token = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], open: false);
    $cadence = SubmissionFixtures::cadenceMs($game);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, EngineFixtures::opensAt($round, 1)->subMilliseconds($cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English));

    expect($round->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->exists())->toBeFalse();

    // En révélation : après `ended_at + tier_grace_ms`.
    $token = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $revealing = EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms + $cadence);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $revealing)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English));

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0);

    // Terminée : après la fin de sa révélation.
    $completed = ($round->reveal_ends_at ?? throw new LogicException('Manche sans fin de révélation.'))->addMilliseconds($cadence);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $completed)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English));

    expect($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0);

    // Annulée en pleine manche, réserve programmée à sa place.
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], reserve: 1);
    $cancelledAt = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence);

    Date::setTestNow($cancelledAt);
    app(CancelRound::class)->handle($round, RoundIncidentReason::FrameUnavailable, $cancelledAt);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $cancelledAt->addMilliseconds($cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French));

    expect($round->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0)
        ->and(Game::query()->whereKey($game->id)->value('ended_at'))->toBeNull();
});

it('refuse une réponse dont la manche annoncée n\'est pas la manche ouverte', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence);
    $next = EngineFixtures::round($game, 2);

    // La manche 1 est ouverte ; le client annonce la manche 2, puis une
    // manche qui n'existe pas.
    foreach ([$next->sequence_index, $game->rounds_count + 1] as $step => $announced) {
        SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at->addMilliseconds($step * $cadence), $announced)
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertExactJson(SubmissionFixtures::closedBody(Locale::French));
    }

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0);

    // La manche 2 s'ouvre à la fin de la révélation de la manche 1 : annoncer
    // la manche 1 est désormais une manche close, annoncer la 2 est jugé.
    EngineFixtures::close($round);
    EngineFixtures::reveal($round);

    $opened = EngineFixtures::opensAt($next, 1)->addMilliseconds($cadence);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $opened, $round->sequence_index)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French));

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $opened->addMilliseconds($cadence), $next->sequence_index)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(windowFirstRefusalLeft($game)));

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0)
        ->and(SubmissionFixtures::participation($next, $seat)->wrong_attempts)->toBe(1)
        ->and($next->refresh()->status)->toBe(RoundStatus::Running);
});

it('refuse une réponse reçue après ended_at + tier_grace_ms d\'une manche close par fin anticipée', function (): void {
    // Deux sièges, pour que les deux soumissions à une milliseconde d'écart
    // ne se disputent jamais la cadence d'un même siège.
    $early = PlayerToken::mint(Locale::French);
    $late = PlayerToken::mint(Locale::French);
    [$game, $round, [$earlySeat, $lateSeat]] = SubmissionFixtures::openedRound([$early, $late]);

    // Fin anticipée au premier palier, décidée par 60 à l'instant de
    // l'événement déclencheur.
    $endedAt = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));
    EngineFixtures::close($round, $endedAt);

    $closesAt = $endedAt->addMilliseconds($game->tier_grace_ms);

    // La fenêtre se ferme `tier_grace_ms` après la fin anticipée, bien avant D.
    expect($closesAt->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue();

    SubmissionFixtures::submit($this, $earlySeat, $early, SubmissionFixtures::WRONG, $closesAt->subMillisecond())
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(windowFirstRefusalLeft($game)));

    SubmissionFixtures::submit($this, $lateSeat, $late, SubmissionFixtures::WRONG, $closesAt)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French));

    expect(SubmissionFixtures::participation($round, $earlySeat)->wrong_attempts)->toBe(1)
        ->and(SubmissionFixtures::participation($round, $lateSeat)->wrong_attempts)->toBe(0)
        ->and($round->refresh()->status)->toBe(RoundStatus::Revealing);

    // `ended_at` d'abord : sur la manche encore `running`, la fenêtre suit la
    // fin anticipée, jamais `D`.
    $unrevealed = $round->replicate();
    $unrevealed->status = RoundStatus::Running;

    expect(AcceptanceWindow::closesAt($unrevealed, $game)?->equalTo($closesAt))->toBeTrue()
        ->and(AcceptanceWindow::admits($unrevealed, $game, $closesAt))->toBeFalse();
});

it('répond saisie close sans appeler l\'action quand le siège n\'a pas de partie courante', function (): void {
    $resolved = 0;
    $this->app->resolving(SubmitTextAnswer::class, function () use (&$resolved): void {
        $resolved++;
    });

    // Au lobby : aucune partie n'est jamais née dans ce salon.
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $host, $token, SubmissionFixtures::WRONG)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French)));

    // Après le podium : la partie du salon est close, elle n'est plus la
    // partie courante du siège.
    $english = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$english]);
    Game::query()->whereKey($game->id)->update(['status' => GameStatus::Completed->value, 'ended_at' => (new Game)->fromDateTime(Date::now())]);

    $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $english, SubmissionFixtures::WRONG)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English)))];

    // L'action n'a été ni construite ni appelée : aucune lecture de manche, de
    // participation ni de clé, aucune écriture.
    expect($resolved)->toBe(0)
        ->and(array_filter(
            array_map(static fn ($query): string => $query->sql, $queries),
            static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'round')
                || SubmissionFixtures::touches($sql, 'round_player')
                || SubmissionFixtures::touches($sql, 'answer_key'),
        ))->toBe([])
        ->and(SubmissionFixtures::writes($queries))->toBe([])
        ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0)
        ->and(Round::query()->whereKey($round->id)->value('status'))->toBe(RoundStatus::Running);
});
