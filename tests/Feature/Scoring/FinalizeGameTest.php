<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Events\Game\GameFinalized;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Scoring\Ranking;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Scoring\ScoringFixtures;

/*
|--------------------------------------------------------------------------
| L'action de gel — spec 80 § 10, contrat C13 § 2.3 et § 4.5 (L80-4)
|--------------------------------------------------------------------------
|
| `FinalizeGame` est la seule écrivaine de `game.ended_at`, du statut final,
| de la valeur finale de `rounds_completed` et des cinq agrégats de
| `game_player`. Idempotente sous le verrou `game` ; clôture d'office des
| manches non terminales ; filtre unique `Settled` (manches `completed` après
| clôture) pour tous les agrégats ; rang nul en solo et pour un siège sans
| manche jouée ; `GameFinalized` après commit, une fois.
|
| Les points attendus sont recalculés en commentaire depuis 80 § 3.2 et
| § 4.5 (N = 3, D = 30 s, 300 / 200 / 100, B_max = 50 %), sur l'instant
| corrigé de la grâce : 1 700 → 424 ; 2 000 → 420 ; 3 300 → 400 ;
| 8 700 → 319 ; 11 700 → 283 ; 21 700 → 141.
|
*/

/**
 * Réglages au nombre minimal de manches : une partie complète tient en
 * `RoomSettingsBounds::MIN_ROUNDS_COUNT` manches.
 */
