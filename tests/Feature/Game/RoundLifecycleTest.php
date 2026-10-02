<?php

use App\Actions\Game\AdvanceToNextRound;
use App\Actions\Game\CancelRound;
use App\Actions\Game\CatchUpGame;
use App\Actions\Game\CloseRound;
use App\Actions\Game\EndReveal;
use App\Actions\Game\FinalizeGame;
use App\Actions\Game\OpenTier;
use App\Actions\Game\RevealRound;
use App\Actions\Game\SeatInputClosed;
use App\Enums\ContentAvailability;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoundIncidentReason;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\GameFinalized;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Frame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Game\GameJournal;
use App\Support\Game\NextRoundOutcome;
use App\Support\Game\RoundStep;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WireTime;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Events\GateEvaluated;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Game\GameJournalRecorder;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Cycle de vie d'une manche — spec 60 § 3.2, § 4, § 5.3, § 5.4, § 6.3 et § 9 (lots L60-6, L60-7, L60-13)
|--------------------------------------------------------------------------
|
| Les transitions réelles, chacune appelée à son instant théorique, horloge
| figée à cet instant comme un job de frontière à l'heure : `OpenTier` à
| `Tᵢ`, `CloseRound` à `D`, `RevealRound` à `ended_at + tier_grace_ms`,
| `EndReveal` à `reveal_ends_at`. Les intitulés de L60-6 portent sur le
| cycle, la programmation de `k+1`, l'ordre à instant égal et
| `rounds_completed` ; ceux de L60-7 sur le job de frontière (réveil
| anticipé, échec), le rattrapage (`CatchUpGame` : ordre, diffusion de
| l'état courant, partie close ou en pause) et le journal `game` (retard
| réel des diffusions de frontière) ; ceux de L60-13 sur la « manche
| suivante », par la route de l'hôte (`room.round.next`) : raccourcir `R`
| sans descendre sous `preload_lead_ms` plus la marge, 409 hors révélation,
| refus à un non-hôte, hôte relu sous le verrou du salon.
|
| Parties matérialisées par l'action réelle (`EngineFixtures`), vraies
| variantes à fichier réel ; aucune valeur de jeu en littéral : durées,
| grâce et révélation relues sur la partie et ses paliers.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-26 14:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Les jobs de frontière poussés pour une manche, dans l'ordre : étape, palier,
 * instant théorique.
 *
 * @return list<array{string, int|null, string}>
 */
function lifecycleBoundaries(Round $round): array
{
    return Queue::pushed(AdvanceRound::class, static fn (AdvanceRound $job): bool => $job->roundId === $round->id)
        ->map(static fn (AdvanceRound $job): array => [$job->step->value, $job->tierIndex, $job->dueAt])
        ->values()
        ->all();
}

test('une manche passe de pending à running à T₁, puis ferme à D, révèle à ended_at + tier_grace_ms et se complète à reveal_ends_at', function (): void {
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    $present = EngineFixtures::seat($game);
    $away = EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => $this->now]);
    // Ni un siège parti, ni un siège expulsé, ni un retardataire admis à la
    // manche suivante ne naissent dans la manche 1 (E10-49).
    EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'disconnected_at' => $this->now, 'left_at' => $this->now], status: GamePlayerStatus::Left);
    EngineFixtures::seat($game, ['connection_state' => PlayerConnectionState::Left, 'left_at' => $this->now, 'kicked_at' => $this->now], status: GamePlayerStatus::Kicked);
    EngineFixtures::seat($game, firstRoundNumber: 2);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);

    $startsAt = $this->now->addSeconds(5);
    EngineFixtures::schedule($round, $startsAt);
    $recorder->sent = [];

    // Programmée, la manche attend son T₁ : une ouverture une milliseconde
    // trop tôt n'écrit rien.
    $t1 = EngineFixtures::opensAt($round, 1);

    expect(EngineFixtures::openTier($round, 1, $t1->subMillisecond())->served_at)->toBeNull()
        ->and($round->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(RoundPlayer::query()->where('round_id', $round->id)->count())->toBe(0);

    // T₁ : `running`, le palier 1 servi à son instant théorique, une ligne
    // `open` par siège non parti, déconnecté compris (E10-49).
    $first = EngineFixtures::openTier($round, 1);

    expect($round->refresh()->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at)->toBeNull()
        ->and($first->served_at?->equalTo($t1))->toBeTrue()
        ->and(RoundPlayer::query()->where('round_id', $round->id)->orderBy('player_id')->pluck('player_id')->all())
        ->toEqualCanonicalizing([$present->id, $away->id])
        ->and(RoundPlayer::query()->where('round_id', $round->id)->pluck('input_state')->unique()->all())
        ->toBe([RoundPlayerInputState::Open]);

    // Les paliers suivants à leur Tᵢ, dans l'ordre.
    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        $opened = EngineFixtures::openTier($round, $tierIndex);

        expect($opened->served_at?->equalTo(EngineFixtures::opensAt($round, $tierIndex)))->toBeTrue();
    }

    // D : `ended_at` à l'instant théorique, la manche reste `running`. La
    // clôture est demandée ici par un verrouillage reçu dans la grâce finale,
    // après `D` : l'instant écrit est toujours borné à `started_at + D`.
    $durationEnd = EngineFixtures::durationEnd($round);
    EngineFixtures::close($round, $durationEnd->addMilliseconds(intdiv($game->tier_grace_ms, 2)));

    $round->refresh();
    $revealStartsAt = $durationEnd->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);

    expect($round->status)->toBe(RoundStatus::Running)
        ->and($round->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();

    // Une clôture rejouée ne déplace rien.
    EngineFixtures::close($round, $durationEnd->addSeconds(3));

    expect($round->refresh()->ended_at?->equalTo($durationEnd))->toBeTrue();

    // La révélation attend `ended_at + tier_grace_ms`, jamais avant.
    EngineFixtures::reveal($round, $revealStartsAt->subMillisecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Running);

    EngineFixtures::reveal($round);

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    // La fin de révélation attend `reveal_ends_at`.
    EngineFixtures::endReveal($round, $revealEndsAt->subMillisecond());

    expect($round->refresh()->status)->toBe(RoundStatus::Revealing);

    EngineFixtures::endReveal($round);

    expect($round->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($round->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($round->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();

    // Un job par frontière, chacun à l'instant théorique de l'étape suivante.
    $expected = [];

    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        $expected[] = [RoundStep::OpenTier->value, $tierIndex, WireTime::iso(EngineFixtures::opensAt($round, $tierIndex))];
    }

    $expected[] = [RoundStep::Close->value, null, WireTime::iso($durationEnd)];
    $expected[] = [RoundStep::Reveal->value, null, WireTime::iso($revealStartsAt)];
    $expected[] = [RoundStep::EndReveal->value, null, WireTime::iso($revealEndsAt)];

    expect(array_slice(lifecycleBoundaries($round), 1))->toBe($expected);

    // Sur le fil, une diffusion par étape de la manche.
    $ownEvents = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => ($sent['payload']['sequenceIndex'] ?? $sent['payload']['round']['sequenceIndex'] ?? null) === 1,
    ));

    expect(array_column($ownEvents, 'event'))->toBe([
        ...array_fill(0, $game->frames_per_round, 'tier.opened'),
        'round.closed',
        'round.revealed',
    ]);
});

