<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Game\RecordHeartbeat;
use App\Actions\Game\SeatInputClosed;
use App\Enums\ContentAvailability;
use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Jobs\Game\SweepSeatPresence;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettingsBounds;
use App\Support\Deploy\DeployDrain;
use App\Support\Game\GameJournal;
use App\Support\Game\GamesInProgress;
use App\Support\Game\RoundStep;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Prédicat « partie en cours » et game:reschedule — spec 60 § 14.4 et § 17 (lots L60-10, L60-13 ; contrat C17)
|--------------------------------------------------------------------------
|
| Le prédicat (`Game::inProgress()`, compté et résumé par `GamesInProgress`)
| est ce que le drainage de 100 attend ; `game:reschedule` est ce qu'il
| appelle avant d'attendre, pour qu'aucune partie aux jobs perdus ne le
| bloque jusqu'à l'échéance. L'intitulé « la reprise d'une partie en pause
| reste permise pendant un drainage » est de L60-13, qui livre `ResumeGame`
| et le battement qui l'appelle.
|
| La terminaison bornée se prouve par un WORKER SIMULÉ de la file `game` :
| les jobs réellement dispatchés par le moteur (file simulée), dépilés dans
| l'ordre de leurs échéances et exécutés comme par le vrai worker, horloge
| avancée à l'échéance — aucune transition n'y est appelée à la main. Une
| partie qui se retrouverait sans job ne finirait jamais : le worker le
| constate à l'étape même.
|
| Parties matérialisées par l'action réelle (`EngineFixtures`), vraies
| variantes à fichier réel ; aucune valeur de jeu en littéral : durées,
| échéances et bornes relues sur la partie, ses manches et les constantes.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    Sleep::fake();

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Les parcours d'une partie que le worker simulé mène à leur terme, chacun
 * par un autre chemin de fin : fin normale, solo, fin anticipée, pause puis
 * interruption, annulation remplacée par la réserve, annulation sans manche
 * restante.
 *
 * @return list<string>
 */
function gamesInProgressJourneys(): array
{
    return [
        'multijoueur mené à son terme',
        'solo mené à son terme',
        'fin anticipée de la manche 1',
        'pause puis interruption',
        'annulation remplacée par la réserve',
        'annulation sans manche restante',
    ];
}

/**
 * Une partie lancée comme le ferait le lancement (L50-7a) — manche 1
 * programmée au bout du décompte —, préparée pour le parcours, avec son
 * issue attendue et le geste joueur que le parcours glisse entre deux jobs.
 *
 * @return array{Game, GameStatus, (Closure(AdvanceRound|InterruptPausedGame): void)|null}
 */
function gamesInProgressJourney(string $journey): array
{
    $game = EngineFixtures::game(EngineFixtures::settings(), solo: $journey === 'solo mené à son terme');

    // Personne n'est présent en fin de révélation quand le seul siège est
    // déconnecté : la partie se met en pause (§ 14.1).
    $seat = EngineFixtures::seat($game, $journey === 'pause puis interruption'
        ? ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]
        : []);

    $movies = EngineFixtures::materialize($game, reserve: $journey === 'annulation remplacée par la réserve' ? 1 : 0);
    $firstLevel = FrameLevelCoverage::nominal($game->frames_per_round)[0];

    // L'unique variante du palier 1 d'une manche est retirée : sa frappe, à la
    // révélation de la manche précédente, l'annule (`no_variant_available`) —
    // remplacée par la réserve pour la manche 2, sans manche restante pour la
    // dernière, et la partie se gèle alors à la fin de la révélation en cours.
    $withdrawn = match ($journey) {
        'annulation remplacée par la réserve' => $movies[1],
        'annulation sans manche restante' => $movies[$game->rounds_count - 1],
        default => null,
    };

    $withdrawn !== null && EngineFixtures::variant($withdrawn, $firstLevel)
        ->forceFill(['availability' => ContentAvailability::Withdrawn])
        ->save();

    EngineFixtures::schedule(
        EngineFixtures::round($game, 1),
        Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()),
    );

    $gesture = $journey === 'fin anticipée de la manche 1' ? gamesInProgressEarlyEnd($seat) : null;

    return [$game->refresh(), $journey === 'pause puis interruption' ? GameStatus::Interrupted : GameStatus::Completed, $gesture];
}

/**
 * Le parcours a bien pris son chemin de fin : sans cette preuve, un parcours
 * qui dériverait vers la fin normale passerait sans rien éprouver.
 */
