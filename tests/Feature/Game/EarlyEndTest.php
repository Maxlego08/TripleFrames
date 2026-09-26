<?php

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\SeatInputClosed;
use App\Enums\GameMode;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Game\RoundStep;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;

/*
|--------------------------------------------------------------------------
| Fin anticipée — spec 60 § 8.2, § 9.1 à § 9.3 ; 10 § 7.7 (lot L60-7)
|--------------------------------------------------------------------------
|
| Le prédicat de 10 § 7.7 amendé par D20 du 23/09 (E10-53) : fin anticipée si
| et seulement si AU MOINS UN participant (ligne `round_player` dont le siège
| est `connected` et `left_at` nul) et TOUS à saisie close — `text_exhausted`
| n'est pas une saisie close. Il est réévalué par `SeatInputClosed`, le
| crochet que les écouteurs de 70 (L60-11), le balayage de présence (L60-13),
| le départ et l'expulsion (50) appellent avec l'instant ÉCRIT de
| l'événement déclencheur. Les intitulés de 10 § 7.7 sont conservés ; ceux de
| la déconnexion du dernier participant et du départ ou de l'expulsion
| arrivent avec L60-13, qui livre le balayage et reçoit les gestes de 50.
|
| Les clôtures de saisie de 70 (verrouillage, clic faux, tentatives
| épuisées) ne sont pas encore livrées : elles sont écrites ici comme 70 les
| écrira — état, `input_closed_at` —, puis le crochet est appelé comme son
| écouteur l'appellera. Parties matérialisées par l'action réelle, manches
| ouvertes par les transitions réelles, aucune valeur de jeu en littéral.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Une partie multijoueur dont la manche 1 est ouverte à son palier 1, avec
 * ces sièges (connectés à `T₁` sauf mention) : les lignes `round_player`
 * naissent par `OpenTier(1)`, comme en production.
 *
 * @param  array<string, array{player?: array<string, mixed>, firstRoundNumber?: int}>  $seats
 * @return array{Game, Round, array<string, Player>}
 */
function earlyEndOpenedRound(array $seats): array
{
    $game = EngineFixtures::game(EngineFixtures::settings());
    $players = [];

    foreach ($seats as $label => $seat) {
        $players[$label] = EngineFixtures::seat($game, $seat['player'] ?? [], $seat['firstRoundNumber'] ?? null);
    }

    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);

    EngineFixtures::schedule($round, Date::now()->toImmutable()->addSeconds(5));
    EngineFixtures::openTier($round, 1);

    return [$game, $round->refresh(), $players];
}

/** La participation d'un siège à une manche, relue en base. */
function earlyEndParticipation(Round $round, Player $seat): RoundPlayer
{
    return RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->firstOrFail();
}

/**
 * La saisie d'un siège se clôt à `$closedAt`, comme 70 l'écrira ; puis
 * l'écouteur appelle le crochet à `$runsAt` (en retard ou non) avec l'instant
 * ÉCRIT de l'événement.
 */
function earlyEndCloseInput(
    Round $round,
    Player $seat,
    RoundPlayerInputState $state,
    CarbonImmutable $closedAt,
    ?CarbonImmutable $runsAt = null,
    ?Guess $guess = null,
): RoundPlayer {
    RoundPlayer::query()
        ->where('round_id', $round->id)
        ->where('player_id', $seat->id)
        ->update([
            'input_state' => $state->value,
            'input_closed_at' => (new RoundPlayer)->fromDateTime($closedAt),
        ]);

    $participation = earlyEndParticipation($round, $seat);
    $written = $participation->input_closed_at ?? throw new LogicException('Saisie close sans instant.');

    Date::setTestNow($runsAt ?? $closedAt);

    DB::transaction(static function () use ($round, $participation, $guess, $written): void {
        $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

        app(SeatInputClosed::class)->handle($lockedRound, $participation, $guess, $written);
    });

    return $participation;
}

/** Le siège sort des participants : il passe `disconnected` à `$at`. */
function earlyEndDisconnect(Player $seat, CarbonImmutable $at): void
{
    $seat->forceFill([
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => $at,
    ])->save();
}

