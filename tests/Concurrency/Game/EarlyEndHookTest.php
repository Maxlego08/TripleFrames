<?php

use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\AnswerAccepted;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Listeners\Game\CloseSeatInput;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Support\Game\RoundStep;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Crochets de fin de saisie sous MySQL — spec 60 § 8.2, § 9.1 et § 9.2,
| contrat C7 § 4.3 et § 4.7 (lot L60-11)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation`.
|
| Les écouteurs de 60 des événements de domaine de 70 (`AnswerAccepted`,
| `InputClosed`), livrés après le commit de la clôture de saisie, ouvrent
| leur propre transaction, dont la PREMIÈRE lecture est `round FOR UPDATE`,
| puis appellent `SeatInputClosed` avec l'instant écrit de la clôture. Les
| trois premiers intitulés passent par les routes réelles — soumission et
| clic —, donc par les vrais événements et les vrais écouteurs ; le dernier
| joue l'entrelacement de deux écouteurs sur deux connexions.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge (patron
| de `LockTransactionTest`) : les deux derniers verrouillages sont validés,
| leurs écouteurs retenus ; l'écouteur du premier s'exécute sur une connexion
| jumelle, dans une transaction laissée ouverte — il voit l'état complet et
| clôt la manche, sans rien valider ; celui du second, sur la connexion par
| défaut, BUTE sur la ligne `round` à sa première instruction, avant d'avoir
| rien lu (`innodb_lock_wait_timeout` de session : la borne de l'attente,
| jamais le verdict). Validée la jumelle, le second, rejoué, trouve la manche
| close et ne la reclôt pas. Sans le verrou en première lecture, le second
| lirait un instantané où `ended_at` est nul.
|
| Les écouteurs sont synchrones : Redis n'y porte ni verrou ni file (C7
| § 4.3) ; les jobs de frontière restent simulés.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 20:10:00.250'));
});

/**
 * Une partie multijoueur à cette difficulté, un siège par jeton, manche 1
 * ouverte à `T₁` par la transition réelle, film au titre imposé.
 *
 * @return array{game: Game, round: Round, seats: list<Player>, tokens: list<PlayerToken>, cadence: int, t1: CarbonImmutable}
 */
function earlyEndHookRound(InputDifficulty $difficulty, int $seats): array
{
    $tokens = array_map(static fn (): PlayerToken => PlayerToken::mint(Locale::French), range(1, $seats));
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, $players] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings($difficulty), $target);

    return [
        'game' => $game,
        'round' => $round,
        'seats' => $players,
        'tokens' => $tokens,
        'cadence' => SubmissionFixtures::cadenceMs($game),
        't1' => EngineFixtures::opensAt($round, 1),
    ];
}

/** Les jobs `Reveal` programmés pour la manche, par instant dû. */
function earlyEndHookRevealJobs(Round $round): array
{
    return Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id && $job->step === RoundStep::Reveal)
        ->map(static fn (AdvanceRound $job): string => $job->dueAt)
        ->values()
        ->all();
}

test('le crochet de fin de saisie émet player.locked et clôt la manche quand tous les participants ont leur saisie close', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['game' => $game, 'round' => $round, 'seats' => [$first, $second, $away], 'tokens' => $tokens, 'cadence' => $cadence, 't1' => $t1] = earlyEndHookRound(InputDifficulty::Expert, 3);

    // Un siège déconnecté n'est pas un participant : il ne retient jamais la
    // manche.
    $away->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $t1])->save();
    $recorder->sent = [];

    $firstAt = $t1->addMilliseconds($cadence);
    SubmissionFixtures::submit($this, $first, $tokens[0], 'Harbour Lights', $firstAt)->assertOk()->assertJson(['lockRank' => 1]);

    // Une saisie encore ouverte : l'annonce, sans clôture.
    expect(array_column($recorder->sent, 'event'))->toBe(['player.locked'])
        ->and($recorder->sent[0]['payload'])->toMatchArray(['publicId' => $first->public_id, 'lockRank' => 1])
        ->and($round->refresh()->ended_at)->toBeNull();

    $secondAt = $firstAt->addMilliseconds($cadence);
    SubmissionFixtures::submit($this, $second, $tokens[1], 'Harbour Lights', $secondAt)->assertOk()->assertJson(['lockRank' => 2]);

    // La dernière saisie ouverte se clôt : l'annonce, puis la clôture, à
    // l'instant écrit de la clôture de saisie — l'instant de réception.
    $round->refresh();
    $closedAt = SubmissionFixtures::participation($round, $second)->input_closed_at;

    expect(array_column($recorder->sent, 'event'))->toBe(['player.locked', 'player.locked', 'round.closed'])
        ->and($recorder->sent[1]['payload'])->toMatchArray(['publicId' => $second->public_id, 'lockRank' => 2])
        ->and($closedAt?->equalTo($secondAt))->toBeTrue()
        ->and($round->ended_at?->equalTo($secondAt))->toBeTrue()
        ->and($round->status)->toBe(RoundStatus::Running)
        ->and($recorder->sent[2]['payload']['endedAt'])->toBe(WireTime::iso($secondAt))
        ->and(earlyEndHookRevealJobs($round))->toBe([WireTime::iso($secondAt->addMilliseconds($game->tier_grace_ms))]);
});

test('un siège en text_exhausted empêche la fin anticipée', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['game' => $game, 'round' => $round, 'seats' => [$finder, $exhausted], 'tokens' => $tokens, 'cadence' => $cadence, 't1' => $t1] = earlyEndHookRound(InputDifficulty::Normal, 2);
    SubmissionFixtures::decoyCandidates();
    $tN = EngineFixtures::opensAt($round, $game->frames_per_round);

    // Le texte libre épuisé d'abord : la saisie attend le QCM (D20 du
    // 23/09), et n'est PAS close — aucun `InputClosed`, aucune clôture.
    SubmissionFixtures::spend($round, $exhausted, $game->settings_snapshot->attemptsPerRound - 1);
    SubmissionFixtures::submit($this, $exhausted, $tokens[1], SubmissionFixtures::WRONG, $t1->addMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    // Puis l'autre participant verrouille : le crochet est réévalué alors que
    // la seule saisie non close est `text_exhausted` — il annonce, et ne
    // clôt rien.
    SubmissionFixtures::submit($this, $finder, $tokens[0], 'Harbour Lights', $t1->addMilliseconds(2 * $cadence))->assertOk();

    expect(collect($recorder->sent)->last()['event'] ?? null)->toBe('player.locked')
        ->and($round->refresh()->ended_at)->toBeNull();

    // Le QCM s'ouvre à `T_N`, et lui est poussé ; la manche court toujours.
    $clickedAt = SubmissionFixtures::openChoices($round)->addMilliseconds($cadence);

    expect($round->refresh()->ended_at)->toBeNull()
        ->and(collect($recorder->sent)->where('event', 'seat.choices')->count())->toBe(1);

    // Son clic ferme sa saisie : la manche se clôt à l'instant du clic.
    SubmissionFixtures::click($this, $exhausted, $tokens[1], SubmissionFixtures::wrongChoice($round, $exhausted), $clickedAt)
        ->assertOk()
        ->assertJson(['inputState' => RoundPlayerInputState::QcmWrong->value]);

    expect($round->refresh()->ended_at?->equalTo($clickedAt))->toBeTrue()
        ->and(collect($recorder->sent)->last()['event'] ?? null)->toBe('round.closed');
});

test('la révélation inclut toute réponse acceptée avant ended_at + tier_grace_ms', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['game' => $game, 'round' => $round, 'seats' => [$finder, $spent, $late, $tooLate], 'tokens' => $tokens, 'cadence' => $cadence, 't1' => $t1] = earlyEndHookRound(InputDifficulty::Expert, 4);

    // Deux sièges déconnectés : leur saisie reste ouverte, mais ils ne
    // retiennent pas la manche.
    foreach ([$late, $tooLate] as $seat) {
        $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $t1])->save();
    }

    SubmissionFixtures::submit($this, $finder, $tokens[0], 'Harbour Lights', $t1->addMilliseconds($cadence))->assertOk();

    // La dernière saisie des participants s'épuise : fin anticipée à cet
    // instant, et les titres ne partiront qu'à `ended_at + tier_grace_ms`.
    SubmissionFixtures::spend($round, $spent, $game->settings_snapshot->attemptsPerRound - 1);
    $endedAt = $t1->addMilliseconds(2 * $cadence);

    SubmissionFixtures::submit($this, $spent, $tokens[1], SubmissionFixtures::WRONG, $endedAt)
        ->assertOk()
        ->assertJson(['inputState' => RoundPlayerInputState::AttemptsExhausted->value]);

    expect($round->refresh()->ended_at?->equalTo($endedAt))->toBeTrue();

    $revealStartsAt = $endedAt->addMilliseconds($game->tier_grace_ms);

    // Reçue une milliseconde avant les titres : acceptée, annoncée, et la
    // manche n'est pas reclose.
    SubmissionFixtures::submit($this, $late, $tokens[2], 'Harbour Lights', $revealStartsAt->subMillisecond())
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'lockRank' => 2]);

    expect($round->refresh()->ended_at?->equalTo($endedAt))->toBeTrue()
        ->and(collect($recorder->sent)->last()['payload']['publicId'] ?? null)->toBe($late->public_id);

    // Reçue à `ended_at + tier_grace_ms` : la révélation passe d'abord (le
    // rattrapage de la soumission), la saisie est close, rien n'est compté.
    SubmissionFixtures::submit($this, $tooLate, $tokens[3], 'Harbour Lights', $revealStartsAt)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJson(['result' => 'closed']);

    $revealed = collect($recorder->sent)->firstWhere('event', 'round.revealed');

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and($round->found_count)->toBe(2)
        ->and($revealed['payload']['serverNow'] ?? null)->toBe(WireTime::iso($revealStartsAt))
        ->and(array_column($revealed['payload']['finders'] ?? [], 'publicId'))->toBe([$finder->public_id, $late->public_id]);
});

test('deux derniers verrouillages concurrents clôturent la manche une seule fois', function (): void {
    $recorder = RecordingBroadcaster::install();
    ['game' => $game, 'round' => $round, 'seats' => [$first, $second], 'tokens' => $tokens, 'cadence' => $cadence, 't1' => $t1] = earlyEndHookRound(InputDifficulty::Expert, 2);
    $at = $t1->addMilliseconds($cadence);

    // Les deux derniers verrouillages, reçus au même instant, sont validés ;
    // leurs écouteurs sont retenus pour être joués l'un contre l'autre.
    $accepted = [];

    Event::fakeFor(function () use ($first, $second, $tokens, $at, &$accepted): void {
        SubmissionFixtures::submit($this, $first, $tokens[0], 'Harbour Lights', $at)->assertOk()->assertJson(['lockRank' => 1]);
        SubmissionFixtures::submit($this, $second, $tokens[1], 'Harbour Lights', $at)->assertOk()->assertJson(['lockRank' => 2]);

        $accepted = Event::dispatched(AnswerAccepted::class)->map(static fn (array $call): AnswerAccepted => $call[0])->values()->all();
    }, [AnswerAccepted::class]);

    expect($accepted)->toHaveCount(2)
        ->and($round->refresh()->ended_at)->toBeNull();

    $recorder->sent = [];
    $default = DB::getDefaultConnection();
    $rival = 'early_end_hook_rival';
    $blocked = null;
    $blockedQueries = [];
    $recording = false;

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    // Les requêtes abouties de la connexion par défaut pendant l'écouteur
    // bloqué, et pendant lui seul (patron de `LaunchConcurrencyTest`) : ni le
    // `SET SESSION` qui le précède, ni les lectures de contrôle qui le suivent.
    DB::listen(static function (QueryExecuted $query) use (&$blockedQueries, &$recording, $default): void {
        if ($recording && $query->connectionName === $default) {
            $blockedQueries[] = $query->sql;
        }
    });

    try {
        // 1. L'écouteur du premier, sur la jumelle, transaction laissée
        // ouverte : il voit les deux saisies closes et clôt la manche, sans
        // rien valider ni annoncer.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        app(CloseSeatInput::class)->answerAccepted($accepted[0]);

        expect(DB::connection($rival)->table('round')->where('id', $round->id)->value('ended_at'))->not->toBeNull()
            ->and($recorder->sent)->toBe([]);

        // 2. L'écouteur du second, sur la connexion par défaut : sa première
        // instruction est le verrou de la manche, sur lequel il bute avant
        // d'avoir rien lu — il ne décide jamais sur un instantané périmé.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $recording = true;

        try {
            app(CloseSeatInput::class)->answerAccepted($accepted[1]);
        } catch (QueryException $exception) {
            $blocked = $exception;
        } finally {
            $recording = false;
        }

        expect(Round::query()->whereKey($round->id)->value('ended_at'))->toBeNull()
            ->and($recorder->sent)->toBe([]);

        // 3. La jumelle valide : la clôture et l'annonce du premier partent.
        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(strtolower((string) $blocked?->getSql()))->toContain('`round`')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('for update')
        // Rien n'a été lu avant le verrou : aucune requête de la connexion
        // par défaut n'a abouti avant l'instruction en échec — ni la
        // participation, ni les participants de la manche.
        ->and(array_values(array_filter($blockedQueries, static fn (string $sql): bool => ! str_starts_with(strtolower(ltrim($sql)), 'set '))))
        ->toBe([])
        ->and(array_column($recorder->sent, 'event'))->toBe(['player.locked', 'round.closed']);

    // 4. Le second, rejoué comme le fait la requête en attente quand le
    // verrou se libère : il trouve la manche close, l'annonce, et ne la
    // reclôt pas.
    $round->refresh();
    $endedAt = $round->ended_at;
    $writes = SubmissionFixtures::writes(SubmissionFixtures::queries(static fn () => app(CloseSeatInput::class)->answerAccepted($accepted[1])));

    expect($endedAt?->equalTo($at))->toBeTrue()
        ->and($writes)->toBe([])
        ->and($round->refresh()->ended_at?->equalTo($endedAt))->toBeTrue()
        ->and(array_column($recorder->sent, 'event'))->toBe(['player.locked', 'round.closed', 'player.locked'])
        ->and(array_column(array_column($recorder->sent, 'payload'), 'publicId'))->toBe([$first->public_id, $second->public_id])
        ->and(earlyEndHookRevealJobs($round))->toBe([WireTime::iso($at->addMilliseconds($game->tier_grace_ms))]);
});