test('la manche k+1 est programmée à reveal_ends_at(k) dès le début de la révélation de k', function (): void {
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);

    EngineFixtures::schedule($first, $this->now->addSeconds(5));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);

    // Close, la manche k ne programme encore rien.
    expect($second->refresh()->started_at)->toBeNull();

    $recorder->sent = [];

    // La révélation s'exécute EN RETARD : les instants écrits restent les
    // instants théoriques (règle 1, § 1.1) — `T₁(k+1)` et le job de fin de
    // révélation à `reveal_ends_at(k)`, jamais à l'heure d'exécution + `R`.
    $endedAt = $first->refresh()->ended_at ?? throw new LogicException('Manche 1 non close.');
    EngineFixtures::reveal($first, $endedAt->addMilliseconds($game->tier_grace_ms + 2_700));

    $revealEndsAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $second->refresh();

    // Dès le début de la révélation : T₁(k+1) = reveal_ends_at(k), sans
    // intervalle, palier 1 frappé, `OpenTier(1)` programmé à cet instant.
    expect($first->status)->toBe(RoundStatus::Revealing)
        ->and($second->status)->toBe(RoundStatus::Pending)
        ->and($second->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(EngineFixtures::tier($second, 1)->serve_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and(EngineFixtures::tier($second, 2)->serve_token)->toBeNull()
        ->and(lifecycleBoundaries($second))->toBe([[RoundStep::OpenTier->value, 1, WireTime::iso($revealEndsAt)]])
        ->and(lifecycleBoundaries($first))->toContain([RoundStep::EndReveal->value, null, WireTime::iso($revealEndsAt)]);

    // Sur le fil : les titres de k, PUIS la programmation de k+1, dont le
    // palier 1 devient servable dans les `preload_lead_ms` finales de R.
    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed', 'round.scheduled'])
        ->and($recorder->sent[1]['payload']['round'])->toMatchArray([
            'sequenceIndex' => 2,
            'roundNumber' => 2,
            'startsAt' => WireTime::iso($revealEndsAt),
        ])
        ->and($recorder->sent[1]['payload']['image']['fetchNotBefore'])
        ->toBe(WireTime::iso($revealEndsAt->subMilliseconds($game->preload_lead_ms)));

    // La fin de révélation ne reprogramme rien : k+1 l'est déjà.
    EngineFixtures::endReveal($first);

    expect($second->refresh()->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(lifecycleBoundaries($second))->toHaveCount(1);

    // La dernière manche ne programme rien : aucune manche ne reste à jouer.
    foreach (range(2, $game->rounds_count) as $sequenceIndex) {
        $round = EngineFixtures::round($game, $sequenceIndex);

        if ($sequenceIndex > 2) {
            expect($round->started_at?->equalTo(EngineFixtures::round($game, $sequenceIndex - 1)->reveal_ends_at))->toBeTrue();
        }

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($round, $tierIndex);
        }

        EngineFixtures::close($round);
        $recorder->sent = [];
        EngineFixtures::reveal($round);

        if ($sequenceIndex < $game->rounds_count) {
            EngineFixtures::endReveal($round);
        }
    }

    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed'])
        ->and(Round::query()->where('game_id', $game->id)->whereNull('started_at')->count())->toBe(0);
});

test('la fin de révélation précède l\'ouverture du palier 1 suivant à instant égal', function (): void {
    Event::fake([GameFinalized::class]);

    // Deux parties jouées jusqu'à la fin de révélation de la manche 1 : dans
    // la première, plus aucun siège n'est connecté ; dans la seconde, un
    // siège reste présent.
    $scenarios = [];

    foreach (['absent' => false, 'présent' => true] as $label => $connected) {
        $game = EngineFixtures::game(EngineFixtures::settings());
        $seat = EngineFixtures::seat($game);
        EngineFixtures::materialize($game);
        $first = EngineFixtures::round($game, 1);

        EngineFixtures::schedule($first, $this->now->addSeconds(5));

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($first, $tierIndex);
        }

        EngineFixtures::close($first);
        EngineFixtures::reveal($first);

        if (! $connected) {
            $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
        }

        $scenarios[$label] = [$game, $first, EngineFixtures::round($game, 2)];
    }

    foreach ($scenarios as $label => [$game, $first, $second]) {
        $instant = $first->refresh()->reveal_ends_at;

        // Les deux étapes tombent au même instant.
        expect($instant)->not->toBeNull()
            ->and($second->refresh()->started_at?->equalTo($instant))->toBeTrue($label);

        // L'ouverture est appelée la PREMIÈRE, à cet instant : tant que k est
        // en révélation, elle attend, sans rien écrire ni se périmer.
        $opened = EngineFixtures::openTier($second, 1, $instant);

        expect($opened->served_at)->toBeNull($label)
            ->and($second->refresh()->status)->toBe(RoundStatus::Pending, $label)
            ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0, $label)
            ->and(EngineFixtures::tier($second, 2)->serve_token)->toBeNull($label);

        // La fin de révélation de k passe, au même instant…
        EngineFixtures::endReveal($first, $instant);

        expect($first->refresh()->status)->toBe(RoundStatus::Completed, $label);

        // …puis l'ouverture rejouée voit l'état qu'elle a laissé.
        $opened = EngineFixtures::openTier($second, 1, $instant);
        $game->refresh();
        $second->refresh();

        if ($label === 'absent') {
            // Pause : k+1 est déprogrammée avant d'avoir pu s'ouvrir.
            expect($game->status)->toBe(GameStatus::Paused)
                ->and($second->status)->toBe(RoundStatus::Pending)
                ->and($second->started_at)->toBeNull()
                ->and($opened->served_at)->toBeNull()
                ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0);

            continue;
        }

        expect($game->status)->toBe(GameStatus::Running)
            ->and($second->status)->toBe(RoundStatus::Running)
            ->and($opened->served_at?->equalTo($instant))->toBeTrue()
            ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(1);
    }
});

test('rounds_completed compte les manches completed à chaque fin de révélation', function (): void {
    Event::fake([GameFinalized::class]);

    $game = EngineFixtures::game(EngineFixtures::settings(roundsCount: 4));
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game, reserve: 1);

    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $this->now->addSeconds(5));
    EngineFixtures::play($first);

    expect($game->refresh()->rounds_completed)->toBe(1)
        ->and($game->status)->toBe(GameStatus::Running);

    // La manche 2, programmée, est annulée avant son T₁ : son remplaçant de
    // réserve prend son numéro. Une manche annulée n'est jamais comptée.
    $second = EngineFixtures::round($game, 2);
    $startsAt = $second->refresh()->started_at ?? throw new LogicException('Manche 2 non programmée.');

    DB::transaction(static fn () => app(CancelRound::class)->handle($second, RoundIncidentReason::FrameUnavailable, $startsAt->subSecond()));

    expect($game->refresh()->rounds_completed)->toBe(1);

    $replacement = Round::query()->where('game_id', $game->id)->where('round_number', 2)->where('status', RoundStatus::Pending->value)->firstOrFail();
    EngineFixtures::play($replacement);

    expect($game->refresh()->rounds_completed)->toBe(2);

    // Tant qu'une révélation court, le compteur ne la compte pas.
    $third = EngineFixtures::round($game, 3);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($third, $tierIndex);
    }

    EngineFixtures::close($third);
    EngineFixtures::reveal($third);

    expect($game->refresh()->rounds_completed)->toBe(2);

    EngineFixtures::endReveal($third);

    expect($game->refresh()->rounds_completed)->toBe(3);

    // Dernière manche : le gel écrase le compteur par le même décompte, et
    // fige la partie à la fin de la dernière révélation (§ 14.5) — à
    // l'instant théorique `reveal_ends_at`, même quand le job de fin de
    // révélation s'exécute en retard (règle 1, § 1.1 ; § 9.6 étape 2).
    $last = EngineFixtures::round($game, 4);

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($last, $tierIndex);
    }

    EngineFixtures::close($last);
    EngineFixtures::reveal($last);

    $lastRevealEndsAt = $last->refresh()->reveal_ends_at ?? throw new LogicException('Dernière manche sans fin de révélation.');
    EngineFixtures::endReveal($last, $lastRevealEndsAt->addMilliseconds(2_700));

    $game->refresh();

    expect($last->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($game->status)->toBe(GameStatus::Completed)
        ->and($game->rounds_completed)->toBe(4)
        ->and($game->rounds_completed)->toBe(Round::query()->where('game_id', $game->id)->completed()->count())
        ->and($game->ended_at?->equalTo($lastRevealEndsAt))->toBeTrue();

    Event::assertDispatched(GameFinalized::class, 1);
});