test('tous les participants déconnectés → la manche se clôt à D, pas avant', function (): void {
    [$game, $round, $seats] = earlyEndOpenedRound(['a' => [], 'b' => []]);
    $t1 = EngineFixtures::opensAt($round, 1);
    $durationEnd = EngineFixtures::durationEnd($round);

    // Les deux sièges étaient là à T₁ : deux lignes `open`.
    expect(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(2);

    // A épuise ses tentatives puis perd le réseau ; B perd le réseau, saisie
    // encore ouverte. Chaque sortie des participants réévalue le prédicat,
    // avec l'instant écrit de la transition (§ 13.2).
    earlyEndCloseInput($round, $seats['a'], RoundPlayerInputState::AttemptsExhausted, $t1->addMilliseconds(2_137));

    // A, seul participant à saisie close, aurait clos la manche s'il était
    // resté ; B, connecté et ouvert, l'empêche.
    expect($round->refresh()->ended_at)->toBeNull();

    foreach (['a' => 3_411, 'b' => 4_902] as $label => $afterT1) {
        $at = $t1->addMilliseconds($afterT1);
        earlyEndDisconnect($seats[$label], $at);
        Date::setTestNow($at);

        DB::transaction(static function () use ($round, $seats, $label, $at): void {
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            app(SeatInputClosed::class)->handle($lockedRound, earlyEndParticipation($round, $seats[$label]), null, $at);
        });
    }

    // Zéro participant : jamais une clôture anticipée, même si toute saisie
    // restante était close.
    expect($round->refresh()->ended_at)->toBeNull()
        ->and($round->status)->toBe(RoundStatus::Running)
        ->and($round->isEarlyEndReached())->toBeFalse();

    // La manche court jusqu'à D : une milliseconde avant, le rattrapage ouvre
    // les paliers échus, sans clore.
    app(CatchUpGame::class)->handle($game, $durationEnd->subMillisecond());

    expect($round->refresh()->ended_at)->toBeNull()
        ->and(RoundPlayer::query()->where('round_id', $round->id)->whereNotNull('input_closed_at')->count())->toBe(1);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        expect(EngineFixtures::tier($round, $tierIndex)->served_at?->equalTo(EngineFixtures::opensAt($round, $tierIndex)))->toBeTrue();
    }

    // À D, et à D seulement : la clôture à l'instant théorique.
    app(CatchUpGame::class)->handle($game, $durationEnd);

    expect($round->refresh()->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Running);
});

test('un joueur connecté sans ligne round_player ne bloque pas la fin anticipée', function (): void {
    [$game, $round, $seats] = earlyEndOpenedRound([
        'joueur' => [],
        // Retardataire admis à la manche suivante, connecté : aucune ligne
        // dans la manche 1 (E10-49).
        'retardataire' => ['firstRoundNumber' => 2],
    ]);
    $t1 = EngineFixtures::opensAt($round, 1);

    expect(RoundPlayer::query()->where('round_id', $round->id)->pluck('player_id')->all())->toBe([$seats['joueur']->id])
        ->and($seats['retardataire']->refresh()->connection_state)->toBe(PlayerConnectionState::Connected);

    // Le seul participant clôt sa saisie par un clic faux : fin anticipée,
    // le retardataire connecté n'entre pas au dénominateur.
    $closedAt = $t1->addMilliseconds(6_283);
    earlyEndCloseInput($round, $seats['joueur'], RoundPlayerInputState::QcmWrong, $closedAt);

    $round->refresh();

    expect($round->ended_at?->equalTo($closedAt))->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Running)
        ->and($round->reveal_ends_at?->equalTo($closedAt->addMilliseconds($game->tier_grace_ms)->addSeconds($game->settings_snapshot->revealDuration)))->toBeTrue()
        ->and(Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id && $job->step === RoundStep::Reveal)
            ->map(static fn (AdvanceRound $job): string => $job->dueAt)
            ->all())->toBe([WireTime::iso($closedAt->addMilliseconds($game->tier_grace_ms))]);
});

test('à un seul siège connecté en multijoueur, la fin anticipée reste active et game.mode reste multiplayer', function (): void {
    $recorder = RecordingBroadcaster::install();

    [$game, $round, $seats] = earlyEndOpenedRound(['présent' => [], 'absent' => []]);
    $t1 = EngineFixtures::opensAt($round, 1);

    // Le second siège perd le réseau après T₁ : sa ligne reste, mais il n'est
    // plus participant ; il ne bloque jamais la fin anticipée.
    earlyEndDisconnect($seats['absent'], $t1->addMilliseconds(1_500));
    $recorder->sent = [];

    // Le seul siège connecté trouve : `AnswerAccepted` de 70, puis le crochet.
    $closedAt = $t1->addMilliseconds(3_781);
    $guess = Guess::factory()->forRound($round, $seats['présent'])->atTier(1, $game->settings_snapshot)->withRank(1)->create([
        'received_at' => $closedAt,
    ]);

    earlyEndCloseInput($round, $seats['présent'], RoundPlayerInputState::Locked, $closedAt, guess: $guess);

    $round->refresh();

    // La manche se clôt d'anticipation ; la partie reste multijoueur, sans
    // aucun geste d'entraînement (§ 9.3) : `lone_player` est un affichage.
    expect($round->ended_at?->equalTo($closedAt))->toBeTrue()
        ->and($game->refresh()->mode)->toBe(GameMode::Multiplayer)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->whereIn('input_state', [RoundPlayerInputState::Revealed->value, RoundPlayerInputState::Skipped->value])->count())->toBe(0)
        ->and(earlyEndParticipation($round, $seats['absent'])->input_state)->toBe(RoundPlayerInputState::Open);

    // Sur le fil : `player.locked` — sequenceIndex, publicId, lockRank, rien
    // d'autre —, puis la clôture.
    expect(array_column($recorder->sent, 'event'))->toBe(['player.locked', 'round.closed'])
        ->and(array_diff_key($recorder->sent[0]['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe([
            'sequenceIndex' => 1,
            'publicId' => $seats['présent']->public_id,
            'lockRank' => 1,
        ])
        ->and($recorder->sent[1]['payload']['endedAt'])->toBe(WireTime::iso($closedAt));
});