function gamesInProgressAssertJourney(string $journey, Game $game): void
{
    $game->refresh();
    $roundsCount = $game->rounds_count;
    $rounds = Round::query()->where('game_id', $game->id)->orderBy('sequence_index')->get();
    $cancelled = $rounds->where('status', RoundStatus::Cancelled)->values();
    $lastPlayed = $rounds->where('status', RoundStatus::Completed)->sortBy('round_number')->last();
    $endedAt = $game->ended_at ?? throw new LogicException('Partie non close.');

    match ($journey) {
        'multijoueur mené à son terme', 'solo mené à son terme' => expect($game->mode)
            ->toBe($journey === 'solo mené à son terme' ? GameMode::Solo : GameMode::Multiplayer)
            ->and($game->rounds_completed)->toBe($roundsCount)
            ->and($cancelled)->toHaveCount(0)
            ->and($endedAt->equalTo($lastPlayed?->reveal_ends_at))->toBeTrue(),
        'fin anticipée de la manche 1' => expect($rounds[0]->ended_at?->lessThan(
            ($rounds[0]->started_at ?? throw new LogicException('Manche 1 non programmée.'))->addMilliseconds($rounds[0]->duration_ms),
        ))->toBeTrue()
            ->and($game->rounds_completed)->toBe($roundsCount),
        'pause puis interruption' => expect($game->paused_at)->not->toBeNull()
            ->and($endedAt->equalTo($game->paused_at?->addMilliseconds(EngineConstants::pauseTimeoutMs())))->toBeTrue()
            ->and($game->rounds_completed)->toBe(1),
        'annulation remplacée par la réserve' => expect($cancelled)->toHaveCount(1)
            ->and($cancelled[0]->round_number)->toBe(2)
            ->and($cancelled[0]->cancel_reason)->toBe(RoundIncidentReason::NoVariantAvailable)
            ->and($rounds->firstWhere('sequence_index', $roundsCount + 1)?->status)->toBe(RoundStatus::Completed)
            ->and($game->rounds_completed)->toBe($roundsCount),
        'annulation sans manche restante' => expect($cancelled)->toHaveCount(1)
            ->and($cancelled[0]->round_number)->toBe($roundsCount)
            ->and($game->rounds_completed)->toBe($roundsCount - 1)
            ->and($endedAt->equalTo($lastPlayed?->reveal_ends_at))->toBeTrue(),
        default => throw new LogicException("Parcours inconnu : {$journey}."),
    };
}

/**
 * Le geste de la fin anticipée : dès l'ouverture du palier 1 de la manche 1,
 * le seul participant épuise ses tentatives une seconde plus tard, et le
 * crochet de fin de saisie est appelé comme son écouteur (L60-11)
 * l'appellera — la manche se clôt avant `D`.
 *
 * @return Closure(AdvanceRound|InterruptPausedGame): void
 */
function gamesInProgressEarlyEnd(Player $seat): Closure
{
    $done = false;

    return static function (AdvanceRound|InterruptPausedGame $job) use ($seat, &$done): void {
        if ($done || ! $job instanceof AdvanceRound || $job->step !== RoundStep::OpenTier || $job->tierIndex !== 1) {
            return;
        }

        $round = Round::query()->findOrFail($job->roundId);

        if ($round->sequence_index !== 1 || $round->status !== RoundStatus::Running) {
            return;
        }

        $done = true;
        $closedAt = Date::now()->toImmutable()->addSecond();

        RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->update([
            'input_state' => RoundPlayerInputState::AttemptsExhausted->value,
            'input_closed_at' => (new RoundPlayer)->fromDateTime($closedAt),
        ]);

        $participation = RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $seat->id)->firstOrFail();
        $written = $participation->input_closed_at ?? throw new LogicException('Saisie close sans instant.');
        Date::setTestNow($written);

        DB::transaction(static function () use ($round, $participation, $written): void {
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            app(SeatInputClosed::class)->handle($lockedRound, $participation, null, $written);
        });

        // Close avant `D`, à l'instant écrit.
        expect($round->refresh()->ended_at?->equalTo($written))->toBeTrue()
            ->and($written->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue();
    };
}

/**
 * Les jobs du moteur dispatchés pour la partie, dans l'ordre de dispatch.
 *
 * @return list<AdvanceRound|InterruptPausedGame>
 */
function gamesInProgressJobs(Game $game): array
{
    return [
        ...Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->gameId === $game->id)->values()->all(),
        ...Queue::pushed(InterruptPausedGame::class, static fn (InterruptPausedGame $job): bool => $job->gameId === $game->id)->values()->all(),
    ];
}

/** L'échéance d'un job du moteur : l'instant de son étape, ou celui de la clôture après pause. */
function gamesInProgressDueAt(AdvanceRound|InterruptPausedGame $job): CarbonImmutable
{
    return $job instanceof AdvanceRound ? $job->dueAtInstant() : $job->interruptsAt();
}

/**
 * Le worker de la file `game`, simulé. Tant que la partie est en cours, il
 * exige qu'au moins un job non encore exécuté l'attende sur la file `game`
 * (contrat C17 § 4.2), dépile le plus proche — échéance, puis ordre de
 * dispatch —, avance l'horloge à son échéance (jamais en arrière : un
 * doublon échu s'exécute en retard) et l'exécute comme le vrai worker.
 *
 * @param  (Closure(AdvanceRound|InterruptPausedGame): void)|null  $afterEach  Appelée après chaque job exécuté.
 * @return int le nombre de jobs exécutés
 */