function finalizeGameShortSettings(): RoomSettings
{
    return RoomSettings::fromInput(['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT]);
}

/**
 * Les cinq agrégats d'un siège, relus en base, dans l'ordre de 80 § 10.4.
 *
 * @return array{rounds_played: int|null, correct_answers: int|null, final_score: int|null, total_answer_time_ms: int|null, final_rank: int|null}
 */
function finalizeGameAggregates(Game $game, Player $seat): array
{
    $row = GamePlayer::query()->where('game_id', $game->id)->where('player_id', $seat->id)->firstOrFail();

    return [
        'rounds_played' => $row->rounds_played,
        'correct_answers' => $row->correct_answers,
        'final_score' => $row->final_score,
        'total_answer_time_ms' => $row->total_answer_time_ms,
        'final_rank' => $row->final_rank,
    ];
}

/**
 * Les agrégats attendus, dans l'ordre de 80 § 10.4.
 *
 * @return array{rounds_played: int, correct_answers: int, final_score: int, total_answer_time_ms: int, final_rank: int|null}
 */
function finalizeGameExpected(int $roundsPlayed, int $correctAnswers, int $finalScore, int $totalAnswerTimeMs, ?int $finalRank): array
{
    return [
        'rounds_played' => $roundsPlayed,
        'correct_answers' => $correctAnswers,
        'final_score' => $finalScore,
        'total_answer_time_ms' => $totalAnswerTimeMs,
        'final_rank' => $finalRank,
    ];
}

/**
 * Un instant à la milliseconde, pour comparer sans ambiguïté de fuseau ni de
 * précision.
 */
function finalizeGameInstant(?CarbonImmutable $instant): ?string
{
    return $instant?->utc()->format('Y-m-d H:i:s.v');
}

/**
 * Les lignes brutes d'une partie — `game`, `game_player`, `round` —, telles
 * qu'en base : ce qu'un second appel ne doit pas toucher d'un octet.
 *
 * @return array{game: array<string, mixed>, seats: list<array<string, mixed>>, rounds: list<array<string, mixed>>}
 */
function finalizeGameRows(Game $game): array
{
    return [
        'game' => (array) DB::table('game')->where('id', $game->id)->first(),
        'seats' => DB::table('game_player')->where('game_id', $game->id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        'rounds' => DB::table('round')->where('game_id', $game->id)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

/**
 * Les requêtes d'écriture exécutées pendant `$callback`, avec leur niveau de
 * transaction.
 *
 * @return list<array{sql: string, level: int}>
 */
function finalizeGameWrites(callable $callback): array
{
    $writes = [];
    $recording = true;

    DB::listen(static function (QueryExecuted $query) use (&$writes, &$recording): void {
        if ($recording && preg_match('/^\s*(?:insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        }
    });

    try {
        $callback();
    } finally {
        $recording = false;
    }

    return $writes;
}

test('le gel écrit ended_at, le statut, rounds_completed et les cinq agrégats dans une seule transaction', function (): void {
    Event::fake([GameFinalized::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game(finalizeGameShortSettings());
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);
    $c = ScoringFixtures::seat($game, GamePlayerStatus::Left);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::find($first, $b, 21_700 + $grace);  // 141
    ScoringFixtures::played($first, $c);

    // C est parti après la manche 1 : aucune ligne ensuite.
    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::find($second, $b, 1_700 + $grace);  // 424
    ScoringFixtures::find($second, $a, 11_700 + $grace); // 283

    // Fin normale : la dernière manche est encore en révélation.
    $third = ScoringFixtures::round($game, 3, RoundStatus::Revealing);
    ScoringFixtures::find($third, $a, 8_700 + $grace);   // 319
    ScoringFixtures::played($third, $b);

    // 60 a maintenu rounds_completed jusqu'à la manche 2.
    $game->forceFill(['rounds_completed' => 2])->save();
    $game->refresh();

    $endedAt = $third->reveal_ends_at;
    $finalize = app(FinalizeGame::class);

    expect($endedAt)->not->toBeNull();

    // Atomicité : une panne au dernier pas (écriture de la partie) annule tout
    // ce que la transaction a déjà écrit — manches closes, agrégats.
    $fail = true;
    Game::updating(static function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('panne simulée à l’écriture de la partie');
        }
    });

    expect(fn () => $finalize->handle($game, GameStatus::Completed, $endedAt))
        ->toThrow(RuntimeException::class, 'panne simulée');

    foreach ([$a, $b, $c] as $seat) {
        expect(array_filter(finalizeGameAggregates($game, $seat), static fn (?int $value): bool => $value !== null))->toBe([]);
    }

    expect($third->refresh()->status)->toBe(RoundStatus::Revealing)
        ->and(Game::query()->findOrFail($game->id)->ended_at)->toBeNull()
        ->and(Game::query()->findOrFail($game->id)->rounds_completed)->toBe(2);
    Event::assertNotDispatched(GameFinalized::class);

    // Le vrai gel, une seconde plus tard (l'horodatage de mise à jour bouge).
    $fail = false;
    $this->travel(1)->seconds();
    $before = finalizeGameRows($game);
    $base = DB::transactionLevel();
    $opened = 0;

    DB::beforeStartingTransaction(static function (Connection $connection) use (&$opened, $base): void {
        if ($connection->transactionLevel() === $base) {
            $opened++;
        }
    });

    $frozen = null;
    $writes = finalizeGameWrites(function () use ($finalize, $game, $endedAt, &$frozen): void {
        $frozen = $finalize->handle($game, GameStatus::Completed, $endedAt);
    });

    expect($frozen)->toBeTrue();

    // Une seule transaction ouverte au-dessus de l'appelant, et toutes les
    // écritures dedans.
    expect($opened)->toBe(1)
        ->and($writes)->not->toBeEmpty()
        ->and(array_filter($writes, static fn (array $write): bool => $write['level'] <= $base))->toBe([]);

    // Rien sur room, et aucune écriture hors des trois tables du gel.
    foreach ($writes as $write) {
        expect($write['sql'])->toMatch('/^\s*update\s+["`]?(?:game|game_player|round)["`]?\s/i');
    }

    // La partie : statut, fin, manches closes recalculées.
    $stored = Game::query()->findOrFail($game->id);

    expect($stored->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameInstant($stored->ended_at))->toBe(finalizeGameInstant($endedAt))
        ->and($stored->rounds_completed)->toBe(3)
        ->and($third->refresh()->status)->toBe(RoundStatus::Completed);

    // Aucune colonne figée, ni la pause, ni rien d'autre : seuls statut, fin,
    // manches closes et horodatage de mise à jour ont changé.
    $after = finalizeGameRows($game);
    $changed = array_keys(array_diff_assoc(
        array_map(static fn (mixed $value): string => var_export($value, true), $after['game']),
        array_map(static fn (mixed $value): string => var_export($value, true), $before['game']),
    ));
    sort($changed);

    expect($changed)->toBe(['ended_at', 'rounds_completed', 'status', 'updated_at']);

    // Les cinq agrégats. A : 424 + 283 + 319 ; B : 141 + 424 ; C, parti, classé.
    expect(finalizeGameAggregates($game, $a))->toBe(finalizeGameExpected(3, 3, 1_026, 22_100 + 3 * $grace, 1))
        ->and(finalizeGameAggregates($game, $b))->toBe(finalizeGameExpected(3, 2, 565, 23_400 + 2 * $grace, 2))
        ->and(finalizeGameAggregates($game, $c))->toBe(finalizeGameExpected(1, 0, 0, 0, 3));

    // L'issue d'un siège n'est pas une donnée du gel.
    expect(GamePlayer::query()->where('game_id', $game->id)->orderBy('id')->pluck('status')->all())
        ->toBe([GamePlayerStatus::Playing, GamePlayerStatus::Playing, GamePlayerStatus::Left]);

    // L'instance de l'appelant porte la partie gelée.
    expect($game->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameInstant($game->ended_at))->toBe(finalizeGameInstant($endedAt))
        ->and($game->rounds_completed)->toBe(3)
        ->and($game->isDirty())->toBeFalse();

    Event::assertDispatchedTimes(GameFinalized::class, 1);
    Event::assertDispatched(
        GameFinalized::class,
        static fn (GameFinalized $event): bool => $event->gameId === $game->id && $event->outcome === GameStatus::Completed,
    );
});

test('un second appel ne réécrit rien et ne réémet pas GameFinalized', function (): void {
    Event::fake([GameFinalized::class]);

    $game = ScoringFixtures::game(finalizeGameShortSettings());
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);
    ScoringFixtures::played($first, $b);
    $last = ScoringFixtures::round($game, 2, RoundStatus::Revealing);
    ScoringFixtures::find($last, $b, 8_700 + $grace);
    ScoringFixtures::played($last, $a);

    $endedAt = $last->reveal_ends_at;
    $finalize = app(FinalizeGame::class);

    expect($endedAt)->not->toBeNull();

    // Un second appelant tient une instance lue avant le gel.
    $stale = Game::query()->findOrFail($game->id);

    // Premier appel, sous la transaction de son appelant : GameFinalized
    // attend le commit de l'appelant.
    $frozen = DB::transaction(function () use ($finalize, $game, $endedAt): bool {
        $frozen = $finalize->handle($game, GameStatus::Completed, $endedAt);

        Event::assertNotDispatched(GameFinalized::class);

        return $frozen;
    });

    expect($frozen)->toBeTrue();
    Event::assertDispatchedTimes(GameFinalized::class, 1);

    $rows = finalizeGameRows($game);

    // Plus tard, une clôture concurrente — autre issue, autre instant.
    $this->travel(5)->minutes();

    $again = null;
    $writes = finalizeGameWrites(function () use ($finalize, $stale, &$again): void {
        $again = $finalize->handle($stale, GameStatus::Interrupted, CarbonImmutable::now());
    });

    expect($again)->toBeFalse()
        ->and($writes)->toBe([])
        ->and(finalizeGameRows($game))->toBe($rows);

    Event::assertDispatchedTimes(GameFinalized::class, 1);

    // L'instance du second appelant reflète le premier gel, relu sous verrou.
    expect($stale->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameInstant($stale->ended_at))->toBe(finalizeGameInstant($endedAt))
        ->and($stale->rounds_completed)->toBe(2)
        ->and($stale->isDirty())->toBeFalse();

    // Un troisième appel, sur l'instance déjà gelée, pas davantage.
    expect($finalize->handle($game, GameStatus::Completed, $endedAt))->toBeFalse()
        ->and(finalizeGameRows($game))->toBe($rows);
    Event::assertDispatchedTimes(GameFinalized::class, 1);
});

test('une manche cancelled portant deux guess de 400 points ne modifie ni final_score, ni correct_answers, ni rounds_played, ni le rang', function (): void {
    $finalize = app(FinalizeGame::class);

    /**
     * La même partie, avec ou sans une manche 2 annulée puis remplacée, où B
     * et C ont trouvé pour 400 points chacun avant l'annulation.
     *
     * @return array{0: Game, 1: array<string, Player>, 2: Round|null}
     */
    $play = static function (bool $withCancelled): array {
        $game = ScoringFixtures::game(finalizeGameShortSettings());
        $grace = $game->tier_grace_ms;
        $seats = ['a' => ScoringFixtures::seat($game), 'b' => ScoringFixtures::seat($game), 'c' => ScoringFixtures::seat($game)];

        $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
        ScoringFixtures::find($first, $seats['a'], 1_700 + $grace);   // 424
        ScoringFixtures::find($first, $seats['b'], 21_700 + $grace);  // 141
        ScoringFixtures::played($first, $seats['c']);

        $cancelled = null;
        $sequence = 2;

        if ($withCancelled) {
            $cancelled = ScoringFixtures::round($game, 2, RoundStatus::Running, sequenceIndex: $sequence++);
            ScoringFixtures::find($cancelled, $seats['b'], 3_300 + $grace); // 400
            ScoringFixtures::find($cancelled, $seats['c'], 3_300 + $grace); // 400
            ScoringFixtures::played($cancelled, $seats['a']);
            ScoringFixtures::moveTo($cancelled, RoundStatus::Cancelled);
        }

        // La manche 2 jouée (le remplaçant, s'il y a eu annulation).
        $second = ScoringFixtures::round($game, 2, RoundStatus::Completed, sequenceIndex: $sequence);
        ScoringFixtures::find($second, $seats['a'], 11_700 + $grace); // 283
        ScoringFixtures::played($second, $seats['b']);
        ScoringFixtures::played($second, $seats['c']);

        $last = ScoringFixtures::round($game, 3, RoundStatus::Revealing, sequenceIndex: $sequence + 1);
        ScoringFixtures::played($last, $seats['a']);
        ScoringFixtures::played($last, $seats['b']);
        ScoringFixtures::played($last, $seats['c']);

        return [$game, $seats, $cancelled];
    };

    [$game, $seats, $cancelled] = $play(true);
    [$twin, $twinSeats] = $play(false);

    // Les deux bonnes réponses de la manche annulée valent bien 400 chacune.
    expect($cancelled)->toBeInstanceOf(Round::class);
    expect(Guess::query()->where('round_id', $cancelled?->id)->orderBy('lock_rank')->pluck('points_total')->all())->toBe([400, 400]);

    $endedAt = CarbonImmutable::now();

    expect($finalize->handle($game, GameStatus::Completed, $endedAt))->toBeTrue()
        ->and($finalize->handle($twin, GameStatus::Completed, $endedAt))->toBeTrue();

    $grace = $game->tier_grace_ms;

    // A : 424 + 283 ; B : 141 ; C : rien. Les 400 + 400 de la manche annulée
    // ne comptent nulle part — ni dans les points, ni dans les bonnes
    // réponses, ni dans les manches jouées, ni dans le rang (sinon C, à 400,
    // passerait devant B, à 141).
    $expected = [
        'a' => finalizeGameExpected(3, 2, 707, 13_400 + 2 * $grace, 1),
        'b' => finalizeGameExpected(3, 1, 141, 21_700 + $grace, 2),
        'c' => finalizeGameExpected(3, 0, 0, 0, 3),
    ];

    foreach ($expected as $label => $aggregates) {
        expect(finalizeGameAggregates($game, $seats[$label]))->toBe($aggregates, $label)
            ->and(finalizeGameAggregates($twin, $twinSeats[$label]))->toBe($aggregates, "jumeau {$label}");
    }

    // La manche reste annulée, et sa trace intacte (10 A13) : ni supprimée,
    // ni réécrite.
    expect($cancelled?->refresh()->status)->toBe(RoundStatus::Cancelled)
        ->and(Guess::query()->where('round_id', $cancelled?->id)->orderBy('lock_rank')->pluck('points_total')->all())->toBe([400, 400])
        ->and(Game::query()->findOrFail($game->id)->rounds_completed)->toBe(3);
});

test('correct_answers ≤ rounds_played pour chaque siège, clôture d’office comprise', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);
    $c = ScoringFixtures::seat($game);
    $late = ScoringFixtures::seat($game, firstRoundNumber: 2);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::find($first, $b, 11_700 + $grace);  // 283
    ScoringFixtures::played($first, $c);

    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::find($second, $a, 21_700 + $grace);    // 141
    ScoringFixtures::find($second, $late, 8_700 + $grace);  // 319
    ScoringFixtures::played($second, $b);
    ScoringFixtures::played($second, $c);

    // Partie bloquée : la manche 3 est restée running, sa durée écoulée depuis
    // longtemps, avec deux bonnes réponses.
    $third = ScoringFixtures::round($game, 3, RoundStatus::Running);
    $third->forceFill(['started_at' => CarbonImmutable::now()->subHour()])->save();
    ScoringFixtures::find($third, $a, 1_700 + $grace);      // 424
    ScoringFixtures::find($third, $late, 11_700 + $grace);  // 283
    ScoringFixtures::played($third, $b);
    ScoringFixtures::played($third, $c);

    // Avant le gel, la manche 3 n'est dans aucune lecture Settled.
    $settled = collect(Scoreboard::tallies($game, ScoreScope::Settled))->keyBy('publicId');

    expect($settled[$a->public_id]->roundsPlayed)->toBe(2)
        ->and($settled[$a->public_id]->correctAnswers)->toBe(2);

    $endedAt = FinalizeGame::lastKnownActivity($game, CarbonImmutable::now());

    expect(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, $endedAt))->toBeTrue()
        ->and($third->refresh()->status)->toBe(RoundStatus::Completed);

    // La manche close d'office compte des deux côtés : dans les manches
    // jouées ET dans les bonnes réponses, jamais dans une seule.
    $aggregates = [
        'a' => finalizeGameAggregates($game, $a),
        'b' => finalizeGameAggregates($game, $b),
        'c' => finalizeGameAggregates($game, $c),
        'late' => finalizeGameAggregates($game, $late),
    ];

    foreach ($aggregates as $label => $row) {
        expect($row['correct_answers'])->toBeLessThanOrEqual((int) $row['rounds_played'], $label);
    }

    expect($aggregates['a'])->toBe(finalizeGameExpected(3, 3, 989, 25_100 + 3 * $grace, 1))
        ->and($aggregates['late'])->toBe(finalizeGameExpected(2, 2, 602, 20_400 + 2 * $grace, 2))
        ->and($aggregates['b'])->toBe(finalizeGameExpected(3, 1, 283, 11_700 + $grace, 3))
        ->and($aggregates['c'])->toBe(finalizeGameExpected(3, 0, 0, 0, 4))
        ->and(Game::query()->findOrFail($game->id)->rounds_completed)->toBe(3);
});

test('partie interrompue à la manche k : rounds_completed = k et agrégats gelés', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::find($first, $b, 11_700 + $grace);  // 283

    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::find($second, $b, 1_700 + $grace);  // 424
    ScoringFixtures::played($second, $a);

    $third = ScoringFixtures::round($game, 3, RoundStatus::Completed);
    ScoringFixtures::find($third, $a, 8_700 + $grace);   // 319
    ScoringFixtures::find($third, $b, 21_700 + $grace);  // 141

    // La suite du tirage, jamais jouée : la manche 4 déprogrammée, la réserve.
    $pending = [
        ScoringFixtures::round($game, 4, RoundStatus::Pending),
        ScoringFixtures::round($game, 5, RoundStatus::Pending),
    ];

    // Plus personne après la manche 3 : la partie est en pause, et la clôture
    // à l'échéance prévue arrive (60 § 14.2).
    $pausedAt = CarbonImmutable::now();
    $game->forceFill([
        'status' => GameStatus::Paused,
        'paused_at' => $pausedAt,
        'total_paused_ms' => 4_000,
        'rounds_completed' => 3,
    ])->save();

    $endedAt = $pausedAt->addMilliseconds(EngineConstants::pauseTimeoutMs());

    expect(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, $endedAt))->toBeTrue();

    $stored = Game::query()->findOrFail($game->id);

    expect($stored->status)->toBe(GameStatus::Interrupted)
        ->and(finalizeGameInstant($stored->ended_at))->toBe(finalizeGameInstant($endedAt))
        ->and($stored->rounds_completed)->toBe(3)
        ->and($stored->rounds_count)->toBe(RoomSettingsBounds::DEFAULT_ROUNDS_COUNT)
        ->and($stored->rounds_completed)->toBeLessThan($stored->rounds_count)
        // La pause est la donnée de 60 : le gel n'y touche pas.
        ->and(finalizeGameInstant($stored->paused_at))->toBe(finalizeGameInstant($pausedAt))
        ->and($stored->total_paused_ms)->toBe(4_000);

    // Les manches jamais jouées restent intactes.
    foreach ($pending as $round) {
        expect($round->refresh()->status)->toBe(RoundStatus::Pending)
            ->and($round->ended_at)->toBeNull();
    }

    // A : 424 + 319 ; B : 283 + 424 + 141.
    expect(finalizeGameAggregates($game, $b))->toBe(finalizeGameExpected(3, 3, 848, 35_100 + 3 * $grace, 1))
        ->and(finalizeGameAggregates($game, $a))->toBe(finalizeGameExpected(3, 2, 743, 10_400 + 2 * $grace, 2));
});

