<?php

use App\Enums\GameStatus;
use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Enums\RoomStatus;
use App\Jobs\Retention\RunRetentionPurge;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\PurgeRun;
use App\Models\Room;
use App\Support\Ops\Heartbeat;
use App\Support\Realtime\ChannelNames;
use App\Support\Retention\PurgeHandlers;
use App\Support\Retention\RetentionPurger;
use App\Support\Retention\RetentionWindows;
use App\Support\Room\RoomExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Retention\FailingPurgeHandler;
use Tests\Support\Retention\FakePurgeHandler;
use Tests\Support\Retention\RetentionRows;

/*
|--------------------------------------------------------------------------
| Moteur de purge de rétention — spec 100 § 14, 10 § 11
|--------------------------------------------------------------------------
|
| Livré en temps successifs (D37 du 23/09) : les périmètres sans jeu
| (`framework_sessions`, `framework_failed_jobs`, `framework_reset_tokens`,
| `purge_run`), puis `stale_room`, qui archive par l'action de 50 (L50-8),
| puis la branche sièges solo d'`orphan_player`, qui efface les
| identifiants d'un siège solo (L60-15).
|
| Horloge figée à l'heure de la purge quotidienne ; aucune donnée réelle
| ({@see RetentionRows}).
|
*/

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 02:10:00', 'UTC'));
});

/**
 * Une exécution du moteur, ses lignes `purge_run` indexées par périmètre.
 *
 * @return array<string, PurgeRun>
 */
function retentionPurgeRun(): array
{
    $runs = [];

    foreach (app(RetentionPurger::class)->run() as $run) {
        $runs[$run->scope->value] = $run;
    }

    return $runs;
}

/**
 * Un salon non archivé, dernière activité à `$lastActivityAt`, lancé une fois
 * ou jamais.
 */
function retentionPurgeRoom(CarbonImmutable $lastActivityAt, bool $launched = false): Room
{
    return Room::factory()->create([
        'launched_at' => $launched ? $lastActivityAt->subHour() : null,
        'last_activity_at' => $lastActivityAt,
    ]);
}

/**
 * L'hôte et un invité du salon, pseudos et empreintes de jeton posés.
 *
 * @return list<Player>
 */
function retentionPurgeSeats(Room $room): array
{
    $seats = array_values(Player::factory()->for($room)->count(2)->create()->all());

    Room::query()->whereKey($room->id)->update(['host_player_id' => $seats[0]->id]);
    $room->refresh();

    return $seats;
}

/**
 * Les identifiants d'invité d'un siège, bruts.
 *
 * @return array{nickname: mixed, nickname_normalized: mixed, player_token_hash: mixed}
 */
function retentionPurgeIdentity(Player $seat): array
{
    $row = DB::table('player')->where('id', $seat->id)->first(['nickname', 'nickname_normalized', 'player_token_hash']);

    expect($row)->not->toBeNull();

    return [
        'nickname' => $row?->nickname,
        'nickname_normalized' => $row?->nickname_normalized,
        'player_token_hash' => $row?->player_token_hash,
    ];
}

/**
 * Le nombre de lignes des tables qu'un archivage touche sans jamais y
 * supprimer.
 *
 * @return array<string, int>
 */
function retentionPurgeRoomCounts(): array
{
    return [
        'room' => DB::table('room')->count(),
        'player' => DB::table('player')->count(),
        'game' => DB::table('game')->count(),
        'game_player' => DB::table('game_player')->count(),
    ];
}

/**
 * Les clés des requêtes de suppression d'une table, dans l'ordre où elles
 * partent : la clé est la dernière valeur liée (`… and "id" = ?`).
 *
 * @return ArrayObject<int, mixed>
 */
function retentionPurgeWatchDeletes(string $table): ArrayObject
{
    $deleted = new ArrayObject;

    DB::listen(static function (QueryExecuted $query) use ($table, $deleted): void {
        if (preg_match('/^delete from ["`]'.preg_quote($table, '/').'["`]/i', $query->sql) === 1) {
            $bindings = $query->bindings;
            $deleted[] = end($bindings);
        }
    });

    return $deleted;
}