function gamesInProgressWork(Game $game, ?Closure $afterEach = null): int
{
    /** @var array<int, true> $executed */
    $executed = [];

    while ($game->refresh()->ended_at === null) {
        $pending = array_values(array_filter(
            gamesInProgressJobs($game),
            static fn (AdvanceRound|InterruptPausedGame $job): bool => ! isset($executed[spl_object_id($job)]) && $job->queue === 'game',
        ));

        expect($pending)->not->toBeEmpty(sprintf(
            'Partie %s sans aucun job sur la file game après %d job(s) : elle ne finirait jamais.',
            $game->status->value,
            count($executed),
        ));

        // `usort` est stable : à échéance égale, l'ordre de dispatch.
        usort($pending, static fn (AdvanceRound|InterruptPausedGame $left, AdvanceRound|InterruptPausedGame $right): int => (int) gamesInProgressDueAt($left)->format('Uu') <=> (int) gamesInProgressDueAt($right)->format('Uu'));

        $job = $pending[0];
        $executed[spl_object_id($job)] = true;

        $dueAt = gamesInProgressDueAt($job);

        if ($dueAt->greaterThan(Date::now())) {
            Date::setTestNow($dueAt);
        }

        app()->call([$job, 'handle']);

        // Borne du test : quelques dizaines de jobs suffisent à trois manches.
        expect(count($executed))->toBeLessThan(200);

        if ($afterEach !== null) {
            $afterEach($job);
        }
    }

    return count($executed);
}

/**
 * Les lignes `game` qui violent l'invariant 1 du § 17.3 : `ended_at` nul avec
 * un statut terminal, ou posé avec un statut en cours.
 */
function gamesInProgressIncoherentRows(): int
{
    return Game::query()
        ->where(static function ($query): void {
            $query->whereNull('ended_at')->whereIn('status', [GameStatus::Completed->value, GameStatus::Interrupted->value]);
        })
        ->orWhere(static function ($query): void {
            $query->whereNotNull('ended_at')->whereIn('status', [GameStatus::Running->value, GameStatus::Paused->value]);
        })
        ->count();
}

/**
 * Une partie multijoueur au siège connecté, lancée, dont la manche 1 court à
 * son palier 1.
 *
 * @return array{Game, Round, Player}
 */
function gamesInProgressRunning(bool $solo = false): array
{
    $game = EngineFixtures::game(EngineFixtures::settings(), solo: $solo);
    $seat = EngineFixtures::seat($game);
    EngineFixtures::materialize($game);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::openTier($first, 1);

    return [$game->refresh(), $first->refresh(), $seat];
}

/**
 * Une partie dont la manche 1 s'est jouée sans personne de présent à sa fin
 * de révélation : en pause depuis `reveal_ends_at(1)`.
 */
function gamesInProgressPaused(): Game
{
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]);
    EngineFixtures::materialize($game);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::play($first);

    $game->refresh();

    expect($game->status)->toBe(GameStatus::Paused)
        ->and($game->paused_at?->equalTo($first->refresh()->reveal_ends_at))->toBeTrue();

    return $game;
}

/**
 * Compte les écritures SQL exécutées pendant `$callback`.
 */
function gamesInProgressWrites(Closure $callback): int
{
    $writes = 0;

    DB::listen(static function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes++;
        }
    });

    $before = $writes;
    $callback();

    return $writes - $before;
}

/**
 * Les jobs du moteur dispatchés pour la partie, réduits à ce qui les
 * distingue : étape (ou clôture après pause), palier, échéance, file.
 *
 * @return list<array{string, int|null, string, string|null}>
 */
function gamesInProgressJobShapes(Game $game, int $from = 0): array
{
    return array_values(array_map(
        static fn (AdvanceRound|InterruptPausedGame $job): array => $job instanceof AdvanceRound
            ? [$job->step->value, $job->tierIndex, $job->dueAt, $job->queue]
            : ['interrupt', null, WireTime::iso($job->interruptsAt()), $job->queue],
        array_slice(gamesInProgressJobs($game), $from),
    ));
}

test('une partie running ou paused est en cours, solo compris', function (): void {
    // Créées dans le désordre de leurs débuts : le résumé suit `startedAt`,
    // puis l'ordre de création à instant égal.
    $soloPaused = Game::factory()->solo()->paused()->create([
        'started_at' => $this->now->addSeconds(30),
        'paused_at' => $this->now->addSeconds(90),
    ]);
    $running = Game::factory()->create(['started_at' => $this->now->addSeconds(10), 'rounds_completed' => 2]);
    $soloRunning = Game::factory()->solo()->create(['started_at' => $this->now->addSeconds(10)]);
    $paused = Game::factory()->paused()->create([
        'started_at' => $this->now,
        'paused_at' => $this->now->addSeconds(60),
        'rounds_completed' => 1,
    ]);

    // Des sièges, pour prouver que rien d'eux ne sort du résumé.
    $seats = array_map(static fn (Game $game): Player => EngineFixtures::seat($game), [$soloPaused, $running, $soloRunning, $paused]);

    expect(GamesInProgress::count())->toBe(4)
        ->and(Game::query()->inProgress()->orderBy('id')->pluck('id')->all())
        ->toBe([$soloPaused->id, $running->id, $soloRunning->id, $paused->id]);

    $summary = GamesInProgress::summary();

    expect($summary)->toBe([
        [
            'mode' => GameMode::Multiplayer->value,
            'status' => GameStatus::Paused->value,
            'startedAt' => WireTime::iso($this->now),
            'roundsCompleted' => 1,
            'roundsCount' => $paused->rounds_count,
        ],
        [
            'mode' => GameMode::Multiplayer->value,
            'status' => GameStatus::Running->value,
            'startedAt' => WireTime::iso($this->now->addSeconds(10)),
            'roundsCompleted' => 2,
            'roundsCount' => $running->rounds_count,
        ],
        [
            'mode' => GameMode::Solo->value,
            'status' => GameStatus::Running->value,
            'startedAt' => WireTime::iso($this->now->addSeconds(10)),
            'roundsCompleted' => 0,
            'roundsCount' => $soloRunning->rounds_count,
        ],
        [
            'mode' => GameMode::Solo->value,
            'status' => GameStatus::Paused->value,
            'startedAt' => WireTime::iso($this->now->addSeconds(30)),
            'roundsCompleted' => 0,
            'roundsCount' => $soloPaused->rounds_count,
        ],
    ]);

    // Sans aucune donnée de joueur : ni pseudo, ni identifiant public de
    // siège, ni code de salon, ni référence de partie.
    $json = json_encode($summary, JSON_THROW_ON_ERROR);

    foreach ($seats as $seat) {
        expect($json)->not->toContain($seat->nickname)->not->toContain($seat->public_id);
    }

    foreach ([$running, $paused] as $game) {
        expect($json)->not->toContain((string) $game->room?->room_code)->not->toContain(GameRef::for($game));
    }
});