test('partie interrompue avant toute manche close : agrégats à zéro, aucun rang', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $seats = [ScoringFixtures::seat($game), ScoringFixtures::seat($game), ScoringFixtures::seat($game, GamePlayerStatus::Left)];

    // La manche 1, programmée, n'a jamais démarré.
    $scheduled = ScoringFixtures::round($game, 1, RoundStatus::Pending);
    $scheduled->forceFill(['started_at' => CarbonImmutable::now()->addSeconds(3)])->save();
    ScoringFixtures::round($game, 2, RoundStatus::Pending);

    $this->travel(2)->days();

    $endedAt = FinalizeGame::lastKnownActivity($game, CarbonImmutable::now());

    expect(finalizeGameInstant($endedAt))->toBe(finalizeGameInstant($game->started_at))
        ->and(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, $endedAt))->toBeTrue();

    // Gelés à zéro — jamais laissés nuls —, et aucun rang : personne ne compte
    // la partie comme jouée.
    foreach ($seats as $seat) {
        expect(finalizeGameAggregates($game, $seat))->toBe(finalizeGameExpected(0, 0, 0, 0, null));
    }

    $stored = Game::query()->findOrFail($game->id);

    expect($stored->status)->toBe(GameStatus::Interrupted)
        ->and($stored->rounds_completed)->toBe(0)
        ->and($stored->ended_at)->not->toBeNull()
        ->and($scheduled->refresh()->status)->toBe(RoundStatus::Pending);
});