it('supprime par lots bornés, du plus ancien au plus récent', function (): void {
    config(['ops.purge.batch_size' => 2, 'ops.purge.max_batches' => 2]);

    $now = CarbonImmutable::now();
    $cutoff = RetentionRows::cutoff(PurgeScope::FrameworkSessions, $now);

    // Cinq sessions échues, insérées dans le désordre, les clés à rebours de
    // l'âge : l'ordre ne peut venir que de la colonne pilote.
    $minutesPastCutoff = ['seed-a' => 5, 'seed-e' => 400, 'seed-c' => 90, 'seed-b' => 30, 'seed-d' => 250];

    foreach ($minutesPastCutoff as $id => $minutes) {
        RetentionRows::session($id, $cutoff->subMinutes($minutes));
    }

    RetentionRows::session('seed-active', $now);

    $deleted = retentionPurgeWatchDeletes('sessions');
    $selects = new ArrayObject;
    $watching = true;

    DB::listen(static function (QueryExecuted $query) use ($selects, &$watching): void {
        if ($watching && preg_match('/^select .* from ["`]sessions["`]/i', $query->sql) === 1) {
            $selects[] = $query->sql;
        }
    });

    $first = retentionPurgeRun()[PurgeScope::FrameworkSessions->value];
    $watching = false;

    // Deux lots de deux, les quatre plus anciennes, dans l'ordre de l'âge.
    expect($deleted->getArrayCopy())->toBe(['seed-e', 'seed-d', 'seed-c', 'seed-b'])
        ->and($first->status)->toBe(PurgeRunStatus::Completed)
        ->and($first->rows_deleted)->toBe(4)
        ->and($first->batches)->toBe(2)
        ->and(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['seed-a', 'seed-active']);

    // Chaque sélection est bornée à la taille du lot, jamais un balayage entier.
    expect($selects->getArrayCopy())->not->toBeEmpty();

    foreach ($selects as $sql) {
        expect($sql)->toMatch('/ limit 2$/i');
    }

    // La nuit suivante reprend là où la borne de lots s'est arrêtée.
    $deleted->exchangeArray([]);
    $second = retentionPurgeRun()[PurgeScope::FrameworkSessions->value];

    expect($deleted->getArrayCopy())->toBe(['seed-a'])
        ->and($second->rows_deleted)->toBe(1)
        ->and($second->batches)->toBe(1)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['seed-active']);
});

it('écrit une ligne purge_run par périmètre et par exécution, même sans ligne éligible', function (): void {
    $implemented = PurgeScope::implemented();

    $firstAt = CarbonImmutable::now();
    $first = app(RetentionPurger::class)->run();

    $this->travel(1)->day();
    $secondAt = CarbonImmutable::now();
    $second = app(RetentionPurger::class)->run();

    // Dans l'ordre du tableau de 10, un périmètre par ligne.
    expect(array_map(static fn (PurgeRun $run): PurgeScope => $run->scope, $first))->toBe($implemented)
        ->and(array_map(static fn (PurgeRun $run): PurgeScope => $run->scope, $second))->toBe($implemented);

    // Ce que la base garde : exactement une ligne par (exécution, périmètre).
    $stored = PurgeRun::query()->orderBy('id')->get();

    expect($stored)->toHaveCount(2 * count($implemented));

    foreach ([$firstAt, $secondAt] as $ranAt) {
        $rows = $stored->filter(static fn (PurgeRun $run): bool => $run->ran_at->equalTo($ranAt));

        expect($rows->map(static fn (PurgeRun $run): string => $run->scope->value)->values()->all())
            ->toBe(array_map(static fn (PurgeScope $scope): string => $scope->value, $implemented));

        foreach ($rows as $run) {
            // Rien d'éligible : zéro est une information, pas une absence.
            expect($run->status)->toBe(PurgeRunStatus::Completed)
                ->and($run->rows_deleted)->toBe(0)
                ->and($run->batches)->toBe(0)
                ->and($run->error)->toBeNull()
                ->and($run->started_at->equalTo($ranAt))->toBeTrue()
                ->and($run->finished_at?->equalTo($ranAt))->toBeTrue()
                ->and($run->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
        }
    }
});

it('passe à la ligne suivante quand une ligne échoue, sans annuler le lot', function (): void {
    config(['ops.purge.batch_size' => 2, 'ops.purge.max_batches' => 10]);

    $now = CarbonImmutable::now();
    $cutoff = RetentionRows::cutoff(PurgeScope::FrameworkResetTokens, $now);

    // Cinq jetons échus, du plus ancien (1) au plus récent (5).
    foreach ([1 => 50, 2 => 40, 3 => 30, 4 => 20, 5 => 10] as $rank => $minutes) {
        RetentionRows::resetToken("seed-{$rank}@example.com", $cutoff->subMinutes($minutes));
    }

    // Le premier et le quatrième échouent APRÈS leur suppression, comme un
    // `restrict` vérifié en aval dans la même transaction.
    FailingPurgeHandler::wrap(PurgeScope::FrameworkResetTokens, ['seed-1@example.com', 'seed-4@example.com']);

    Log::spy();

    $runs = retentionPurgeRun();
    $tokens = $runs[PurgeScope::FrameworkResetTokens->value];

    // Lots [1, 2], [3, 4], [5] : chaque voisin d'une ligne en échec est
    // supprimé, et la suppression de la ligne en échec est annulée — une
    // transaction par ligne, jamais le lot entier.
    expect(DB::table('password_reset_tokens')->orderBy('email')->pluck('email')->all())
        ->toBe(['seed-1@example.com', 'seed-4@example.com'])
        ->and($tokens->status)->toBe(PurgeRunStatus::Completed)
        ->and($tokens->rows_deleted)->toBe(3)
        ->and($tokens->batches)->toBe(3)
        ->and($tokens->error)->toContain('2 ligne(s) en échec')
        ->and($tokens->error)->toContain(QueryException::class);

    // La clé d'une ligne (ici une adresse électronique) ne quitte jamais le
    // processus : ni `purge_run`, ni le journal.
    expect($tokens->error)->not->toContain('@');

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context = []): bool => ($context['failures'] ?? null) === 2
            && ! str_contains((string) json_encode($context), '@'))
        ->once();

    // Les autres périmètres ne sont pas touchés par l'échec.
    foreach ($runs as $scope => $run) {
        expect($run->status)->toBe(PurgeRunStatus::Completed, $scope);
    }

    // La nuit suivante bute encore sur les mêmes lignes, sans jamais les
    // resélectionner dans la même exécution : un seul lot, puis la fin.
    $again = retentionPurgeRun()[PurgeScope::FrameworkResetTokens->value];

    expect($again->rows_deleted)->toBe(0)
        ->and($again->batches)->toBe(1)
        ->and($again->error)->toContain('2 ligne(s) en échec');
});