test('la fin anticipée prend pour ended_at l\'instant de clôture écrit et jamais l\'heure d\'exécution de l\'écouteur', function (): void {
    $recorder = RecordingBroadcaster::install();

    [$game, $round, $seats] = earlyEndOpenedRound(['seul' => []]);
    $t1 = EngineFixtures::opensAt($round, 1);

    // La saisie se clôt à `closedAt` (instant de réception de 70, à la
    // milliseconde écrite) ; l'écouteur s'exécute 2,7 s plus tard.
    $closedAt = $t1->addMilliseconds(4_518);
    $runsAt = $closedAt->addMilliseconds(2_700);
    $recorder->sent = [];

    $participation = earlyEndCloseInput($round, $seats['seul'], RoundPlayerInputState::AttemptsExhausted, $closedAt, $runsAt);

    $round->refresh();
    $revealStartsAt = $closedAt->addMilliseconds($game->tier_grace_ms);

    // `ended_at`, la fin de révélation, la fenêtre d'acceptation qui en
    // dépend et le job de révélation suivent l'instant ÉCRIT, jamais l'heure
    // d'exécution de l'écouteur.
    expect($participation->input_closed_at?->equalTo($closedAt))->toBeTrue()
        ->and($round->ended_at?->equalTo($closedAt))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealStartsAt->addSeconds($game->settings_snapshot->revealDuration)))->toBeTrue()
        ->and(Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id && $job->step === RoundStep::Reveal)
            ->map(static fn (AdvanceRound $job): string => $job->dueAt)
            ->all())->toBe([WireTime::iso($revealStartsAt)]);

    // Sur le fil, `round.closed` part à l'exécution (son `serverNow`), mais
    // annonce l'instant écrit.
    expect(array_column($recorder->sent, 'event'))->toBe(['round.closed'])
        ->and($recorder->sent[0]['payload']['serverNow'])->toBe(WireTime::iso($runsAt))
        ->and($recorder->sent[0]['payload']['endedAt'])->toBe(WireTime::iso($closedAt));

    // Un crochet rejoué plus tard encore ne déplace rien.
    Date::setTestNow($runsAt->addSeconds(1));

    DB::transaction(static function () use ($round, $participation): void {
        $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

        app(SeatInputClosed::class)->handle($lockedRound, $participation, null, Date::now()->toImmutable());
    });

    expect($round->refresh()->ended_at?->equalTo($closedAt))->toBeTrue()
        ->and(array_column($recorder->sent, 'event'))->toBe(['round.closed']);

    // Le rattrapage suivant révèle à `ended_at + tier_grace_ms` : les paliers
    // que la fin anticipée a empêchés de s'ouvrir ne s'ouvrent jamais.
    Date::setTestNow($revealStartsAt->addSeconds(1));
    app(CatchUpGame::class)->handle($game, $revealStartsAt->addSeconds(1));

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and(RoundTier::query()->where('round_id', $round->id)->whereNotNull('served_at')->pluck('tier_index')->all())->toBe([1]);
});

test('un siège au texte épuisé empêche la fin anticipée jusqu\'à son clic', function (): void {
    // Ajout (D20 du 23/09, E10-53) : `text_exhausted` n'est pas une saisie
    // close ; le cas concurrent vit en `tests/Concurrency` (L60-11).
    [$game, $round, $seats] = earlyEndOpenedRound(['épuisé' => [], 'trouveur' => []]);
    $t1 = EngineFixtures::opensAt($round, 1);

    RoundPlayer::query()
        ->where('round_id', $round->id)
        ->where('player_id', $seats['épuisé']->id)
        ->update(['input_state' => RoundPlayerInputState::TextExhausted->value, 'input_closed_at' => null]);

    earlyEndCloseInput($round, $seats['trouveur'], RoundPlayerInputState::AttemptsExhausted, $t1->addMilliseconds(2_000));

    expect($round->refresh()->ended_at)->toBeNull();

    // Son clic QCM ferme sa saisie : la manche se clôt à cet instant.
    $clickedAt = $t1->addMilliseconds(3_250);
    earlyEndCloseInput($round, $seats['épuisé'], RoundPlayerInputState::QcmWrong, $clickedAt);

    expect($round->refresh()->ended_at?->equalTo($clickedAt))->toBeTrue()
        ->and($game->refresh()->mode)->toBe(GameMode::Multiplayer);
});