test('retardataire : rounds_played ne compte que ses manches', function (): void {
    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game, firstRoundNumber: 1);
    $late = ScoringFixtures::seat($game, firstRoundNumber: 3);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);      // 424

    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::played($second, $a);

    // Le retardataire entre à la manche 3 : aucune ligne avant.
    $third = ScoringFixtures::round($game, 3, RoundStatus::Completed);
    ScoringFixtures::find($third, $a, 11_700 + $grace);     // 283
    ScoringFixtures::find($third, $late, 1_700 + $grace);   // 424

    $fourth = ScoringFixtures::round($game, 4, RoundStatus::Completed);
    ScoringFixtures::played($fourth, $a);
    ScoringFixtures::find($fourth, $late, 8_700 + $grace);  // 319

    expect(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, CarbonImmutable::now()))->toBeTrue();

    // Deux manches, jamais 4 ni M ; classé sur ses seules manches, devant A.
    expect(finalizeGameAggregates($game, $late))->toBe(finalizeGameExpected(2, 2, 743, 10_400 + 2 * $grace, 1))
        ->and(finalizeGameAggregates($game, $a))->toBe(finalizeGameExpected(4, 2, 707, 13_400 + 2 * $grace, 2))
        ->and(GamePlayer::query()->where('game_id', $game->id)->where('player_id', $late->id)->value('first_round_number'))->toBe(3);
});