it('part sur la file default et jamais sur la file game', function (): void {
    $job = new RunRetentionPurge;

    expect(RunRetentionPurge::QUEUE)->toBe(Heartbeat::DEFAULT)->not->toBe(Heartbeat::GAME)
        ->and($job)->toBeInstanceOf(ShouldQueue::class)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe(RunRetentionPurge::QUEUE)
        ->and($job->connection)->toBeNull();

    // Planifiée chaque jour à `ops.purge.daily_at`, en UTC.
    Artisan::call('list');

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn (Event $event): bool => $event instanceof CallbackEvent
            && $event->description === RunRetentionPurge::class,
    ));

    expect($events)->toHaveCount(1)
        ->and(config('ops.purge.daily_at'))->toBe('02:10')
        ->and($events[0]->expression)->toBe('10 2 * * *')
        ->and(config('app.timezone'))->toBe('UTC');

    // Le planificateur la dépose sur la file `default` de la connexion par
    // défaut, jamais `sync` : elle doit être dépilée par le worker `default`.
    Queue::fake();
    $events[0]->run(app());

    Queue::assertPushedOn(RunRetentionPurge::QUEUE, RunRetentionPurge::class, static fn (RunRetentionPurge $pushed): bool => $pushed->connection === null);
    Queue::assertNotPushed(RunRetentionPurge::class, static fn (RunRetentionPurge $pushed, ?string $queue): bool => $queue === Heartbeat::GAME);

    // `purge:run` sans `--sync` dépose le même job, sur la même file (un
    // nouveau `Queue::fake()` lève le verrou d'unicité du précédent).
    Queue::fake();

    $this->artisan('purge:run')
        ->expectsOutputToContain((string) trans('admin.console.purge.queued', [], 'fr'))
        ->assertSuccessful();

    Queue::assertPushedOn(RunRetentionPurge::QUEUE, RunRetentionPurge::class);
    Queue::assertNotPushed(RunRetentionPurge::class, static fn (RunRetentionPurge $pushed, ?string $queue): bool => $queue === Heartbeat::GAME);

    // Unique : une seconde dépose tant que la première attend ne part pas,
    // et `purge:run` le dit au lieu d'annoncer une purge qui ne partira pas.
    RunRetentionPurge::dispatch();

    $this->artisan('purge:run')
        ->expectsOutputToContain((string) trans('admin.console.purge.already_queued', [], 'fr'))
        ->doesntExpectOutputToContain((string) trans('admin.console.purge.queued', [], 'fr'))
        ->assertFailed();

    Queue::assertPushed(RunRetentionPurge::class, 1);
});

it('n\'exécute que les périmètres implémentés, chacun par un seul gestionnaire', function (): void {
    $implemented = PurgeScope::implemented();
    $values = array_map(static fn (PurgeScope $scope): string => $scope->value, $implemented);

    // La liste est déclarée dans le code, sans doublon, dans l'ordre du
    // tableau de 10 — l'ordre des cas de l'énumération.
    $table = array_map(static fn (PurgeScope $scope): string => $scope->value, PurgeScope::cases());
    $positions = array_map(static fn (string $value): int|false => array_search($value, $table, true), $values);
    $sorted = $positions;
    sort($sorted);

    expect($implemented)->not->toBeEmpty()
        ->and(array_unique($values))->toBe($values)
        ->and($positions)->toBe($sorted);

    // Chaque périmètre implémenté a exactement un gestionnaire, et chaque
    // gestionnaire déclaré sert un périmètre implémenté.
    $byScope = app(PurgeHandlers::class)->byScope();

    expect(array_keys($byScope))->toEqualCanonicalizing($values)
        ->and(PurgeHandlers::CLASSES)->toHaveCount(count($implemented));

    foreach ($byScope as $scope => $handlers) {
        expect($handlers)->toHaveCount(1, $scope);
    }

    // Un gestionnaire étiqueté pour un périmètre non implémenté n'est jamais
    // appelé, même s'il annonce des lignes éligibles.
    $undeclared = FakePurgeHandler::alongside(PurgeScope::Report, eligible: 5);

    // Un second gestionnaire pour un périmètre implémenté : le périmètre ne
    // s'exécute par aucun des deux, et sa ligne le dit.
    RetentionRows::session('seed-duplicated', RetentionRows::cutoff(PurgeScope::FrameworkSessions, CarbonImmutable::now())->subHour());
    $duplicate = FakePurgeHandler::alongside(PurgeScope::FrameworkSessions, eligible: 1);

    $runs = retentionPurgeRun();

    expect(array_keys($runs))->toBe($values)
        ->and($undeclared->wasExecuted())->toBeFalse()
        ->and($undeclared->askedAsOf)->toBeNull()
        ->and(PurgeRun::query()->where('scope', PurgeScope::Report->value)->exists())->toBeFalse();

    $sessions = $runs[PurgeScope::FrameworkSessions->value];

    expect($sessions->status)->toBe(PurgeRunStatus::Failed)
        ->and($sessions->error)->toContain('2 gestionnaires')
        ->and($sessions->rows_deleted)->toBe(0)
        ->and($sessions->finished_at)->toBeNull()
        ->and($duplicate->wasExecuted())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'seed-duplicated')->exists())->toBeTrue();

    foreach ($runs as $scope => $run) {
        if ($scope !== PurgeScope::FrameworkSessions->value) {
            expect($run->status)->toBe(PurgeRunStatus::Completed, $scope);
        }
    }
});

