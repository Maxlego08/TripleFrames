<?php

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Jobs\Retention\RunRetentionPurge;
use App\Models\PurgeRun;
use App\Support\Ops\Heartbeat;
use App\Support\Retention\PurgeHandlers;
use App\Support\Retention\RetentionPurger;
use App\Support\Retention\RetentionWindows;
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
use Tests\Support\Retention\FailingPurgeHandler;
use Tests\Support\Retention\FakePurgeHandler;
use Tests\Support\Retention\RetentionRows;

/*
|--------------------------------------------------------------------------
| Moteur de purge de rétention — spec 100 § 14, 10 § 11
|--------------------------------------------------------------------------
|
| Premier temps de L100-8 (D37 du 23/09) : les périmètres sans jeu
| (`framework_sessions`, `framework_failed_jobs`, `framework_reset_tokens`,
| `purge_run`). Les tests propres à `stale_room` et à la branche solo
| d'`orphan_player` s'écrivent avec leur périmètre (L50-8, L60-15).
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