test('une partie completed ou interrupted n\'est pas en cours', function (): void {
    // Gelées par l'action de gel elle-même, multijoueur et solo.
    Game::factory()->finalized()->create();
    Game::factory()->finalized(GameStatus::Interrupted)->create();
    Game::factory()->solo()->finalized()->create();
    Game::factory()->solo()->finalized(GameStatus::Interrupted)->create();

    // Lignes incohérentes (invariant 1 violé : statut terminal sans
    // `ended_at`) : le statut seul les écarte.
    Game::factory()->create(['status' => GameStatus::Completed]);
    Game::factory()->solo()->create(['status' => GameStatus::Interrupted]);

    expect(GamesInProgress::count())->toBe(0)
        ->and(GamesInProgress::summary())->toBe([])
        ->and(Game::query()->inProgress()->exists())->toBeFalse();

    // Témoin : une partie en cours compte.
    $running = Game::factory()->create();

    expect(GamesInProgress::count())->toBe(1)
        ->and(Game::query()->inProgress()->pluck('id')->all())->toBe([$running->id]);
});

test('ended_at est non nul si et seulement si le statut est terminal', function (): void {
    $coherent = static function (): void {
        expect(gamesInProgressIncoherentRows())->toBe(0);
    };

    // Chaque chemin de fin, mené par le seul worker : `ended_at` reste nul
    // tant que la partie court, et n'est posé qu'avec un statut terminal.
    foreach (gamesInProgressJourneys() as $journey) {
        [$game, $outcome, $gesture] = gamesInProgressJourney($journey);

        $coherent();

        gamesInProgressWork($game, static function (AdvanceRound|InterruptPausedGame $job) use ($game, $gesture, $coherent): void {
            if ($gesture !== null) {
                $gesture($job);
            }

            $game->refresh();

            expect($game->ended_at !== null)->toBe(in_array($game->status, [GameStatus::Completed, GameStatus::Interrupted], true));
            $coherent();
        });

        expect($game->refresh()->status)->toBe($outcome, $journey)
            ->and($game->ended_at)->not->toBeNull();

        gamesInProgressAssertJourney($journey, $game);
    }

    // Les deux clôtures de game:reschedule. Une pause échue dont le job est
    // perdu : le rattrapage la gèle à `paused_at + pauseTimeoutMs`.
    $paused = gamesInProgressPaused();
    $pausedAt = $paused->paused_at ?? throw new LogicException('Partie en pause sans paused_at.');
    Date::setTestNow($pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())->addSecond());

    $this->artisan('game:reschedule')->assertSuccessful();

    expect($paused->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($paused->ended_at?->equalTo($pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())))->toBeTrue();
    $coherent();

    // Une partie bloquée : gelée à sa dernière activité connue.
    [$blocked] = gamesInProgressRunning();
    Date::setTestNow($blocked->started_at->addMilliseconds(GamesInProgress::maxNaturalDurationMs() + EngineConstants::pauseTimeoutMs())->addMillisecond());

    $this->artisan('game:reschedule')->assertSuccessful();

    expect($blocked->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($blocked->ended_at)->not->toBeNull();
    $coherent();

    // Lignes incohérentes (`ended_at` posé sur un statut en cours) : `ended_at`
    // seul les écarte du prédicat.
    Game::factory()->create(['ended_at' => Date::now()]);
    Game::factory()->solo()->paused()->create(['ended_at' => Date::now()]);

    expect(gamesInProgressIncoherentRows())->toBe(2)
        ->and(GamesInProgress::count())->toBe(0);
});