it('n\'exécute jamais stale_lobby, confié au balayage de 50', function (): void {
    expect(PurgeScope::implemented())->not->toContain(PurgeScope::StaleLobby)
        ->and(app(PurgeHandlers::class)->byScope())->not->toHaveKey(PurgeScope::StaleLobby->value);

    // Un gestionnaire `stale_lobby` étiqueté par erreur n'est pas exécuté pour
    // autant, et la ligne du dernier passage du balayage de 50 reste la seule.
    $lobby = FakePurgeHandler::alongside(PurgeScope::StaleLobby, eligible: 3);

    $sweep = PurgeRun::factory()->forScope(PurgeScope::StaleLobby)->deletedNothing()->create([
        'started_at' => CarbonImmutable::now()->subMinutes(10),
        'finished_at' => CarbonImmutable::now()->subMinutes(10),
        'ran_at' => CarbonImmutable::now()->subMinutes(10),
    ]);

    retentionPurgeRun();
    $this->artisan('purge:run', ['--sync' => true])->assertSuccessful();

    expect($lobby->wasExecuted())->toBeFalse()
        ->and(PurgeRun::query()->where('scope', PurgeScope::StaleLobby->value)->pluck('id')->all())->toBe([$sweep->id])
        ->and(PurgeRun::query()->count())->toBe(1 + 2 * count(PurgeScope::implemented()));
});