/**
 * Les écritures SQL (`insert`, `update`, `delete`) exécutées par `$step`.
 *
 * @return list<string>
 */
function lifecycleWritesDuring(Closure $step): array
{
    $writes = [];
    $recording = true;

    DB::listen(static function (QueryExecuted $query) use (&$writes, &$recording): void {
        if ($recording && preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    try {
        $step();
    } finally {
        $recording = false;
    }

    return $writes;
}

/**
 * Les étapes écrites par `$step`, dans l'ordre, lues au fil des requêtes :
 * `open:s:i` (`served_at` du palier `i` de la manche `s`), `status:s:valeur`,
 * `close:s` (`ended_at`), `schedule:s` (`started_at`).
 *
 * @return list<string>
 */
function lifecycleStepTrace(Game $game, Closure $step): array
{
    $rounds = Round::query()->where('game_id', $game->id)->pluck('sequence_index', 'id')->all();
    $tiers = RoundTier::query()
        ->whereIn('round_id', array_keys($rounds))
        ->get(['id', 'round_id', 'tier_index'])
        ->mapWithKeys(static fn (RoundTier $tier): array => [$tier->id => $rounds[$tier->round_id].':'.$tier->tier_index])
        ->all();

    $trace = [];
    $recording = true;

    DB::listen(static function (QueryExecuted $query) use (&$trace, &$recording, $rounds, $tiers): void {
        if (! $recording) {
            return;
        }

        $sql = strtolower($query->sql);
        $id = $query->bindings === [] ? null : $query->bindings[array_key_last($query->bindings)];

        if (str_starts_with($sql, 'update "round_tier" set "served_at"')) {
            $trace[] = 'open:'.($tiers[$id] ?? '?');
        } elseif (str_starts_with($sql, 'update "round" set "status"')) {
            $trace[] = 'status:'.($rounds[$id] ?? '?').':'.$query->bindings[0];
        } elseif (str_starts_with($sql, 'update "round" set "ended_at"')) {
            $trace[] = 'close:'.($rounds[$id] ?? '?');
        } elseif (str_starts_with($sql, 'update "round" set "started_at"')) {
            $trace[] = 'schedule:'.($rounds[$id] ?? '?');
        }
    });

    try {
        $step();
    } finally {
        $recording = false;
    }

    return $trace;
}

test('un job réveillé une seconde trop tôt attend en processus et ne se relâche jamais', function (): void {
    Sleep::fake(syncWithCarbon: true);

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, $this->now->addSeconds(5));

    $t1 = EngineFixtures::opensAt($round, 1);
    $job = new AdvanceRound($game->id, $round->id, RoundStep::OpenTier, 1, WireTime::iso($t1));

    // `availableAt()` tronque le délai à la seconde : le worker réveille le
    // job une seconde avant son instant.
    Date::setTestNow($t1->subMilliseconds(EngineConstants::JOB_DELAY_RESOLUTION_MS));
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    // Il attend EN PROCESSUS, une seule fois, le temps restant — jamais plus
    // que `transitionMaxWaitMs` —, puis rattrape : le palier s'ouvre à son
    // instant théorique. Jamais relâché, jamais échoué.
    Sleep::assertSequence([Sleep::for(EngineConstants::JOB_DELAY_RESOLUTION_MS)->milliseconds()]);
    $job->assertNotReleased();
    $job->assertNotFailed();

    expect($round->refresh()->status)->toBe(RoundStatus::Running)
        ->and(EngineFixtures::tier($round, 1)->served_at?->equalTo($t1))->toBeTrue();

    // Réveillé plus tôt encore (horloges désaccordées) : l'étape ne peut pas
    // être échue dans l'attente permise. Un job NEUF pour le même instant,
    // jamais un relâchement, et rien d'exécuté.
    Sleep::fake(syncWithCarbon: true);
    $t2 = EngineFixtures::opensAt($round, 2);
    $early = new AdvanceRound($game->id, $round->id, RoundStep::OpenTier, 2, WireTime::iso($t2));
    $pushed = Queue::pushed(AdvanceRound::class)->count();

    Date::setTestNow($t2->subMilliseconds(EngineConstants::transitionMaxWaitMs() + intdiv(EngineConstants::JOB_DELAY_RESOLUTION_MS, 2)));
    $early->withFakeQueueInteractions();
    app()->call([$early, 'handle']);

    $redispatched = Queue::pushed(AdvanceRound::class)->last();

    $early->assertNotReleased();
    $early->assertNotFailed();
    Sleep::assertNeverSlept();

    expect(Queue::pushed(AdvanceRound::class))->toHaveCount($pushed + 1)
        ->and($redispatched)->toBeInstanceOf(AdvanceRound::class)
        ->and($redispatched?->step)->toBe(RoundStep::OpenTier)
        ->and($redispatched?->tierIndex)->toBe(2)
        ->and($redispatched?->dueAt)->toBe(WireTime::iso($t2))
        ->and($redispatched?->retried)->toBeFalse()
        ->and($redispatched?->queue)->toBe('game')
        ->and(EngineFixtures::tier($round, 2)->served_at)->toBeNull();

    // Sur une file synchrone, qui ignore tout délai, le job neuf s'exécuterait
    // aussitôt, sans fin : il n'est pas dispatché ; l'étape reste due.
    $sync = new AdvanceRound($game->id, $round->id, RoundStep::OpenTier, 2, WireTime::iso($t2));
    $sync->setJob(new SyncJob(app(), '{}', 'sync', 'game'));
    $pushed = Queue::pushed(AdvanceRound::class)->count();

    app()->call([$sync, 'handle']);

    expect(Queue::pushed(AdvanceRound::class))->toHaveCount($pushed)
        ->and(EngineFixtures::tier($round, 2)->served_at)->toBeNull();
});

test('un rattrapage tardif applique toutes les étapes échues dans l\'ordre et n\'émet que l\'état courant', function (): void {
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);

    EngineFixtures::schedule($first, $this->now->addSeconds(5));
    $recorder->sent = [];

    // Aucun job ne s'est exécuté : le rattrapage arrive pendant le palier 2
    // de la manche 2, une manche et demie en retard.
    $startedAt = $first->refresh()->started_at ?? throw new LogicException('Manche 1 non programmée.');
    $durationEnd = $startedAt->addMilliseconds($first->duration_ms);
    $revealStartsAt = $durationEnd->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $revealStartsAt->addSeconds($game->settings_snapshot->revealDuration);
    $secondT2 = $revealEndsAt->addMilliseconds(EngineFixtures::tier($second, 2)->starts_at_offset_ms);
    $late = $secondT2->addMilliseconds(1_234);

    Date::setTestNow($late);

    $trace = lifecycleStepTrace($game, fn () => app(CatchUpGame::class)->handle($game, $late));

    // Toutes les étapes échues, dans l'ordre chronologique — la fin de
    // révélation de la manche 1 avant l'ouverture de la manche 2, à instant
    // égal —, chacune à son instant théorique.
    $expected = ['status:1:running'];

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        $expected[] = "open:1:{$tierIndex}";
    }

    array_push($expected, 'close:1', 'status:1:revealing', 'schedule:2', 'status:1:completed', 'status:2:running', 'open:2:1', 'open:2:2');

    expect($trace)->toBe($expected);

    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe(RoundStatus::Completed)
        ->and($first->ended_at?->equalTo($durationEnd))->toBeTrue()
        ->and($first->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and($second->status)->toBe(RoundStatus::Running)
        ->and($second->started_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and($game->refresh()->rounds_completed)->toBe(1)
        ->and(EngineFixtures::tier($second, 2)->served_at?->equalTo($secondT2))->toBeTrue()
        ->and(EngineFixtures::tier($second, 3)->served_at)->toBeNull()
        ->and(EngineFixtures::tier($second, 3)->serve_token)->toMatch('/^[0-9a-f]{32}$/');

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        expect(EngineFixtures::tier($first, $tierIndex)->served_at?->equalTo(EngineFixtures::opensAt($first, $tierIndex)))->toBeTrue();
    }

    // Sur le fil, l'état courant seulement : la dernière étape de la
    // manche 1 (ses titres et son classement), et le palier courant de la
    // manche 2 — ni les ouvertures, ni la clôture, ni la programmation de la
    // manche 2, que son ouverture a rendue périmée.
    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed', 'tier.opened'])
        ->and($recorder->sent[0]['payload']['sequenceIndex'])->toBe(1)
        ->and($recorder->sent[1]['payload'])->toMatchArray(['sequenceIndex' => 2, 'tierIndex' => 2, 'opensAt' => WireTime::iso($secondT2)])
        ->and($recorder->sent[1]['payload']['next']['tierIndex'])->toBe(3);

    // Un second passage au même instant n'a plus rien à faire.
    $recorder->sent = [];

    expect(lifecycleWritesDuring(fn () => app(CatchUpGame::class)->handle($game, $late)))->toBe([])
        ->and($recorder->sent)->toBe([]);

    // Un passage à l'heure n'exécute qu'une étape, et en émet l'événement.
    $secondT3 = EngineFixtures::opensAt($second, 3);
    Date::setTestNow($secondT3);
    app(CatchUpGame::class)->handle($game, $secondT3);

    expect(array_column($recorder->sent, 'event'))->toBe(['tier.opened'])
        ->and($recorder->sent[0]['payload']['tierIndex'])->toBe(3);

    // La clôture à `D`, à l'heure : son seul événement.
    $recorder->sent = [];
    $secondEnd = EngineFixtures::durationEnd($second);
    Date::setTestNow($secondEnd);
    app(CatchUpGame::class)->handle($game, $secondEnd);

    expect(array_column($recorder->sent, 'event'))->toBe(['round.closed']);

    // La révélation, à l'heure — le chemin de production de `round.scheduled`
    // de la manche suivante, qui n'est jamais émis que dans un passage : le
    // passage libère `round.revealed` ET l'annonce de la manche qu'il vient
    // de programmer, qu'il n'a pas ouverte.
    $recorder->sent = [];
    $secondRevealAt = $secondEnd->addMilliseconds($game->tier_grace_ms);
    Date::setTestNow($secondRevealAt);
    app(CatchUpGame::class)->handle($game, $secondRevealAt);

    $secondRevealEndsAt = $second->refresh()->reveal_ends_at ?? throw new LogicException('Manche 2 sans fin de révélation.');

    expect(array_column($recorder->sent, 'event'))->toBe(['round.revealed', 'round.scheduled'])
        ->and($recorder->sent[0]['payload']['sequenceIndex'])->toBe(2)
        ->and($recorder->sent[1]['payload']['round']['sequenceIndex'])->toBe(3)
        ->and($recorder->sent[1]['payload']['round']['startsAt'])->toBe(WireTime::iso($secondRevealEndsAt))
        ->and($recorder->sent[1]['payload']['serverNow'] < $recorder->sent[1]['payload']['round']['startsAt'])->toBeTrue();
});

test('un rattrapage qui met la partie en pause ne libère pas l\'annonce de la manche qu\'il déprogramme', function (): void {
    // Ajout (§ 4.4, § 14.1) : un passage tardif exécute `RevealRound(1)`, qui
    // programme la manche 2 et l'annonce, puis `EndReveal(1)`, qui met la
    // partie en pause faute de siège présent et déprogramme la manche 2
    // (`started_at` nul). L'annonce ne décrit plus l'état courant, et
    // partirait après son `T₁` : seule la pause part.
    $recorder = RecordingBroadcaster::install();

    $game = EngineFixtures::game(EngineFixtures::settings());
    $seat = EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    $second = EngineFixtures::round($game, 2);

    EngineFixtures::schedule($first, $this->now->addSeconds(5));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();

    $endedAt = $first->refresh()->ended_at ?? throw new LogicException('Manche 1 non close.');
    $revealEndsAt = $endedAt
        ->addMilliseconds($game->tier_grace_ms)
        ->addSeconds($game->settings_snapshot->revealDuration);
    $late = $revealEndsAt->addSecond();

    Date::setTestNow($late);
    $recorder->sent = [];

    app(CatchUpGame::class)->handle($game, $late);

    expect($game->refresh()->status)->toBe(GameStatus::Paused)
        ->and($game->paused_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and($second->refresh()->started_at)->toBeNull()
        ->and(array_column($recorder->sent, 'event'))->toBe(['round.revealed', 'game.paused'])
        ->and($recorder->sent[0]['payload']['sequenceIndex'])->toBe(1);

    foreach ($recorder->sent as $sent) {
        if ($sent['event'] === 'round.scheduled') {
            expect($sent['payload']['serverNow'] < $sent['payload']['round']['startsAt'])->toBeTrue();
        }
    }
});

test('une étape d\'une partie close ou en pause n\'écrit rien', function (): void {
    Event::fake([GameFinalized::class]);

    // En pause : plus aucun siège présent en fin de révélation de la
    // manche 1 ; la manche 2, programmée, est déprogrammée.
    $paused = EngineFixtures::game(EngineFixtures::settings());
    $seat = EngineFixtures::seat($paused);
    EngineFixtures::materialize($paused);
    $first = EngineFixtures::round($paused, 1);
    $second = EngineFixtures::round($paused, 2);

    EngineFixtures::schedule($first, $this->now->addSeconds(5));

    foreach (range(1, $paused->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    $plannedT1 = $second->refresh()->started_at ?? throw new LogicException('Manche 2 non programmée.');
    $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
    EngineFixtures::endReveal($first);

    expect($paused->refresh()->status)->toBe(GameStatus::Paused)
        ->and($second->refresh()->started_at)->toBeNull();

    // Le job de l'ouverture prévue se réveille à son instant, le rattrapage
    // passe, l'ouverture est rappelée : rien n'est écrit.
    $staleOpen = new AdvanceRound($paused->id, $second->id, RoundStep::OpenTier, 1, WireTime::iso($plannedT1));
    $staleOpen->withFakeQueueInteractions();
    Date::setTestNow($plannedT1);

    expect(lifecycleWritesDuring(static function () use ($staleOpen, $paused, $second, $plannedT1): void {
        app()->call([$staleOpen, 'handle']);
        app(CatchUpGame::class)->handle($paused, $plannedT1);
        app(OpenTier::class)->handle(EngineFixtures::tier($second, 1), $plannedT1);
    }))->toBe([]);

    $staleOpen->assertNotReleased();

    expect($second->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(EngineFixtures::tier($second, 1)->served_at)->toBeNull()
        ->and(RoundPlayer::query()->where('round_id', $second->id)->count())->toBe(0);

    // La seule étape d'une partie en pause est sa clôture à l'échéance —
    // jamais avant, et à l'instant prévu même rattrapée en retard.
    $pausedAt = $paused->refresh()->paused_at ?? throw new LogicException('Partie non en pause.');
    $interruptsAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

    expect(lifecycleWritesDuring(fn () => app(CatchUpGame::class)->handle($paused, $interruptsAt->subMillisecond())))->toBe([]);

    Date::setTestNow($interruptsAt->addMilliseconds(2_700));
    app(CatchUpGame::class)->handle($paused, $interruptsAt->addMilliseconds(2_700));

    expect($paused->refresh()->status)->toBe(GameStatus::Interrupted)
        ->and($paused->ended_at?->equalTo($interruptsAt))->toBeTrue();

    // Close : la manche 2, programmée pendant la révélation de la manche 1,
    // reste `pending` au gel (C13 § 4.5) ; ses étapes ne s'ouvrent jamais.
    $closed = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($closed);
    EngineFixtures::materialize($closed);
    $revealed = EngineFixtures::round($closed, 1);
    $scheduled = EngineFixtures::round($closed, 2);

    EngineFixtures::schedule($revealed, Date::now()->toImmutable()->addSeconds(5));

    foreach (range(1, $closed->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($revealed, $tierIndex);
    }

    EngineFixtures::close($revealed);
    EngineFixtures::reveal($revealed);

    $revealEndsAt = $revealed->refresh()->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    app(FinalizeGame::class)->handle($closed, GameStatus::Interrupted, Date::now()->toImmutable());

    expect($closed->refresh()->ended_at)->not->toBeNull()
        ->and($scheduled->refresh()->status)->toBe(RoundStatus::Pending)
        ->and($scheduled->started_at?->equalTo($revealEndsAt))->toBeTrue();

    $jobs = [
        new AdvanceRound($closed->id, $revealed->id, RoundStep::EndReveal, null, WireTime::iso($revealEndsAt)),
        new AdvanceRound($closed->id, $scheduled->id, RoundStep::OpenTier, 1, WireTime::iso($revealEndsAt)),
    ];

    Date::setTestNow($revealEndsAt);

    expect(lifecycleWritesDuring(static function () use ($jobs, $closed, $revealed, $scheduled, $revealEndsAt): void {
        foreach ($jobs as $job) {
            $job->withFakeQueueInteractions();
            app()->call([$job, 'handle']);
        }

        app(CatchUpGame::class)->handle($closed, $revealEndsAt->addMinutes(5));
        app(OpenTier::class)->handle(EngineFixtures::tier($scheduled, 1), $revealEndsAt);
        app(CloseRound::class)->handle($revealed, $revealEndsAt);
        app(RevealRound::class)->handle($revealed, $revealEndsAt);
        app(EndReveal::class)->handle($revealed, $revealEndsAt);
    }))->toBe([]);

    expect($scheduled->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(EngineFixtures::tier($scheduled, 1)->served_at)->toBeNull()
        ->and(RoundPlayer::query()->where('round_id', $scheduled->id)->count())->toBe(0);
});

test('journalise sur le canal game le retard réel de chaque diffusion de frontière', function (): void {
    $journal = GameJournalRecorder::start();

    try {
        $recorder = RecordingBroadcaster::install();

        $game = EngineFixtures::game(EngineFixtures::settings());
        $seat = EngineFixtures::seat($game);
        EngineFixtures::materialize($game);
        $first = EngineFixtures::round($game, 1);
        $second = EngineFixtures::round($game, 2);

        // Programmation par un geste (le lancement) : mesurée contre
        // l'instant de sa transaction.
        EngineFixtures::schedule($first, $this->now->addSeconds(5));

        // Chaque ouverture, plus ou moins en retard sur son `Tᵢ`.
        $expected = [['round.scheduled', 1, null, 0]];

        foreach (range(1, $game->frames_per_round) as $tierIndex) {
            $lateness = 100 * $tierIndex + 17;
            EngineFixtures::openTier($first, $tierIndex, EngineFixtures::opensAt($first, $tierIndex)->addMilliseconds($lateness));
            $expected[] = ['tier.opened', 1, $tierIndex, $lateness];
        }

        // La clôture à `D`, 450 ms en retard.
        $durationEnd = EngineFixtures::durationEnd($first);
        Date::setTestNow($durationEnd->addMilliseconds(450));
        app(CloseRound::class)->handle($first, $durationEnd);
        $expected[] = ['round.closed', 1, null, 450];

        // La révélation, 2,7 s en retard : les titres, et la programmation de
        // la manche 2 qu'elle décide, contre `ended_at + tier_grace_ms`.
        EngineFixtures::reveal($first, $durationEnd->addMilliseconds($game->tier_grace_ms + 2_700));
        array_push($expected, ['round.revealed', 1, null, 2_700], ['round.scheduled', 2, null, 2_700]);

        // Manche 2 : ouverte à l'heure, puis close d'anticipation par un
        // écouteur 900 ms en retard — mesurée contre l'instant de clôture
        // écrit.
        EngineFixtures::endReveal($first);
        EngineFixtures::openTier($second, 1);
        $expected[] = ['tier.opened', 2, 1, 0];

        $closedAt = EngineFixtures::opensAt($second, 1)->addMilliseconds(3_000);
        $guess = Guess::factory()->forRound($second, $seat)->atTier(1, $game->settings_snapshot)->create(['received_at' => $closedAt]);
        RoundPlayer::query()->where('round_id', $second->id)->where('player_id', $seat->id)->update([
            'input_state' => RoundPlayerInputState::Locked->value,
            'input_closed_at' => (new RoundPlayer)->fromDateTime($closedAt),
        ]);
        $participation = RoundPlayer::query()->where('round_id', $second->id)->where('player_id', $seat->id)->firstOrFail();

        Date::setTestNow($closedAt->addMilliseconds(900));
        DB::transaction(static function () use ($second, $participation, $guess, $closedAt): void {
            app(SeatInputClosed::class)->handle(Round::query()->whereKey($second->id)->lockForUpdate()->firstOrFail(), $participation, $guess, $closedAt);
        });
        $expected[] = ['round.closed', 2, null, 900];

        // Une ligne par diffusion de frontière, rien pour les autres
        // (`player.locked`), dans l'ordre d'émission.
        $contexts = $journal->contexts(GameJournal::BROADCAST_DELAY);
        $boundaries = array_values(array_filter(
            array_column($recorder->sent, 'event'),
            static fn (string $event): bool => in_array($event, ['round.scheduled', 'tier.opened', 'round.closed', 'round.revealed'], true),
        ));

        expect(array_map(
            static fn (array $context): array => [$context['event'], $context['sequenceIndex'], $context['tierIndex'] ?? null, $context['delayMs']],
            $contexts,
        ))->toBe($expected)
            ->and(array_column($contexts, 'event'))->toBe($boundaries)
            ->and(array_column($recorder->sent, 'event'))->toContain('player.locked');

        // Sans donnée de joueur : la partie par sa référence publique, la
        // manche par sa position, le palier par son index — rien d'autre.
        foreach ($contexts as $context) {
            expect(array_diff(array_keys($context), ['gameRef', 'event', 'sequenceIndex', 'tierIndex', 'delayMs']))->toBe([])
                ->and($context['gameRef'])->toBe(GameRef::for($game));
        }

        expect(array_column($journal->lines(GameJournal::BROADCAST_DELAY), 'level_name'))->each->toBe('INFO');

        // Les transitions y sont aussi : ouvertures, et clôtures avec leur
        // cause.
        expect(array_column($journal->contexts(GameJournal::ROUND_OPENED), 'sequenceIndex'))->toBe([1, 2])
            ->and(array_map(
                static fn (array $context): array => [$context['sequenceIndex'], $context['cause']],
                $journal->contexts(GameJournal::ROUND_CLOSED),
            ))->toBe([[1, GameJournal::CLOSE_CAUSE_DURATION], [2, GameJournal::CLOSE_CAUSE_EARLY_END]]);

        $raw = $journal->raw();
        $room = $game->room ?? throw new LogicException('Partie sans salon.');

        expect($raw)->not->toContain($seat->nickname)
            ->and($raw)->not->toContain($seat->public_id)
            ->and($raw)->not->toContain((string) $room->room_code)
            ->and($raw)->not->toContain((string) $seat->player_token_hash);
    } finally {
        $journal->stop();
    }
});

test('un job de frontière en échec est redispatché une fois pour le même instant, puis journalisé', function (): void {
    // Ajout (§ 4.6) : `failed()` du job de frontière.
    $journal = GameJournalRecorder::start();

    try {
        $game = EngineFixtures::game(EngineFixtures::settings());
        EngineFixtures::materialize($game);
        $round = EngineFixtures::round($game, 1);
        $dueAt = WireTime::iso($this->now->subSeconds(2));

        $job = new AdvanceRound($game->id, $round->id, RoundStep::Close, null, $dueAt);
        $job->failed(new RuntimeException('transition en échec'));

        $retry = Queue::pushed(AdvanceRound::class)->last();

        expect(Queue::pushed(AdvanceRound::class))->toHaveCount(1)
            ->and($retry?->step)->toBe(RoundStep::Close)
            ->and($retry?->dueAt)->toBe($dueAt)
            ->and($retry?->retried)->toBeTrue()
            ->and($retry?->queue)->toBe('game')
            ->and($retry?->delay)->toEqual($this->now->addMilliseconds(EngineConstants::transitionMaxWaitMs()))
            ->and($journal->lines(GameJournal::TRANSITION_FAILED_TWICE))->toBe([]);

        // Le second échec n'est pas redispatché : il part au journal.
        $retry?->failed(new RuntimeException('transition en échec'));

        expect(Queue::pushed(AdvanceRound::class))->toHaveCount(1)
            ->and($journal->contexts(GameJournal::TRANSITION_FAILED_TWICE))->toBe([[
                'gameRef' => GameRef::for($game),
                'mode' => $game->mode->value,
                'sequenceIndex' => 1,
                'roundNumber' => 1,
                'step' => RoundStep::Close->value,
                'dueAt' => $dueAt,
                'exception' => RuntimeException::class,
            ]])
            ->and(array_column($journal->lines(GameJournal::TRANSITION_FAILED_TWICE), 'level_name'))->toBe(['ERROR']);
    } finally {
        $journal->stop();
    }
});

test('une diffusion en échec part au journal game, désignée par son événement, sans annuler la transition', function (): void {
    // Ajout (§ 4.6, § 4.7) : `ShouldRescue` rapporte et avale ; le journal
    // `game` la désigne.
    $journal = GameJournalRecorder::start();

    try {
        $recorder = RecordingBroadcaster::install();

        $game = EngineFixtures::game(EngineFixtures::settings());
        EngineFixtures::seat($game);
        EngineFixtures::materialize($game);
        $round = EngineFixtures::round($game, 1);
        EngineFixtures::schedule($round, $this->now->addSeconds(5));

        $recorder->failing = true;
        $opened = EngineFixtures::openTier($round, 1);

        expect($opened->served_at)->not->toBeNull()
            ->and($recorder->attempts)->toBe(2)
            ->and($journal->contexts(GameJournal::BROADCAST_FAILED))->toBe([[
                'gameRef' => GameRef::for($game),
                'event' => 'tier.opened',
                'sequenceIndex' => 1,
                'exception' => BroadcastException::class,
            ]])
            ->and(lifecycleBoundaries($round))->toContain([RoundStep::OpenTier->value, 2, WireTime::iso(EngineFixtures::opensAt($round, 2))]);
    } finally {
        $journal->stop();
    }
});

test('journalise sur le canal game les transitions de la partie, sans aucune donnée de joueur', function (): void {
    // Ajout (§ 4.7) : chaque point de journalisation branché sur les
    // transitions de L60-5 et L60-6 — ouverture et clôture de manche,
    // substitution, annulation remplacée ou non, pause, et les trois gels
    // décidés par 60 (fin de révélation, annulation, pause échue).
    Sleep::fake(syncWithCarbon: true);
    $journal = GameJournalRecorder::start();

    try {
        // Partie A : substitution à la frappe du palier 2, annulation
        // remplacée, annulation sans réserve pendant une révélation, gel à
        // la fin de cette révélation.
        $gameA = EngineFixtures::game(EngineFixtures::settings());
        $seatA = EngineFixtures::seat($gameA);
        $moviesA = EngineFixtures::materialize($gameA, reserve: 1);
        $levels = FrameLevelCoverage::nominal($gameA->frames_per_round);

        Frame::factory()->for($moviesA[0])->level($levels[1])->published()->create();
        EngineFixtures::variant($moviesA[0], $levels[1])->forceFill(['availability' => ContentAvailability::Suspended])->save();

        $first = EngineFixtures::round($gameA, 1);
        EngineFixtures::schedule($first, $this->now->addSeconds(5));
        EngineFixtures::play($first);

        $second = EngineFixtures::round($gameA, 2);
        $secondT1 = $second->refresh()->started_at ?? throw new LogicException('Manche 2 non programmée.');
        Date::setTestNow($secondT1);
        app(CancelRound::class)->handle($second, RoundIncidentReason::FrameUnavailable, $secondT1);

        $replacement = Round::query()->where('game_id', $gameA->id)->where('round_number', 2)->where('status', RoundStatus::Pending->value)->firstOrFail();

        foreach (range(1, $gameA->frames_per_round) as $tierIndex) {
            EngineFixtures::openTier($replacement, $tierIndex);
        }

        EngineFixtures::close($replacement);
        EngineFixtures::reveal($replacement);

        $third = EngineFixtures::round($gameA, 3);
        app(CancelRound::class)->handle($third, RoundIncidentReason::FrameUnavailable, Date::now()->toImmutable());
        $lastRevealEndsAt = $replacement->refresh()->reveal_ends_at ?? throw new LogicException('Remplaçant sans fin de révélation.');
        EngineFixtures::endReveal($replacement);

        expect($gameA->refresh()->status)->toBe(GameStatus::Completed);

        // Parties B et D : pause sans siège présent, puis interruption — par
        // son job (B), par un rattrapage tardif (D).
        $pause = static function (): array {
            $game = EngineFixtures::game(EngineFixtures::settings());
            $seat = EngineFixtures::seat($game);
            EngineFixtures::materialize($game);
            $opening = EngineFixtures::round($game, 1);
            EngineFixtures::schedule($opening, Date::now()->toImmutable()->addSeconds(5));

            foreach (range(1, $game->frames_per_round) as $tierIndex) {
                EngineFixtures::openTier($opening, $tierIndex);
            }

            EngineFixtures::close($opening);
            EngineFixtures::reveal($opening);
            $seat->forceFill(['connection_state' => PlayerConnectionState::Disconnected, 'disconnected_at' => Date::now()])->save();
            EngineFixtures::endReveal($opening);

            return [$game, $seat, $game->refresh()->paused_at ?? throw new LogicException('Partie non en pause.')];
        };

        [$gameB, $seatB, $pausedAt] = $pause();
        $interrupt = new InterruptPausedGame($gameB->id, WireTime::iso($pausedAt));
        Date::setTestNow($interrupt->interruptsAt());
        app()->call([$interrupt, 'handle']);

        [$gameD, , $pausedAtD] = $pause();
        $interruptsAtD = $pausedAtD->addMilliseconds(EngineConstants::pauseTimeoutMs());
        Date::setTestNow($interruptsAtD->addSeconds(3));
        app(CatchUpGame::class)->handle($gameD, $interruptsAtD->addSeconds(3));

        expect($gameB->refresh()->status)->toBe(GameStatus::Interrupted)
            ->and($gameD->refresh()->status)->toBe(GameStatus::Interrupted);

        // Partie C : la dernière manche annulée avant son T₁, hors de toute
        // révélation — gel à l'instant de l'annulation.
        $gameC = EngineFixtures::game(EngineFixtures::settings());
        EngineFixtures::seat($gameC);
        EngineFixtures::materialize($gameC);
        EngineFixtures::schedule(EngineFixtures::round($gameC, 1), Date::now()->toImmutable()->addSeconds(5));

        foreach ([1, 2] as $sequenceIndex) {
            EngineFixtures::play(EngineFixtures::round($gameC, $sequenceIndex));
        }

        $lastC = EngineFixtures::round($gameC, 3);
        $cancelledAt = $lastC->started_at ?? throw new LogicException('Manche 3 non programmée.');
        app(CancelRound::class)->handle($lastC, RoundIncidentReason::NoVariantAvailable, $cancelledAt);

        expect($gameC->refresh()->status)->toBe(GameStatus::Completed);

        $refA = GameRef::for($gameA);
        $refB = GameRef::for($gameB);
        $refC = GameRef::for($gameC);

        $of = static fn (string $message, string $gameRef): array => array_values(array_filter(
            $journal->contexts($message),
            static fn (array $context): bool => $context['gameRef'] === $gameRef,
        ));

        expect(array_map(static fn (array $context): array => [$context['sequenceIndex'], $context['tierIndex'], $context['reason']], $of(GameJournal::TIER_SUBSTITUTED, $refA)))
            ->toBe([[1, 2, RoundIncidentReason::FrameUnavailable->value]])
            ->and(array_map(static fn (array $context): array => [$context['sequenceIndex'], $context['roundNumber'], $context['reason'], $context['replaced']], $of(GameJournal::ROUND_CANCELLED, $refA)))
            ->toBe([
                [2, 2, RoundIncidentReason::FrameUnavailable->value, true],
                [3, 3, RoundIncidentReason::FrameUnavailable->value, false],
            ])
            ->and(array_column($of(GameJournal::ROUND_OPENED, $refA), 'roundNumber'))->toBe([1, 2])
            ->and(array_column($of(GameJournal::ROUND_CLOSED, $refA), 'cause'))->toBe([GameJournal::CLOSE_CAUSE_DURATION, GameJournal::CLOSE_CAUSE_DURATION])
            ->and($of(GameJournal::GAME_FINALIZED, $refA))->toBe([[
                'gameRef' => $refA,
                'mode' => $gameA->mode->value,
                'outcome' => GameStatus::Completed->value,
                'finalizedAt' => WireTime::iso($lastRevealEndsAt),
            ]])
            ->and($of(GameJournal::GAME_PAUSED, $refB))->toBe([['gameRef' => $refB, 'mode' => $gameB->mode->value, 'pausedAt' => WireTime::iso($pausedAt)]])
            ->and(array_column($of(GameJournal::GAME_FINALIZED, $refB), 'outcome'))->toBe([GameStatus::Interrupted->value])
            ->and(array_column($of(GameJournal::GAME_FINALIZED, $refB), 'finalizedAt'))->toBe([WireTime::iso($interrupt->interruptsAt())])
            ->and($of(GameJournal::GAME_FINALIZED, GameRef::for($gameD)))->toBe([[
                'gameRef' => GameRef::for($gameD),
                'mode' => $gameD->mode->value,
                'outcome' => GameStatus::Interrupted->value,
                'finalizedAt' => WireTime::iso($interruptsAtD),
            ]])
            ->and(array_map(static fn (array $context): array => [$context['sequenceIndex'], $context['replaced']], $of(GameJournal::ROUND_CANCELLED, $refC)))->toBe([[3, false]])
            ->and(array_column($of(GameJournal::GAME_FINALIZED, $refC), 'finalizedAt'))->toBe([WireTime::iso($cancelledAt)]);

        // Aucune donnée de joueur, aucun identifiant interne, aucun titre.
        $raw = $journal->raw();

        foreach ([$seatA, $seatB] as $seat) {
            expect($raw)->not->toContain($seat->nickname)
                ->and($raw)->not->toContain($seat->public_id);
        }

        foreach ($moviesA as $movie) {
            expect($raw)->not->toContain($movie->title_original);
        }

        foreach ($journal->lines() as $line) {
            expect(array_keys($line['context'] ?? []))->each->toBeIn([
                'gameRef', 'mode', 'inputDifficulty', 'sequenceIndex', 'roundNumber', 'tierIndex', 'reason', 'replaced', 'cause',
                'startedAt', 'closedAt', 'pausedAt', 'resumedAt', 'outcome', 'finalizedAt', 'event', 'delayMs',
            ]);
        }
    } finally {
        $journal->stop();
    }
});

test('une transition annulée ne laisse aucune ligne au journal game', function (): void {
    // Ajout (§ 4.7) : le journal ne raconte que ce qui a eu lieu — une ligne
    // écrite dans une transaction ne part qu'à son commit.
    $journal = GameJournalRecorder::start();

    try {
        $game = EngineFixtures::game(EngineFixtures::settings());
        EngineFixtures::seat($game);
        EngineFixtures::materialize($game);
        $round = EngineFixtures::round($game, 1);
        EngineFixtures::schedule($round, $this->now->addSeconds(5));
        $t1 = EngineFixtures::opensAt($round, 1);
        Date::setTestNow($t1);

        try {
            DB::transaction(static function () use ($round, $t1): void {
                app(OpenTier::class)->handle(EngineFixtures::tier($round, 1), $t1);

                throw new RuntimeException('transition annulée après l’ouverture');
            });
        } catch (RuntimeException) {
            // Rien n'a eu lieu.
        }

        expect(EngineFixtures::tier($round, 1)->served_at)->toBeNull()
            ->and($journal->lines(GameJournal::ROUND_OPENED))->toBe([]);

        // La même ouverture, validée, part au journal.
        app(OpenTier::class)->handle(EngineFixtures::tier($round, 1), $t1);

        expect(array_column($journal->contexts(GameJournal::ROUND_OPENED), 'startedAt'))->toBe([WireTime::iso($t1)]);
    } finally {
        $journal->stop();
    }
});

test('la manche suivante n\'agit qu\'avec l\'autorité évaluée sur le salon relu sous son verrou', function (): void {
    // Ajout (§ 5.4, étape 1) : l'action seule — la route de l'hôte et ses
    // intitulés arrivent avec L60-13.
    $game = EngineFixtures::game(EngineFixtures::settings());
    EngineFixtures::seat($game);
    EngineFixtures::materialize($game);
    $first = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($first, $this->now->addSeconds(5));

    foreach (range(1, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    $revealEndsAt = $first->refresh()->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $gestureAt = Date::now()->toImmutable()->addSeconds(1);
    Date::setTestNow($gestureAt);

    // En multijoueur, jamais sans autorité à évaluer.
    expect(static fn () => app(AdvanceToNextRound::class)->handle($game, $gestureAt))->toThrow(LogicException::class);

    // L'autorité reçoit le salon relu sous son verrou, jamais la relation
    // déjà chargée : un transfert d'hôte écrit depuis est vu ; refusée, rien
    // n'est raccourci ni reprogrammé.
    $staleRoom = $game->load('room')->room ?? throw new LogicException('Partie sans salon.');
    $otherSeat = EngineFixtures::seat($game);
    Room::query()->whereKey($game->room_id)->update(['host_player_id' => $otherSeat->id]);

    $seen = [];
    $outcome = app(AdvanceToNextRound::class)->handle($game, $gestureAt, static function (Room $room) use (&$seen, $staleRoom): bool {
        $seen[] = [$room->id, $room->host_player_id, $room !== $staleRoom];

        return false;
    });

    expect($outcome)->toBe(NextRoundOutcome::Forbidden)
        ->and($staleRoom->host_player_id)->not->toBe($otherSeat->id)
        ->and($seen)->toBe([[$game->room_id, $otherSeat->id, true]])
        ->and($first->refresh()->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(EngineFixtures::round($game, 2)->started_at?->equalTo($revealEndsAt))->toBeTrue();

    // Accordée : la révélation finit à `now + preload_lead_ms + marge`, et le
    // job de fin de révélation suit.
    $newEnd = $gestureAt->addMilliseconds($game->preload_lead_ms + EngineConstants::nextRoundMarginMs());

    expect(app(AdvanceToNextRound::class)->handle($game, $gestureAt, static fn (Room $room): bool => true))->toBe(NextRoundOutcome::Advanced)
        ->and($first->refresh()->reveal_ends_at?->equalTo($newEnd))->toBeTrue()
        ->and(lifecycleBoundaries($first))->toContain([RoundStep::EndReveal->value, null, WireTime::iso($newEnd)]);
});

/**
 * Un salon en partie, sa manche 1 jouée jusqu'à sa révélation : l'hôte et un
 * autre siège, tenus par des jetons, onglets actifs frappés.
 *
 * @return array{room: Room, game: Game, first: Round, host: Player, hostToken: PlayerToken, seat: Player, seatToken: PlayerToken}
 */
function lifecycleRevealingRoom(): array
{
    [$room, $host, $hostToken] = HostGestures::room();
    [$seat, $seatToken] = HostGestures::seat($room);
    [$game, $first] = HostGestures::runningGame($room, [$host, $seat]);

    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($first, $tierIndex);
    }

    EngineFixtures::close($first);
    EngineFixtures::reveal($first);

    return [
        'room' => $room->refresh(),
        'game' => $game->refresh(),
        'first' => $first->refresh(),
        'host' => $host,
        'hostToken' => $hostToken,
        'seat' => $seat,
        'seatToken' => $seatToken,
    ];
}

/**
 * « Manche suivante » par la route, telle que le client l'envoie : JSON,
 * cookie du jeton, onglet actif, reçue à `$at`.
 *
 * @return TestResponse<Response>
 */
function lifecycleNextRound(TestCase $test, Room $room, Player $seat, PlayerToken $token, CarbonImmutable $at): TestResponse
{
    Date::setTestNow($at);
    LobbyWrites::actAs($test, $token);

    return $test->postJson(route('room.round.next', $room), [], [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token]);
}

test('la manche suivante raccourcit R sans descendre sous preload_lead_ms plus la marge et répond 409 hors révélation', function (): void {
    $recorder = RecordingBroadcaster::install();
    $scene = lifecycleRevealingRoom();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'hostToken' => $token] = $scene;
    $second = EngineFixtures::round($game, 2);
    $revealStartsAt = ($first->ended_at ?? throw new LogicException('Manche 1 non close.'))->addMilliseconds($game->tier_grace_ms);
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $margin = $game->preload_lead_ms + EngineConstants::nextRoundMarginMs();

    // Une seconde après le début de la révélation, l'hôte avance la manche
    // suivante : la révélation finit à `now + preload_lead_ms + marge`, et
    // la manche suivante part à cet instant, `round.scheduled` réémis.
    $recorder->sent = [];
    $gestureAt = $revealStartsAt->addMilliseconds(1_137);
    $newEnd = $gestureAt->addMilliseconds($margin);

    expect($newEnd->lessThan($revealEndsAt))->toBeTrue();

    lifecycleNextRound($this, $room, $host, $token, $gestureAt)->assertNoContent();

    expect($first->refresh()->reveal_ends_at?->equalTo($newEnd))->toBeTrue()
        ->and($first->status)->toBe(RoundStatus::Revealing)
        // `D` n'est jamais touché, ni l'instant de clôture de la manche révélée.
        ->and($first->duration_ms)->toBe($scene['first']->duration_ms)
        ->and($first->ended_at?->equalTo($scene['first']->ended_at))->toBeTrue()
        ->and($second->refresh()->started_at?->equalTo($newEnd))->toBeTrue()
        ->and(lifecycleBoundaries($first))->toContain([RoundStep::EndReveal->value, null, WireTime::iso($newEnd)])
        ->and(array_column($recorder->sent, 'event'))->toBe(['round.scheduled'])
        ->and($recorder->sent[0]['payload']['round']['startsAt'])->toBe(WireTime::iso($newEnd));

    // Un second geste aussitôt : la fin est déjà plus proche que la marge,
    // rien ne bouge — jamais sous `preload_lead_ms + marge`.
    $recorder->sent = [];
    lifecycleNextRound($this, $room, $host, $token, $gestureAt->addMilliseconds(200))->assertNoContent();

    expect($first->refresh()->reveal_ends_at?->equalTo($newEnd))->toBeTrue()
        ->and($second->refresh()->started_at?->equalTo($newEnd))->toBeTrue()
        ->and($recorder->sent)->toBe([]);

    // À la fin de révélation, et après : 409 `not_revealing`. Le geste
    // rattrape d'abord la partie — la fin de révélation échue est appliquée.
    lifecycleNextRound($this, $room, $host, $token, $newEnd)
        ->assertConflict()
        ->assertExactJson(['code' => 'not_revealing']);

    expect($first->refresh()->status)->toBe(RoundStatus::Completed)
        ->and($first->reveal_ends_at?->equalTo($newEnd))->toBeTrue();

    // Pendant une manche en cours : 409, `D` intact.
    $runningAt = EngineFixtures::opensAt($second, 1)->addSeconds(2);
    $durationEnd = EngineFixtures::durationEnd($second);

    lifecycleNextRound($this, $room, $host, $token, $runningAt)
        ->assertConflict()
        ->assertExactJson(['code' => 'not_revealing']);

    expect($second->refresh()->status)->toBe(RoundStatus::Running)
        ->and($second->ended_at)->toBeNull()
        ->and(EngineFixtures::durationEnd($second)->equalTo($durationEnd))->toBeTrue();

    // Sans partie en cours (salon au lobby) : 409 aussi.
    [$lobby, $lobbyHost, $lobbyToken] = HostGestures::room();

    lifecycleNextRound($this, $lobby, $lobbyHost, $lobbyToken, $runningAt)
        ->assertConflict()
        ->assertExactJson(['code' => 'not_revealing']);
});

test('la manche suivante est refusée à un siège qui n\'est pas l\'hôte', function (): void {
    $scene = lifecycleRevealingRoom();
    ['room' => $room, 'game' => $game, 'first' => $first] = $scene;
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $gestureAt = ($first->ended_at ?? throw new LogicException('Manche 1 non close.'))->addMilliseconds($game->tier_grace_ms + 1_000);

    // Un siège du salon qui n'est pas l'hôte : 403, rien ne bouge.
    lifecycleNextRound($this, $room, $scene['seat'], $scene['seatToken'], $gestureAt)->assertForbidden();

    expect($first->refresh()->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(EngineFixtures::round($game, 2)->started_at?->equalTo($revealEndsAt))->toBeTrue();

    // Un jeton sans siège dans ce salon : 403 dès `seat.active`.
    [, $stranger, $strangerToken] = HostGestures::room();

    lifecycleNextRound($this, $room, $stranger, $strangerToken, $gestureAt)->assertForbidden();

    // L'hôte, depuis un onglet supplanté : 409 `seat_superseded`.
    $superseded = clone $scene['host'];
    $superseded->active_seat_token = 'onglet-supplante';

    lifecycleNextRound($this, $room, $superseded, $scene['hostToken'], $gestureAt)
        ->assertConflict()
        ->assertExactJson(['code' => 'seat_superseded']);

    expect($first->refresh()->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue();
});

test('la manche suivante relit l\'hôte sous le verrou du salon', function (): void {
    $scene = lifecycleRevealingRoom();
    ['room' => $room, 'game' => $game, 'first' => $first, 'host' => $host, 'seat' => $seat] = $scene;
    $revealEndsAt = $first->reveal_ends_at ?? throw new LogicException('Manche 1 sans fin de révélation.');
    $gestureAt = ($first->ended_at ?? throw new LogicException('Manche 1 non close.'))->addMilliseconds($game->tier_grace_ms + 1_000);

    // Un transfert d'hôte concurrent se valide juste après la première garde
    // (la policy du contrôleur) : l'action relit le salon sous son verrou et
    // refuse, 403, sans rien raccourcir.
    $evaluations = [];
    Event::listen(GateEvaluated::class, static function (GateEvaluated $event) use (&$evaluations, $room, $seat): void {
        if ($event->ability !== 'advanceRound') {
            return;
        }

        $evaluated = $event->arguments[0] ?? null;
        $evaluations[] = [$evaluated instanceof Room ? $evaluated->host_player_id : null, $event->result];

        if (count($evaluations) === 1) {
            Room::query()->whereKey($room->id)->update(['host_player_id' => $seat->id]);
        }
    });

    lifecycleNextRound($this, $room, $host, $scene['hostToken'], $gestureAt)->assertForbidden();

    expect($evaluations)->toBe([[$host->id, true], [$seat->id, false]])
        ->and($first->refresh()->reveal_ends_at?->equalTo($revealEndsAt))->toBeTrue()
        ->and(EngineFixtures::round($game, 2)->started_at?->equalTo($revealEndsAt))->toBeTrue();

    // Le nouvel hôte, lui, avance la manche.
    $handedAt = $gestureAt->addMilliseconds(300);

    lifecycleNextRound($this, $room, $seat, $scene['seatToken'], $handedAt)->assertNoContent();

    expect($first->refresh()->reveal_ends_at?->equalTo($handedAt->addMilliseconds($game->preload_lead_ms + EngineConstants::nextRoundMarginMs())))->toBeTrue();
});