test('un salon au lobby sans partie ne compte pas comme partie en cours', function (): void {
    // Un salon au lobby, sièges assis : aucune partie.
    $lobby = Room::factory()->create();
    Player::factory()->count(3)->create(['room_id' => $lobby->id]);

    // Un salon revenu au lobby après une partie close, et un salon encore au
    // podium (`playing`, partie close jusqu'au « Rejouer ») : rien en cours.
    $replayed = Room::factory()->create();
    Game::factory()->forRoom($replayed)->finalized()->create();
    $podium = Room::factory()->playing()->create();
    Game::factory()->forRoom($podium)->finalized()->create();

    expect(GamesInProgress::count())->toBe(0)
        ->and(GamesInProgress::summary())->toBe([])
        ->and(Game::query()->inProgress()->exists())->toBeFalse();

    // game:reschedule ne dispatche rien pour un salon sans partie en cours.
    $this->artisan('game:reschedule')->assertSuccessful()->doesntExpectOutputToContain('multiplayer');

    Queue::assertNothingPushed();

    // Témoin : le lancement d'un salon crée une partie en cours, qui compte.
    $launched = Room::factory()->playing()->create();
    Game::factory()->forRoom($launched)->create();

    expect(GamesInProgress::count())->toBe(1);
});

test('toute partie en cours a au moins un job programmé sur la file game', function (string $journey): void {
    [$game, $outcome, $gesture] = gamesInProgressJourney($journey);

    expect($game->status)->toBe(GameStatus::Running)
        ->and(gamesInProgressJobs($game))->not->toBeEmpty();

    // Le worker exige un job en attente sur la file game avant CHAQUE job,
    // jusqu'au gel : de bout en bout, la partie ne vit que de ses jobs.
    $executed = gamesInProgressWork($game, $gesture);

    gamesInProgressAssertJourney($journey, $game);

    expect($game->refresh()->status)->toBe($outcome)
        ->and($game->ended_at)->not->toBeNull()
        ->and($executed)->toBeGreaterThan($game->rounds_count)
        // Tous sur la file `game`, jamais sur `default`.
        ->and(array_values(array_unique(array_map(static fn (AdvanceRound|InterruptPausedGame $job): ?string => $job->queue, gamesInProgressJobs($game)))))
        ->toBe(['game']);

    // Un job dispatché en retard pour une partie close ne fait rien.
    $last = gamesInProgressJobs($game)[array_key_last(gamesInProgressJobs($game))];
    $endedAt = $game->ended_at;

    expect(gamesInProgressWrites(static fn () => app()->call([$last, 'handle'])))->toBe(0)
        ->and($game->refresh()->ended_at?->equalTo($endedAt))->toBeTrue();
})->with(gamesInProgressJourneys());

test('la reprise d\'une partie en pause reste permise pendant un drainage', function (): void {
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class, SweepSeatPresence::class]);

    // Deux parties en pause, un siège déconnecté chacune : multijoueur, dont
    // le siège revient par la route du battement, et solo, par l'action
    // partagée du battement (la route `solo.heartbeat` arrive avec L60-16).
    $token = PlayerToken::mint(Locale::French);
    $game = EngineFixtures::game(EngineFixtures::settings());
    $seat = EngineFixtures::seat($game, [
        'player_token_hash' => $token->hash(),
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => Date::now(),
    ]);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::play($first);

    $solo = EngineFixtures::game(EngineFixtures::settings(), solo: true);
    $soloSeat = EngineFixtures::seat($solo, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()]);
    EngineFixtures::materialize($solo);
    $soloFirst = EngineFixtures::round($solo, 1);
    EngineFixtures::schedule($soloFirst, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));
    EngineFixtures::play($soloFirst);

    expect($game->refresh()->status)->toBe(GameStatus::Paused)
        ->and($solo->refresh()->status)->toBe(GameStatus::Paused);

    // Le drainage commence : il bloque les lancements, jamais une reprise.
    $drain = app(DeployDrain::class);
    $drain->start(DeployDrain::defaultTimeoutMinutes());

    expect($drain->isDraining())->toBeTrue()
        ->and(GamesInProgress::count())->toBe(2);

    $backAt = ($game->paused_at ?? throw new LogicException('Partie sans instant de pause.'))->addSeconds(3);
    Date::setTestNow($backAt);
    LobbyWrites::actAs($this, $token);
    $this->postJson(route('room.heartbeat', $game->room ?? throw new LogicException('Partie sans salon.')))->assertNoContent();

    Date::setTestNow($backAt->addSecond());
    app(RecordHeartbeat::class)->handle($soloSeat, null);

    $countdown = EngineConstants::launchCountdownMs();

    expect($game->refresh()->status)->toBe(GameStatus::Running)
        ->and($seat->refresh()->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(EngineFixtures::round($game, 2)->started_at?->equalTo($backAt->addMilliseconds($countdown)))->toBeTrue()
        ->and($solo->refresh()->status)->toBe(GameStatus::Running)
        ->and(EngineFixtures::round($solo, 2)->started_at?->equalTo($backAt->addSecond()->addMilliseconds($countdown)))->toBeTrue()
        // Le drapeau n'a pas bougé, et les deux parties restent en cours.
        ->and($drain->isDraining())->toBeTrue()
        ->and(GamesInProgress::count())->toBe(2);
});