it('archive un salon oublié depuis 48 h par l\'action d\'archivage de 50, jamais par suppression', function (): void {
    // Un salon par lot : le curseur doit dépasser les salons que l'action
    // refuse, sans jamais les resélectionner.
    config(['ops.purge.batch_size' => 1]);

    $recorder = RecordingBroadcaster::install();
    $now = CarbonImmutable::now();
    $cutoff = RetentionRows::cutoff(PurgeScope::StaleRoom, $now);

    // Le balayage de 50 n'a jamais tourné. Un salon lancé une fois, revenu au
    // lobby, oublié une seconde au-delà du filet : hôte, invité et une partie
    // figée dont les participations gardent leurs pseudos.
    $forgotten = retentionPurgeRoom($cutoff->subSecond(), launched: true);
    $forgottenSeats = retentionPurgeSeats($forgotten);
    $game = Game::factory()->forRoom($forgotten)->completed()->create(['started_at' => $cutoff->subHours(3)]);

    foreach ($forgottenSeats as $seat) {
        GamePlayer::factory()->for($game)->frozenFrom($seat)->create();
    }

    // Un lobby jamais lancé, oublié depuis des jours : le filet le prend aussi.
    $lobby = retentionPurgeRoom($cutoff->subDays(3));
    $lobbySeats = retentionPurgeSeats($lobby);

    // Conservés : à la borne exacte (stricte), récent, déjà archivé.
    $edge = retentionPurgeRoom($cutoff);
    $edgeSeats = retentionPurgeSeats($edge);
    $recent = retentionPurgeRoom($now->subHour());
    $archivedAt = $now->subDays(9);
    $archived = Room::factory()->archived()->create(['last_activity_at' => $now->subDays(10), 'archived_at' => $archivedAt]);

    // Refusés par l'action, qui relit tout sous le verrou du salon : une
    // dernière partie qui n'est pas figée (cas défensif, journalisé), et un
    // salon qui reprend vie entre la sélection du lot et le verrou.
    $unfinished = retentionPurgeRoom($cutoff->subHour(), launched: true);
    Game::factory()->forRoom($unfinished)->create(['started_at' => $cutoff->subHours(2), 'ended_at' => null]);
    $revived = retentionPurgeRoom($cutoff->subHours(2));
    $revivedSeats = retentionPurgeSeats($revived);

    $handler = FakePurgeHandler::declared(PurgeScope::StaleRoom);

    expect($handler->eligibleCount())->toBe(4);

    $reviving = true;
    DB::beforeExecuting(static function (string $query, array $bindings) use (&$reviving, $revived, $now): void {
        if ($reviving
            && preg_match('/^select \* from ["`]room["`] where ["`]room["`]\.["`]id["`] = \? limit 1/i', $query) === 1
            && $bindings === [$revived->id]) {
            $reviving = false;
            DB::table('room')->where('id', $revived->id)->update(['last_activity_at' => $now]);
        }
    });

    $counts = retentionPurgeRoomCounts();
    $deletes = new ArrayObject;
    DB::listen(static function (QueryExecuted $query) use ($deletes): void {
        if (preg_match('/^delete from ["`](room|player|game|game_player)["`]/i', $query->sql, $match) === 1) {
            $deletes[] = $match[1];
        }
    });
    Log::spy();

    $run = retentionPurgeRun()[PurgeScope::StaleRoom->value];

    // Le périmètre a tourné jusqu'au bout, un lot par salon éligible : deux
    // salons archivés, et les refus de l'action ne sont pas des échecs.
    expect($reviving)->toBeFalse()
        ->and($run->status)->toBe(PurgeRunStatus::Completed)
        ->and($run->rows_deleted)->toBe(2)
        ->and($run->batches)->toBe(4)
        ->and($run->error)->toBeNull();

    // Archivés par l'action de 50 : code actif libéré, hôte vidé, pseudos,
    // formes normalisées, empreintes de jeton et pseudos figés effacés.
    foreach ([$forgotten, $lobby] as $room) {
        $room->refresh();

        expect($room->status)->toBe(RoomStatus::Archived)
            ->and($room->archived_at?->equalTo($now))->toBeTrue()
            ->and($room->room_code_active)->toBeNull()
            ->and($room->host_player_id)->toBeNull();
    }

    foreach ([...$forgottenSeats, ...$lobbySeats] as $seat) {
        expect(retentionPurgeIdentity($seat))->toBe(['nickname' => null, 'nickname_normalized' => null, 'player_token_hash' => null]);
    }

    expect(GamePlayer::query()->where('game_id', $game->id)->count())->toBe(2)
        ->and(GamePlayer::query()->where('game_id', $game->id)->whereNotNull('display_nickname')->exists())->toBeFalse();

    // Après validation, `room.archived` pour chacun d'eux, et pour eux seuls.
    expect(array_column($recorder->sent, 'event'))->toBe(['room.archived', 'room.archived'])
        ->and(array_column($recorder->sent, 'channels'))->toEqualCanonicalizing([
            ['presence-'.ChannelNames::room($forgotten)],
            ['presence-'.ChannelNames::room($lobby)],
        ]);

    // Les autres, intacts ; le salon déjà archivé ne l'est pas une seconde fois.
    foreach ([$edge, $recent, $unfinished, $revived] as $room) {
        $room->refresh();

        expect($room->archived_at)->toBeNull()
            ->and($room->status)->not->toBe(RoomStatus::Archived)
            ->and($room->room_code_active)->toBe($room->room_code);
    }

    foreach ([...$edgeSeats, ...$revivedSeats] as $seat) {
        expect(retentionPurgeIdentity($seat)['player_token_hash'])->not->toBeNull();
    }

    expect($archived->refresh()->archived_at?->equalTo($archivedAt))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context = []): bool => ($context['room_id'] ?? null) === $unfinished->id)
        ->once();

    // Jamais par suppression : aucune requête de suppression sur ces tables,
    // aucune ligne en moins.
    expect($deletes->getArrayCopy())->toBe([])
        ->and(retentionPurgeRoomCounts())->toBe($counts);

    // Le refus défensif laisse le salon éligible, ce que la sonde voit ; celui
    // qui a repris vie ne l'est plus.
    expect($handler->eligibleCount())->toBe(1);

    // Une seconde plus tard, le salon resté à la borne est échu à son tour ;
    // ceux déjà archivés ne sont pas repris.
    $this->travel(1)->seconds();
    $recorder->sent = [];

    $next = retentionPurgeRun()[PurgeScope::StaleRoom->value];

    expect($next->rows_deleted)->toBe(1)
        ->and($next->batches)->toBe(2)
        ->and($edge->refresh()->status)->toBe(RoomStatus::Archived)
        ->and($forgotten->refresh()->archived_at?->equalTo($now))->toBeTrue()
        ->and(array_column($recorder->sent, 'channels'))->toBe([['presence-'.ChannelNames::room($edge)]])
        ->and($recent->refresh()->archived_at)->toBeNull()
        ->and($deletes->getArrayCopy())->toBe([])
        ->and(retentionPurgeRoomCounts())->toBe($counts);
});

/**
 * Les identifiants d'invité d'un siège, bruts : les quatre colonnes de
 * `player` que l'effacement d'un siège solo vide, et les pseudos figés de
 * ses participations.
 *
 * @return array{nickname: mixed, nickname_normalized: mixed, player_token_hash: mixed, solo_token_hash: mixed, display_nicknames: list<mixed>}
 */