test('en solo, final_rank reste nul et les manches revealed ou skipped comptent comme jouées, jamais comme bonnes réponses', function (): void {
    $game = ScoringFixtures::game(solo: true);
    $grace = $game->tier_grace_ms;
    $seat = ScoringFixtures::seat($game);

    expect($game->room_id)->toBeNull()
        ->and($seat->room_id)->toBeNull();

    $found = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($found, $seat, 1_700 + $grace);   // 424

    // « Voir la réponse » puis « Passer la manche » : manches completed, sans
    // bonne réponse.
    $revealed = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    RoundPlayer::factory()->forRound($revealed, $seat)->revealed()->create();

    $skipped = ScoringFixtures::round($game, 3, RoundStatus::Completed);
    RoundPlayer::factory()->forRound($skipped, $seat)->skipped()->create();

    ScoringFixtures::round($game, 4, RoundStatus::Pending);

    // Sans le forçage du gel, la chaîne de départage le classerait premier.
    $standings = Ranking::rank(Scoreboard::tallies($game, ScoreScope::Settled), $game->frames_per_round, $game->scoring_version);

    expect($standings[0]->rank)->toBe(1);

    expect(app(FinalizeGame::class)->handle($game, GameStatus::Completed, CarbonImmutable::now()))->toBeTrue();

    expect(finalizeGameAggregates($game, $seat))->toBe(finalizeGameExpected(3, 1, 424, 1_700 + $grace, null))
        ->and(Game::query()->findOrFail($game->id)->rounds_completed)->toBe(3);
});