test('game:reschedule redonne un job à une partie en cours qui n\'en a plus et reste idempotent', function (): void {
    // B, solo : lancée d'abord, palier 1 ouvert ; son palier 2 sera échu au
    // passage de la commande.
    [$solo, $soloRound] = gamesInProgressRunning(solo: true);

    // A, multijoueur : lancée 12 s plus tard, palier 1 ouvert ; son palier 2
    // ne sera pas échu.
    Date::setTestNow($this->now->addSeconds(12));
    [$running, $runningRound] = gamesInProgressRunning();

    // C : en pause, clôture non échue. D : close.
    $paused = Game::factory()->paused()->create(['started_at' => $this->now, 'paused_at' => $this->now->addSeconds(10)]);
    $ended = Game::factory()->finalized()->create();

    $runningT2 = EngineFixtures::opensAt($runningRound, 2);
    $soloT2 = EngineFixtures::opensAt($soloRound, 2);
    $soloT3 = EngineFixtures::opensAt($soloRound, 3);
    $at = $this->now->addSeconds(20);

    expect($soloT2->lessThan($at))->toBeTrue()
        ->and($runningT2->greaterThan($at))->toBeTrue();

    // Redis perdu : plus aucun job pour aucune partie.
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    Date::setTestNow($at);

    $summary = GamesInProgress::summary();

    $this->artisan('game:reschedule')
        ->expectsTable(array_keys($summary[0]), $summary)
        ->assertSuccessful();

    // A : le job de sa prochaine étape non échue, et rien d'écrit.
    expect(gamesInProgressJobShapes($running))->toBe([[RoundStep::OpenTier->value, 2, WireTime::iso($runningT2), 'game']])
        ->and(EngineFixtures::tier($runningRound, 2)->served_at)->toBeNull()
        // B : l'étape échue rattrapée à son instant théorique, puis le job de
        // la suivante — celui de la transition et celui de la commande, le même.
        ->and(EngineFixtures::tier($soloRound, 2)->served_at?->equalTo($soloT2))->toBeTrue()
        ->and(array_values(array_unique(gamesInProgressJobShapes($solo), SORT_REGULAR)))
        ->toBe([[RoundStep::OpenTier->value, 3, WireTime::iso($soloT3), 'game']])
        // C : sa clôture après pause, armée sur son `paused_at`.
        ->and(gamesInProgressJobShapes($paused))
        ->toBe([['interrupt', null, WireTime::iso($this->now->addSeconds(10)->addMilliseconds(EngineConstants::pauseTimeoutMs())), 'game']])
        ->and(Queue::pushed(InterruptPausedGame::class)->first()?->pausedAt)->toBe(WireTime::iso($this->now->addSeconds(10)))
        ->and($paused->refresh()->status)->toBe(GameStatus::Paused)
        // D : close, rien.
        ->and(gamesInProgressJobs($ended))->toBe([]);

    // Rejouée : aucune écriture, et le même job pour chacune — un doublon,
    // inoffensif.
    $pushed = [$running->id => count(gamesInProgressJobs($running)), $solo->id => count(gamesInProgressJobs($solo)), $paused->id => count(gamesInProgressJobs($paused))];

    expect(gamesInProgressWrites(fn () => $this->artisan('game:reschedule')->assertSuccessful()->run()))->toBe(0)
        ->and(gamesInProgressJobShapes($running, $pushed[$running->id]))->toBe([[RoundStep::OpenTier->value, 2, WireTime::iso($runningT2), 'game']])
        ->and(gamesInProgressJobShapes($solo, $pushed[$solo->id]))->toBe([[RoundStep::OpenTier->value, 3, WireTime::iso($soloT3), 'game']])
        ->and(gamesInProgressJobShapes($paused, $pushed[$paused->id]))
        ->toBe([['interrupt', null, WireTime::iso($this->now->addSeconds(10)->addMilliseconds(EngineConstants::pauseTimeoutMs())), 'game']])
        ->and(gamesInProgressJobs($ended))->toBe([]);

    // Et la partie repart : le worker mène chacune à son terme depuis ces
    // seuls jobs, doublons compris (le second exemplaire ne trouve rien
    // d'échu).
    gamesInProgressWork($running);
    gamesInProgressWork($solo);
    gamesInProgressWork($paused);

    expect($running->refresh()->status)->toBe(GameStatus::Completed)
        ->and(EngineFixtures::tier($runningRound, 2)->served_at?->equalTo($runningT2))->toBeTrue()
        ->and($solo->refresh()->status)->toBe(GameStatus::Completed)
        ->and($paused->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($paused->ended_at?->equalTo($this->now->addSeconds(10)->addMilliseconds(EngineConstants::pauseTimeoutMs())))->toBeTrue();
});

test('maxNaturalDurationMs se dérive des bornes sans littéral', function (): void {
    // Formule du § 17.3, point 3, réécrite depuis les sources nommées.
    $expected = static function (): int {
        $rounds = RoomSettingsBounds::MAX_ROUNDS_COUNT + PlatformLimits::drawSubstituteMargin();

        return $rounds * (RoomSettingsBounds::MAX_ROUND_DURATION + RoomSettingsBounds::MAX_REVEAL_DURATION) * 1000
            + $rounds * PlatformLimits::tierGraceMs()
            + (1 + PlatformLimits::drawSubstituteMargin()) * EngineConstants::launchCountdownMs();
    };

    expect(GamesInProgress::maxNaturalDurationMs())->toBe($expected());

    // Elle suit la marge de tirage et le décompte de lancement configurés.
    platformLimitsConfigure(['draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN + 4]);

    expect(GamesInProgress::maxNaturalDurationMs())->toBe($expected());

    engineConstantsConfigure(['launch_countdown_ms' => EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS + 1500]);

    expect(GamesInProgress::maxNaturalDurationMs())->toBe($expected())
        ->and(EngineConstants::launchCountdownMs())->toBe(EngineConstants::DEFAULT_LAUNCH_COUNTDOWN_MS + 1500);

    // Aucun littéral numérique dans le corps de la méthode : chaque terme
    // vient d'une borne ou d'une constante nommée.
    $tokens = token_get_all((string) file_get_contents(app_path('Support/Game/GamesInProgress.php')));
    $body = [];
    $depth = 0;
    $inMethod = false;

    foreach ($tokens as $index => $token) {
        if (! $inMethod) {
            $inMethod = is_array($token) && $token[0] === T_STRING && $token[1] === 'maxNaturalDurationMs'
                && is_array($tokens[$index - 2] ?? null) && $tokens[$index - 2][0] === T_FUNCTION;

            continue;
        }

        if ($token === '{') {
            $depth++;
        } elseif ($token === '}' && --$depth === 0) {
            break;
        }

        if ($depth > 0) {
            $body[] = $token;
        }
    }

    $literals = array_filter($body, static fn (mixed $token): bool => is_array($token) && in_array($token[0], [T_LNUMBER, T_DNUMBER], true));
    $source = implode('', array_map(static fn (mixed $token): string => is_array($token) ? $token[1] : $token, $body));

    expect($body)->not->toBeEmpty()
        ->and($literals)->toBe([])
        ->and($source)->toContain('RoomSettingsBounds::MAX_ROUNDS_COUNT')
        ->toContain('RoomSettingsBounds::MAX_ROUND_DURATION')
        ->toContain('RoomSettingsBounds::MAX_REVEAL_DURATION')
        ->toContain('PlatformLimits::drawSubstituteMargin()')
        ->toContain('PlatformLimits::tierGraceMs()')
        ->toContain('EngineConstants::launchCountdownMs()');
});

test('game:reschedule clôt à sa dernière activité connue une partie qui dépasse sa durée maximale', function (): void {
    Event::fake([GameFinalized::class]);
    $journal = GameJournalRecorder::start();

    try {
        // A : manche 1 en cours, jobs perdus avant sa clôture.
        [$running, $first, $seat] = gamesInProgressRunning();

        // B : en pause depuis la fin de sa manche 1, clôture perdue.
        Date::setTestNow($this->now);
        $paused = gamesInProgressPaused();
        $pausedAt = $paused->paused_at ?? throw new LogicException('Partie en pause sans paused_at.');
        $pausedDeadline = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

        // C : témoin à sa limite exacte — mêmes instants que A, mais une
        // pause achevée d'une milliseconde, que `total_paused_ms` ajoute à
        // sa limite.
        Date::setTestNow($this->now);
        [$witness] = gamesInProgressRunning();
        $witness->forceFill(['total_paused_ms' => 1])->save();

        // D : en pause au-delà de la durée naturelle (décomptes de reprise),
        // encore dans son délai de reprise au passage de la commande.
        $resumable = Game::factory()->paused()->create([
            'started_at' => $this->now,
            'paused_at' => $this->now->addMilliseconds(GamesInProgress::maxNaturalDurationMs())->addSecond(),
        ]);
        $resumablePausedAt = $resumable->paused_at ?? throw new LogicException('Partie en pause sans paused_at.');

        foreach ([$running, $paused, $witness, $resumable] as $game) {
            expect($game->started_at->equalTo($this->now))->toBeTrue();
        }

        // `now > started_at + maxNaturalDurationMs() + total_paused_ms +
        // pauseTimeoutMs` pour A, B et D ; égalité, donc pas bloquée, pour C.
        $at = $this->now->addMilliseconds(GamesInProgress::maxNaturalDurationMs() + EngineConstants::pauseTimeoutMs())->addMillisecond();
        Date::setTestNow($at);

        $runningLast = FinalizeGame::lastKnownActivity($running->refresh(), $at);
        $pausedLast = FinalizeGame::lastKnownActivity($paused->refresh(), $at);
        $durationEnd = EngineFixtures::durationEnd($first);

        // A : la fin théorique de sa manche en cours. B : sa dernière activité
        // serait sa pause, mais sa fin est déjà fixée à son échéance, passée ;
        // D : son échéance est à venir.
        expect($runningLast->equalTo($durationEnd))->toBeTrue()
            ->and($pausedLast->equalTo($pausedAt))->toBeTrue()
            ->and($pausedDeadline->lessThan($at))->toBeTrue()
            ->and($resumablePausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())->greaterThan($at))->toBeTrue();

        Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

        $this->artisan('game:reschedule')->assertSuccessful();

        $running->refresh();
        $first->refresh();

        // A, gelée sans rattrapage : manche 1 close d'office à `D`, jamais
        // révélée, manche 2 jamais programmée, aucun job.
        expect($running->status)->toBe(GameStatus::Interrupted)
            ->and($running->ended_at?->equalTo($durationEnd))->toBeTrue()
            ->and($running->rounds_completed)->toBe(1)
            ->and($first->status)->toBe(RoundStatus::Completed)
            ->and($first->ended_at?->equalTo($durationEnd))->toBeTrue()
            ->and($first->reveal_ends_at)->toBeNull()
            ->and(EngineFixtures::round($running, 2)->started_at)->toBeNull()
            ->and(gamesInProgressJobs($running))->toBe([])
            // B, jamais tenue pour bloquée : gelée par le rattrapage à son
            // échéance `paused_at + pauseTimeoutMs` (§ 14.3), comme par
            // `InterruptPausedGame` ou un battement tardif — jamais à
            // `paused_at`, quelle que soit l'heure de passage.
            ->and($paused->refresh()->status)->toBe(GameStatus::Interrupted)
            ->and($paused->ended_at?->equalTo($pausedDeadline))->toBeTrue()
            ->and(gamesInProgressJobs($paused))->toBe([])
            // D, jamais tenue pour bloquée : toujours en pause, reprise
            // permise, sa clôture réarmée sur son `paused_at`.
            ->and($resumable->refresh()->status)->toBe(GameStatus::Paused)
            ->and($resumable->ended_at)->toBeNull()
            ->and(gamesInProgressJobShapes($resumable))
            ->toBe([['interrupt', null, WireTime::iso($resumablePausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())), 'game']]);

        // C, à sa limite : rattrapée, jouée jusqu'à sa fin normale à la fin
        // de sa dernière révélation, jamais gelée à sa dernière activité.
        $lastRound = Round::query()->where('game_id', $witness->id)->where('round_number', $witness->rounds_count)->firstOrFail();

        expect($witness->refresh()->status)->toBe(GameStatus::Completed)
            ->and($witness->ended_at?->equalTo($lastRound->reveal_ends_at))->toBeTrue();

        Event::assertDispatched(GameFinalized::class, static fn (GameFinalized $event): bool => $event->gameId === $running->id && $event->outcome === GameStatus::Interrupted);
        Event::assertDispatched(GameFinalized::class, static fn (GameFinalized $event): bool => $event->gameId === $paused->id && $event->outcome === GameStatus::Interrupted);

        // Journal `game` : les deux gels, A à sa dernière activité, B à son
        // échéance, sans donnée de joueur ; rien pour D.
        $finalized = collect($journal->contexts(GameJournal::GAME_FINALIZED))
            ->filter(static fn (array $context): bool => in_array($context['gameRef'] ?? null, [GameRef::for($running), GameRef::for($paused), GameRef::for($resumable)], true))
            ->values()
            ->all();

        expect($finalized)->toBe([
            ['gameRef' => GameRef::for($running), 'mode' => GameMode::Multiplayer->value, 'outcome' => GameStatus::Interrupted->value, 'finalizedAt' => WireTime::iso($durationEnd)],
            ['gameRef' => GameRef::for($paused), 'mode' => GameMode::Multiplayer->value, 'outcome' => GameStatus::Interrupted->value, 'finalizedAt' => WireTime::iso($pausedDeadline)],
        ])
            ->and($journal->raw())->not->toContain($seat->nickname)->not->toContain($seat->public_id);

        // Rejouée : seule D reste en cours ; rien d'écrit, aucun second gel,
        // et le même job pour D — un doublon, inoffensif.
        $resumableJobs = count(gamesInProgressJobs($resumable));

        expect(gamesInProgressWrites(fn () => $this->artisan('game:reschedule')->assertSuccessful()->run()))->toBe(0)
            ->and(gamesInProgressJobShapes($resumable, $resumableJobs))
            ->toBe([['interrupt', null, WireTime::iso($resumablePausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs())), 'game']]);

        Event::assertDispatchedTimes(GameFinalized::class, 3);
    } finally {
        $journal->stop();
    }
});