function retentionPurgeSoloIdentity(Player $seat): array
{
    $row = DB::table('player')->where('id', $seat->id)->first(['nickname', 'nickname_normalized', 'player_token_hash', 'solo_token_hash']);

    expect($row)->not->toBeNull();

    return [
        'nickname' => $row?->nickname,
        'nickname_normalized' => $row?->nickname_normalized,
        'player_token_hash' => $row?->player_token_hash,
        'solo_token_hash' => $row?->solo_token_hash,
        'display_nicknames' => DB::table('game_player')->where('player_id', $seat->id)->orderBy('id')->pluck('display_nickname')->all(),
    ];
}

/**
 * Tout ce que portent un siège et ses participations HORS des identifiants
 * que l'effacement vide et des `updated_at` qu'il pose : ce qui ne change
 * jamais, `last_seen_at` compris.
 *
 * @return array{seat: array<string, mixed>, participations: list<array<string, mixed>>}
 */
function retentionPurgeSoloRest(Player $seat): array
{
    $strip = static fn (object $row, array $columns): array => array_diff_key((array) $row, array_flip($columns));
    $row = DB::table('player')->where('id', $seat->id)->first();

    expect($row)->not->toBeNull();

    return [
        'seat' => $strip((object) $row, ['nickname', 'nickname_normalized', 'player_token_hash', 'solo_token_hash', 'updated_at']),
        'participations' => DB::table('game_player')->where('player_id', $seat->id)->orderBy('id')->get()
            ->map(static fn (object $participation): array => $strip($participation, ['display_nickname', 'updated_at']))
            ->values()
            ->all(),
    ];
}