test('une partie bloquée est close à sa dernière activité connue', function (): void {
    $now = CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC');
    $origin = CarbonImmutable::parse('2025-08-01 10:00:00.000', 'UTC');
    $this->travelTo($origin);

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seat = ScoringFixtures::seat($game);

    // Manche 1 jouée et révélée jusqu'à T0 + 43 s.
    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    $first->forceFill([
        'started_at' => $origin->addSeconds(5),
        'ended_at' => $origin->addSeconds(35),
        'reveal_ends_at' => $origin->addSeconds(43),
    ])->save();
    ScoringFixtures::find($first, $seat, 1_700 + $grace);

    // Manche 2 : l'activité la plus tardive, sa fin théorique à T0 + 80,250 s ;
    // puis plus rien — aucun job n'a tourné.
    $stuck = ScoringFixtures::round($game, 2, RoundStatus::Running);
    $stuck->forceFill(['started_at' => $origin->addMilliseconds(50_250)])->save();
    ScoringFixtures::played($stuck, $seat);

    // Une manche programmée plus tard mais jamais jouée n'est pas une activité.
    $scheduled = ScoringFixtures::round($game, 3, RoundStatus::Pending);
    $scheduled->forceFill(['started_at' => $origin->addSeconds(90)])->save();

    // Une annulation plus tôt, sans origine de temps.
    $cancelled = ScoringFixtures::round($game, 3, RoundStatus::Cancelled, sequenceIndex: 4);
    $cancelled->forceFill(['cancelled_at' => $origin->addSeconds(60)])->save();

    // Treize mois plus tard, la partie est retrouvée ouverte.
    $this->travelTo($now);

    $expected = $origin->addMilliseconds(50_250 + $stuck->duration_ms);
    $lastKnown = FinalizeGame::lastKnownActivity($game, $now);

    expect(finalizeGameInstant($lastKnown))->toBe(finalizeGameInstant($expected))
        ->and(app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, $lastKnown))->toBeTrue();

    $stored = Game::query()->findOrFail($game->id);

    // Close à T0 + 80,250 s, jamais « maintenant » : la fenêtre de 12 mois
    // part de la dernière activité, et la partie est déjà hors fenêtre.
    expect(finalizeGameInstant($stored->ended_at))->toBe('2025-08-01 10:01:20.250')
        ->and($stored->ended_at?->lessThan($now->subMonths(12)))->toBeTrue()
        ->and($stored->status)->toBe(GameStatus::Interrupted)
        ->and($stuck->refresh()->status)->toBe(RoundStatus::Completed)
        ->and(finalizeGameInstant($stuck->ended_at))->toBe('2025-08-01 10:01:20.250')
        ->and($scheduled->refresh()->status)->toBe(RoundStatus::Pending)
        ->and(finalizeGameAggregates($game, $seat)['rounds_played'])->toBe(2);

    // Une pause postérieure à toute manche est la dernière activité.
    $this->travelTo($origin);
    $paused = ScoringFixtures::game();
    $played = ScoringFixtures::round($paused, 1, RoundStatus::Completed);
    $played->forceFill(['reveal_ends_at' => $origin->addSeconds(43)])->save();
    $paused->forceFill(['status' => GameStatus::Paused, 'paused_at' => $origin->addSeconds(200)])->save();

    expect(finalizeGameInstant(FinalizeGame::lastKnownActivity($paused, $now)))->toBe('2025-08-01 10:03:20.000');

    // Une fin de révélation programmée dans le futur est bornée par maintenant.
    $this->travelTo($now);
    $revealing = ScoringFixtures::game();
    $open = ScoringFixtures::round($revealing, 1, RoundStatus::Revealing);

    expect($open->reveal_ends_at?->greaterThan($now))->toBeTrue()
        ->and(finalizeGameInstant(FinalizeGame::lastKnownActivity($revealing, $now)))->toBe(finalizeGameInstant($now));

    // Sans aucune manche : le lancement.
    $bare = ScoringFixtures::game(state: ['started_at' => $now->subDays(3)]);

    expect(finalizeGameInstant(FinalizeGame::lastKnownActivity($bare, $now)))->toBe(finalizeGameInstant($now->subDays(3)));
});