test('game:reschedule rapporte une partie en échec, traite les suivantes et sort en code 1', function (): void {
    Exceptions::fake();

    // Journal incohérent : une partie bloquée dont la manche en cours aurait
    // commencé une seconde avant le passage — le gel refuse de clore une
    // manche dont la durée n'est pas écoulée (80 § 10.3).
    [$broken, $brokenRound] = gamesInProgressRunning();
    $at = $this->now->addMilliseconds(GamesInProgress::maxNaturalDurationMs() + EngineConstants::pauseTimeoutMs())->addMillisecond();
    $brokenRound->forceFill(['started_at' => $at->subSecond()])->save();

    // Une partie saine, lancée peu avant le passage, jobs perdus.
    Date::setTestNow($at->subSeconds(10));
    [$healthy, $healthyRound] = gamesInProgressRunning();

    expect($broken->id)->toBeLessThan($healthy->id);

    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    Date::setTestNow($at);

    $this->artisan('game:reschedule')->assertFailed();

    Exceptions::assertReported(LogicException::class);

    // La partie en échec est intacte (transaction annulée) ; la suivante a
    // reçu le job de sa prochaine étape.
    expect($broken->refresh()->status)->toBe(GameStatus::Running)
        ->and($broken->ended_at)->toBeNull()
        ->and($brokenRound->refresh()->status)->toBe(RoundStatus::Running)
        ->and(gamesInProgressJobs($broken))->toBe([])
        ->and(gamesInProgressJobShapes($healthy))
        ->toBe([[RoundStep::OpenTier->value, 2, WireTime::iso(EngineFixtures::opensAt($healthyRound, 2)), 'game']]);
});