it('efface pseudo, forme normalisée du pseudo, empreinte du jeton et pseudo figé d\'un siège solo inactif depuis 24 h, dans une transaction', function (): void {
    // Un siège par lot : le curseur doit dépasser les sièges qui échouent ou
    // ne sont plus éligibles, sans jamais les resélectionner.
    config(['ops.purge.batch_size' => 1]);

    // 24 h, la durée des identifiants d'invité d'un siège de salon (« Idem »
    // de 10 § 11.1), lue chez 50 et jamais recopiée.
    expect(RetentionWindows::SOLO_SEAT_IDLE_MINUTES)->toBe(24 * 60)
        ->and(RetentionWindows::SOLO_SEAT_IDLE_MINUTES)->toBe(RoomExpiry::ROOM_IDLE_MINUTES);

    $now = CarbonImmutable::now();
    $cutoff = RetentionRows::cutoff(PurgeScope::OrphanPlayer, $now);

    // Échus, chacun tel que le démarrage solo l'écrit : un siège inactif une
    // seconde au-delà de la fenêtre, avec une seconde partie solo figée, et un
    // siège oublié depuis un mois.
    $idle = RetentionRows::soloSeat($cutoff->subSecond());
    $interrupted = Game::factory()->solo()->interrupted()->create([
        'started_at' => $cutoff->subHours(3),
        'ended_at' => $cutoff->subHours(2),
    ]);
    GamePlayer::factory()->for($interrupted)->frozenFrom($idle)->create();
    $forgotten = RetentionRows::soloSeat($cutoff->subMonth());

    // Conservés : à la borne exacte (stricte), récent, et le siège inactif
    // d'un salon encore actif, que seul l'archivage de 50 efface.
    $edge = RetentionRows::soloSeat($cutoff);
    $recent = RetentionRows::soloSeat($now->subMinute());
    $roomSeat = Player::factory()
        ->for(Room::factory()->create(['last_activity_at' => $now->subHour()]))
        ->create(['joined_at' => $cutoff->subDays(2), 'last_seen_at' => $cutoff->subDays(2)]);

    // Déjà effacé : plus rien à effacer, donc plus éligible.
    Player::factory()->solo()->archivedIdentity()->create([
        'joined_at' => $cutoff->subDays(60),
        'last_seen_at' => $cutoff->subDays(60),
    ]);

    // Relu sous le verrou du siège : un siège qui reprend vie entre la
    // sélection du lot et le verrou n'est pas effacé.
    $revived = RetentionRows::soloSeat($cutoff->subHours(2));

    // Dans une transaction : l'effacement de l'un échoue sur sa ligne
    // `player`, celui de l'autre sur ses pseudos figés. Quel que soit l'ordre
    // des deux écritures, l'une est faite quand l'autre échoue : rien ne doit
    // en rester.
    $failsOnSeat = RetentionRows::soloSeat($cutoff->subHours(4));
    $failsOnFrozen = RetentionRows::soloSeat($cutoff->subHours(5));

    $handler = FakePurgeHandler::declared(PurgeScope::OrphanPlayer);

    expect($handler->eligibleCount())->toBe(5);

    $identities = [];
    $rests = [];

    foreach ([$edge, $recent, $roomSeat, $revived, $failsOnSeat, $failsOnFrozen] as $seat) {
        $identities[$seat->id] = retentionPurgeSoloIdentity($seat);
    }

    foreach ([$idle, $forgotten, $edge, $recent, $roomSeat, $failsOnSeat, $failsOnFrozen] as $seat) {
        $rests[$seat->id] = retentionPurgeSoloRest($seat);
    }

    $reviving = true;
    $failing = true;

    DB::beforeExecuting(static function (string $query, array $bindings) use (&$reviving, &$failing, $revived, $failsOnSeat, $failsOnFrozen, $now): void {
        if ($reviving
            && preg_match('/^select ["`]id["`] from ["`]player["`] where /i', $query) === 1
            && in_array($revived->id, $bindings, true)) {
            $reviving = false;
            DB::table('player')->where('id', $revived->id)->update(['last_seen_at' => $now->format('Y-m-d H:i:s.v')]);
        }

        if (! $failing) {
            return;
        }

        foreach (['player' => $failsOnSeat->id, 'game_player' => $failsOnFrozen->id] as $table => $seatId) {
            if (preg_match('/^update ["`]'.$table.'["`] /i', $query) === 1 && in_array($seatId, $bindings, true)) {
                throw new QueryException('testing', $query, $bindings, new PDOException('écriture refusée', 23000));
            }
        }
    });

    $counts = retentionPurgeRoomCounts();
    $deletes = new ArrayObject;
    DB::listen(static function (QueryExecuted $query) use ($deletes): void {
        if (preg_match('/^delete from ["`](room|player|game|game_player)["`]/i', $query->sql, $match) === 1) {
            $deletes[] = $match[1];
        }
    });

    $run = retentionPurgeRun()[PurgeScope::OrphanPlayer->value];

    // Le périmètre a tourné jusqu'au bout, un lot par siège éligible : deux
    // sièges effacés, deux lignes en échec comptées, jamais le lot annulé.
    expect($reviving)->toBeFalse()
        ->and($run->status)->toBe(PurgeRunStatus::Completed)
        ->and($run->rows_deleted)->toBe(2)
        ->and($run->batches)->toBe(5)
        ->and($run->error)->toContain('2 ligne(s) en échec')
        ->and($run->error)->toContain(QueryException::class);

    // Effacés : pseudo, forme normalisée, empreinte du jeton et son créneau
    // d'unicité, et le pseudo figé de chacune des parties du siège.
    expect(retentionPurgeSoloIdentity($idle))->toBe([
        'nickname' => null,
        'nickname_normalized' => null,
        'player_token_hash' => null,
        'solo_token_hash' => null,
        'display_nicknames' => [null, null],
    ])->and(retentionPurgeSoloIdentity($forgotten))->toBe([
        'nickname' => null,
        'nickname_normalized' => null,
        'player_token_hash' => null,
        'solo_token_hash' => null,
        'display_nicknames' => [null],
    ]);

    // Dans une transaction : un effacement qui échoue en cours de route ne
    // laisse rien d'effacé, ni sur le siège ni sur ses participations.
    foreach ([$edge, $recent, $roomSeat, $revived, $failsOnSeat, $failsOnFrozen] as $seat) {
        expect(retentionPurgeSoloIdentity($seat))->toBe($identities[$seat->id]);
    }

    expect($identities[$failsOnSeat->id]['player_token_hash'])->not->toBeNull()
        ->and($identities[$failsOnFrozen->id]['display_nicknames'])->not->toContain(null);

    // Effacement de colonnes, jamais suppression de ligne : rien d'autre ne
    // change, `last_seen_at` compris, et aucune ligne ne part.
    foreach ($rests as $id => $rest) {
        expect(retentionPurgeSoloRest(Player::query()->findOrFail($id)))->toBe($rest);
    }

    expect($deletes->getArrayCopy())->toBe([])
        ->and(retentionPurgeRoomCounts())->toBe($counts)
        ->and($handler->eligibleCount())->toBe(2);

    // Une seconde plus tard, le siège resté à la borne est échu à son tour, et
    // les deux lignes en échec sont reprises ; aucun autre siège n'est touché.
    $failing = false;
    $this->travel(1)->seconds();

    $next = retentionPurgeRun()[PurgeScope::OrphanPlayer->value];

    expect($next->rows_deleted)->toBe(3)
        ->and($next->batches)->toBe(3)
        ->and($next->error)->toBeNull();

    foreach ([$edge, $failsOnSeat, $failsOnFrozen] as $seat) {
        expect(retentionPurgeSoloIdentity($seat))->toBe([
            'nickname' => null,
            'nickname_normalized' => null,
            'player_token_hash' => null,
            'solo_token_hash' => null,
            'display_nicknames' => [null],
        ]);
    }

    foreach ([$recent, $roomSeat, $revived] as $seat) {
        expect(retentionPurgeSoloIdentity($seat))->toBe($identities[$seat->id]);
    }

    expect($deletes->getArrayCopy())->toBe([])
        ->and(retentionPurgeRoomCounts())->toBe($counts)
        ->and($handler->eligibleCount())->toBe(0);
});