test('le gel refuse une manche running dont la durée n’est pas écoulée', function (): void {
    Event::fake([GameFinalized::class]);
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $game = ScoringFixtures::game();
    $grace = $game->tier_grace_ms;
    $seat = ScoringFixtures::seat($game);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $seat, 1_700 + $grace);

    // La manche 2 court depuis 5 s sur ses 30.
    $running = ScoringFixtures::round($game, 2, RoundStatus::Running);
    $startedAt = CarbonImmutable::now()->subSeconds(5);
    $running->forceFill(['started_at' => $startedAt])->save();
    ScoringFixtures::find($running, $seat, 1_700 + $grace);

    $finalize = app(FinalizeGame::class);
    $before = finalizeGameRows($game);
    $due = $startedAt->addMilliseconds($running->duration_ms);

    expect(fn () => $finalize->handle($game, GameStatus::Interrupted, CarbonImmutable::now()))->toThrow(LogicException::class)
        // Une milliseconde avant la fin théorique : toujours refusé.
        ->and(fn () => $finalize->handle($game, GameStatus::Interrupted, $due->subMillisecond()))->toThrow(LogicException::class);

    // Rien n'est écrit : ni la partie, ni la manche, ni un agrégat.
    expect(finalizeGameRows($game))->toBe($before)
        ->and($running->refresh()->status)->toBe(RoundStatus::Running)
        ->and(finalizeGameAggregates($game, $seat)['rounds_played'])->toBeNull();
    Event::assertNotDispatched(GameFinalized::class);

    // À la fin théorique exacte, la manche se clôt d'office.
    expect($finalize->handle($game, GameStatus::Interrupted, $due))->toBeTrue()
        ->and($running->refresh()->status)->toBe(RoundStatus::Completed);
    Event::assertDispatchedTimes(GameFinalized::class, 1);
});

test('la clôture d\'office pose round.ended_at à started_at + duration_ms', function (): void {
    $now = CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC');
    $this->travelTo($now);
    $finalize = app(FinalizeGame::class);

    // Manche running, fin non posée : fin théorique à D, à la milliseconde.
    $game = ScoringFixtures::game();
    $running = ScoringFixtures::round($game, 1, RoundStatus::Running);
    $running->forceFill(['started_at' => $now->subMilliseconds(40_123)])->save();

    expect($running->ended_at)->toBeNull()
        ->and($finalize->handle($game, GameStatus::Interrupted, $now))->toBeTrue();

    $running->refresh();

    expect($running->status)->toBe(RoundStatus::Completed)
        ->and(finalizeGameInstant($running->ended_at))->toBe(finalizeGameInstant($now->subMilliseconds(40_123 - $running->duration_ms)))
        ->and($running->reveal_ends_at)->toBeNull();

    // Manche running déjà close (fin anticipée, révélation en attente) : sa
    // fin est conservée.
    $early = ScoringFixtures::game();
    $closed = ScoringFixtures::round($early, 1, RoundStatus::Running);
    $closed->forceFill([
        'started_at' => $now->subSeconds(40),
        'ended_at' => $now->subMilliseconds(27_500),
    ])->save();

    expect($finalize->handle($early, GameStatus::Interrupted, $now))->toBeTrue()
        ->and($closed->refresh()->status)->toBe(RoundStatus::Completed)
        ->and(finalizeGameInstant($closed->ended_at))->toBe(finalizeGameInstant($now->subMilliseconds(27_500)));

    // Manche en révélation : passe completed, ses instants inchangés.
    $revealGame = ScoringFixtures::game();
    $revealing = ScoringFixtures::round($revealGame, 1, RoundStatus::Revealing);
    $endedBefore = finalizeGameInstant($revealing->ended_at);
    $revealEndsBefore = finalizeGameInstant($revealing->reveal_ends_at);

    expect($finalize->handle($revealGame, GameStatus::Completed, $now))->toBeTrue();

    $revealing->refresh();

    expect($revealing->status)->toBe(RoundStatus::Completed)
        ->and(finalizeGameInstant($revealing->ended_at))->toBe($endedBefore)
        ->and(finalizeGameInstant($revealing->reveal_ends_at))->toBe($revealEndsBefore);
});