it('n\'efface pas un siège solo dont une partie n\'est pas figée, et le journalise', function (): void {
    $now = CarbonImmutable::now();
    $cutoff = RetentionRows::cutoff(PurgeScope::OrphanPlayer, $now);

    // Un siège repris par un démarrage solo garde sa dernière activité jusqu'à
    // son premier battement : la partie qui commence n'est pas figée.
    $seat = RetentionRows::soloSeat($cutoff->subDays(2));
    $running = Game::factory()->solo()->create(['started_at' => $now->subSecond()]);
    GamePlayer::factory()->for($running)->frozenFrom($seat)->create();
    $identity = retentionPurgeSoloIdentity($seat);

    Log::spy();

    $run = retentionPurgeRun()[PurgeScope::OrphanPlayer->value];

    // Refusé, sans échec : le siège reste éligible, ce que la sonde voit.
    expect($run->status)->toBe(PurgeRunStatus::Completed)
        ->and($run->rows_deleted)->toBe(0)
        ->and($run->batches)->toBe(1)
        ->and($run->error)->toBeNull()
        ->and(retentionPurgeSoloIdentity($seat))->toBe($identity)
        ->and(FakePurgeHandler::declared(PurgeScope::OrphanPlayer)->eligibleCount())->toBe(1);

    // Journalisé par des identifiants internes, jamais un pseudo.
    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context = []): bool => $context === ['player_id' => $seat->id, 'game_id' => $running->id])
        ->once();

    // Figée, la partie ne retient plus le siège : la nuit suivante l'efface.
    Game::query()->whereKey($running->id)->update(['status' => GameStatus::Interrupted->value, 'ended_at' => $now]);
    $this->travel(1)->day();

    expect(retentionPurgeRun()[PurgeScope::OrphanPlayer->value]->rows_deleted)->toBe(1)
        ->and(retentionPurgeSoloIdentity($seat))->toBe([
            'nickname' => null,
            'nickname_normalized' => null,
            'player_token_hash' => null,
            'solo_token_hash' => null,
            'display_nicknames' => [null, null],
        ]);
});

it('supprime les sessions au-delà de leur durée de vie sans dépendre du tirage', function (): void {
    // Le tirage du ramasse-miettes du framework ne se déclenche jamais : seule
    // la purge quotidienne peut supprimer quoi que ce soit.
    config(['session.lifetime' => 120, 'session.lottery' => [0, 100]]);

    $now = CarbonImmutable::now();

    RetentionRows::session('seed-expired', $now->subMinutes(121));
    RetentionRows::session('seed-forgotten', $now->subDays(30));
    RetentionRows::session('seed-edge', $now->subMinutes(120));
    RetentionRows::session('seed-live', $now->subMinutes(5));

    expect(DB::table('sessions')->whereNotNull('ip_address')->count())->toBe(4);

    $run = retentionPurgeRun()[PurgeScope::FrameworkSessions->value];

    // Au-delà de `session.lifetime` : supprimée ; à la borne exacte, la
    // session n'est pas encore expirée pour le framework, elle reste.
    expect($run->rows_deleted)->toBe(2)
        ->and(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['seed-edge', 'seed-live']);

    // Déterministe : la même exécution ne supprime rien de plus.
    expect(retentionPurgeRun()[PurgeScope::FrameworkSessions->value]->rows_deleted)->toBe(0);

    // La durée est relue à chaque exécution depuis `session.lifetime`.
    config(['session.lifetime' => 60]);

    expect(RetentionWindows::sessionLifetimeMinutes())->toBe(60)
        ->and(retentionPurgeRun()[PurgeScope::FrameworkSessions->value]->rows_deleted)->toBe(1)
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['seed-live']);
});

it('compte les lignes éligibles de chaque périmètre par le prédicat même de son gestionnaire', function (): void {
    $now = CarbonImmutable::now();
    $byScope = app(PurgeHandlers::class)->byScope();
    $seeded = [];
    $counted = [];

    foreach (PurgeScope::implemented() as $scope) {
        $seeded[$scope->value] = RetentionRows::seed($scope, $now);
    }

    foreach (PurgeScope::implemented() as $scope) {
        $handler = $byScope[$scope->value][0];

        // Le compte de la sonde : les lignes au-delà de la fenêtre, jamais
        // celle qui est exactement à la borne.
        $counted[$scope->value] = $handler->eligibleCount();

        expect($counted[$scope->value])->toBe($seeded[$scope->value]['eligible'], $scope->value)
            ->and($handler->nextBatch($now, null, 100))->toHaveCount($counted[$scope->value])
            // Évalué à un instant passé, le même prédicat ne compte que ce qui
            // était déjà éligible alors — ici, rien.
            ->and($handler->eligibleCount($now->subYears(3)))->toBe(0, $scope->value);
    }

    $runs = retentionPurgeRun();

    foreach (PurgeScope::implemented() as $scope) {
        $handler = $byScope[$scope->value][0];

        // Ce que le moteur supprime est exactement ce que le compte annonçait.
        expect($runs[$scope->value]->rows_deleted)->toBe($counted[$scope->value], $scope->value)
            ->and($handler->eligibleCount())->toBe(0, $scope->value)
            ->and(RetentionRows::seeded($scope))->toBe($seeded[$scope->value]['kept'], $scope->value);
    }
});