test('une partie terminée sur une annulation sans manche restante est gelée completed avec rounds_completed inférieur à M', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));
    $finalize = app(FinalizeGame::class);

    $game = ScoringFixtures::game(finalizeGameShortSettings());
    $grace = $game->tier_grace_ms;
    $a = ScoringFixtures::seat($game);
    $b = ScoringFixtures::seat($game);

    $first = ScoringFixtures::round($game, 1, RoundStatus::Completed);
    ScoringFixtures::find($first, $a, 1_700 + $grace);   // 424
    ScoringFixtures::played($first, $b);

    $second = ScoringFixtures::round($game, 2, RoundStatus::Completed);
    ScoringFixtures::find($second, $b, 2_000 + $grace);  // 420
    ScoringFixtures::played($second, $a);

    // La dernière manche est annulée après une bonne réponse ; vivier égal à
    // M, aucune réserve : rien ne la remplace.
    $last = ScoringFixtures::round($game, 3, RoundStatus::Running);
    ScoringFixtures::find($last, $b, 3_300 + $grace);    // 400, jamais compté
    ScoringFixtures::played($last, $a);
    $this->travel(12)->seconds();
    ScoringFixtures::moveTo($last, RoundStatus::Cancelled);

    $cancelledAt = $last->refresh()->cancelled_at;

    expect($cancelledAt)->not->toBeNull();
    expect($finalize->handle($game, GameStatus::Completed, $cancelledAt))->toBeTrue();

    $stored = Game::query()->findOrFail($game->id);

    expect($stored->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameInstant($stored->ended_at))->toBe(finalizeGameInstant($cancelledAt))
        ->and($stored->rounds_completed)->toBe(2)
        ->and($stored->rounds_count)->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT)
        ->and($stored->rounds_completed)->toBeLessThan($stored->rounds_count)
        ->and($last->status)->toBe(RoundStatus::Cancelled);

    expect(finalizeGameAggregates($game, $a))->toBe(finalizeGameExpected(2, 1, 424, 1_700 + $grace, 1))
        ->and(finalizeGameAggregates($game, $b))->toBe(finalizeGameExpected(2, 1, 420, 2_000 + $grace, 2));

    // Toutes les manches annulées : terminée à la manche 0, sans aucun rang.
    $void = ScoringFixtures::game(finalizeGameShortSettings());
    $seat = ScoringFixtures::seat($void);

    foreach (range(1, RoomSettingsBounds::MIN_ROUNDS_COUNT) as $roundNumber) {
        $round = ScoringFixtures::round($void, $roundNumber, RoundStatus::Running);
        ScoringFixtures::find($round, $seat, 1_700 + $grace);
        ScoringFixtures::moveTo($round, RoundStatus::Cancelled);
    }

    expect($finalize->handle($void, GameStatus::Completed, CarbonImmutable::now()))->toBeTrue()
        ->and(Game::query()->findOrFail($void->id)->rounds_completed)->toBe(0)
        ->and(Game::query()->findOrFail($void->id)->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameAggregates($void, $seat))->toBe(finalizeGameExpected(0, 0, 0, 0, null));
});

// Ajout (hors intitulés de 80 § 16) : la garde d'issue, avant toute lecture.
test('le gel refuse une issue qui n’est ni completed ni interrupted, avant toute lecture', function (): void {
    Event::fake([GameFinalized::class]);

    $game = ScoringFixtures::game();
    ScoringFixtures::seat($game);
    $finalize = app(FinalizeGame::class);
    $queries = 0;

    DB::listen(static function () use (&$queries): void {
        $queries++;
    });

    foreach ([GameStatus::Running, GameStatus::Paused] as $outcome) {
        expect(fn () => $finalize->handle($game, $outcome, CarbonImmutable::now()))
            ->toThrow(InvalidArgumentException::class, $outcome->value);
    }

    expect($queries)->toBe(0)
        ->and(Game::query()->findOrFail($game->id)->ended_at)->toBeNull();
    Event::assertNotDispatched(GameFinalized::class);
});

// Ajout (hors intitulés de 80 § 16) : l'état de fabrique qui gèle par l'action.
test('la fabrique finalized() gèle la partie par l’action de gel, completed ou interrupted', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-26 12:00:00.000', 'UTC'));

    $completed = Game::factory()->has(GamePlayer::factory()->count(2))->finalized()->create();
    $interrupted = Game::factory()->has(GamePlayer::factory()->count(1))->finalized(GameStatus::Interrupted)->create();

    expect($completed->status)->toBe(GameStatus::Completed)
        ->and(Game::query()->findOrFail($completed->id)->status)->toBe(GameStatus::Completed)
        ->and(finalizeGameInstant(Game::query()->findOrFail($completed->id)->ended_at))->toBe(finalizeGameInstant($completed->started_at))
        ->and(Game::query()->findOrFail($interrupted->id)->status)->toBe(GameStatus::Interrupted);

    // Aucune manche jouée : agrégats gelés à zéro, jamais nuls, et aucun rang.
    $rows = GamePlayer::query()->whereIn('game_id', [$completed->id, $interrupted->id])->get();

    expect($rows)->toHaveCount(3);

    foreach ($rows as $row) {
        expect([$row->rounds_played, $row->correct_answers, $row->final_score, $row->total_answer_time_ms, $row->final_rank])
            ->toBe([0, 0, 0, 0, null]);
    }
});
