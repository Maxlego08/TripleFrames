<?php

use App\Enums\OpsProbe;
use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Jobs\Retention\RunRetentionPurge;
use App\Models\PurgeRun;
use App\Support\Retention\PurgeSuspension;
use App\Support\Retention\RetentionPurger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Retention\RetentionRows;

/*
|--------------------------------------------------------------------------
| Suspension de la purge — spec 100 § 14 et § 15
|--------------------------------------------------------------------------
|
| `purge:suspend` est l'interrupteur d'incident : il arrête la purge et
| DÉCLENCHE l'alerte de la sonde `purge`, qui ne se lève qu'avec
| `purge:resume` (`questions-ouvertes.md` § Risques élevés).
|
*/

beforeEach(function (): void {
    config(['ops.probe_token' => 'jeton-de-supervision-de-test-purge-0123456789']);

    $this->travelTo(CarbonImmutable::parse('2026-09-24 02:10:00', 'UTC'));
});

/** La sonde `purge`, interrogée comme la supervision, chaque fois d'une adresse neuve. */
function purgeSuspensionProbe(): TestResponse
{
    static $sequence = 0;

    return test()->call('GET', '/ops/probe/'.OpsProbe::Purge->value, server: [
        'REMOTE_ADDR' => '198.51.100.'.(++$sequence % 250 + 1),
        'HTTP_X_PROBE_TOKEN' => config('ops.probe_token'),
    ]);
}

function purgeSuspensionMessage(string $key): string
{
    $message = trans('admin.console.purge.'.$key, [], 'fr');

    return is_string($message) ? $message : '';
}

it('met la sonde purge en alerte tant que la purge est suspendue', function (): void {
    app(RetentionPurger::class)->run();
    purgeSuspensionProbe()->assertOk();

    $this->artisan('purge:suspend')
        ->expectsOutputToContain(purgeSuspensionMessage('suspended'))
        ->assertSuccessful();

    expect(app(PurgeSuspension::class)->isSuspended())->toBeTrue()
        ->and(Cache::get(PurgeSuspension::CACHE_KEY))->toBe(CarbonImmutable::now()->toIso8601String());

    // Des exécutions fraîches et saines ne masquent rien : c'est la
    // suspension elle-même qui sonne.
    purgeSuspensionProbe()->assertStatus(503);

    // Aucune exécution ne part, ni depuis la file ni depuis la console, et
    // l'alerte demeure.
    $rows = PurgeRun::query()->count();

    RunRetentionPurge::dispatch();
    $this->artisan('purge:run', ['--sync' => true])
        ->expectsOutputToContain(purgeSuspensionMessage('suspended'))
        ->assertFailed();

    expect(PurgeRun::query()->count())->toBe($rows);
    purgeSuspensionProbe()->assertStatus(503);

    // Le drapeau n'expire jamais de lui-même.
    $this->artisan('purge:suspend')
        ->expectsOutputToContain(purgeSuspensionMessage('already_suspended'))
        ->assertSuccessful();

    $this->travel(30)->days();
    purgeSuspensionProbe()->assertStatus(503);

    // Levée : la sonde revient au vert dès qu'une exécution terminée est dans
    // sa fenêtre.
    $this->artisan('purge:resume')
        ->expectsOutputToContain(purgeSuspensionMessage('resumed'))
        ->assertSuccessful();

    expect(Cache::has(PurgeSuspension::CACHE_KEY))->toBeFalse();
    purgeSuspensionProbe()->assertStatus(503);

    app(RetentionPurger::class)->run();
    purgeSuspensionProbe()->assertOk();

    $this->artisan('purge:resume')
        ->expectsOutputToContain(purgeSuspensionMessage('not_suspended'))
        ->assertSuccessful();

    purgeSuspensionProbe()->assertOk();
});

it('reprend la purge à la levée de la suspension', function (): void {
    config(['ops.purge.batch_size' => 1]);

    $cutoff = RetentionRows::cutoff(PurgeScope::FrameworkSessions, CarbonImmutable::now());

    foreach (['seed-a', 'seed-b', 'seed-c'] as $index => $id) {
        RetentionRows::session($id, $cutoff->subMinutes(10 - $index));
    }

    $this->artisan('purge:suspend')->assertSuccessful();

    // Suspendue : le job déposé ne supprime rien et n'écrit aucune ligne.
    RunRetentionPurge::dispatch();

    expect(RetentionRows::seeded(PurgeScope::FrameworkSessions))->toBe(3)
        ->and(PurgeRun::query()->count())->toBe(0);

    // Levée : la même exécution repart, et supprime.
    $this->artisan('purge:resume')->assertSuccessful();
    RunRetentionPurge::dispatch();

    expect(RetentionRows::seeded(PurgeScope::FrameworkSessions))->toBe(0)
        ->and(PurgeRun::query()->pluck('status')->unique()->all())->toBe([PurgeRunStatus::Completed])
        ->and(PurgeRun::query()->count())->toBe(count(PurgeScope::implemented()));

    // Une suspension posée PENDANT une exécution l'arrête au lot suivant : le
    // périmètre en cours est clos en échec, les suivants ne partent pas.
    foreach (['seed-d', 'seed-e', 'seed-f'] as $index => $id) {
        RetentionRows::session($id, $cutoff->subMinutes(10 - $index));
    }

    $armed = true;

    DB::listen(static function (QueryExecuted $query) use (&$armed): void {
        if ($armed && preg_match('/^delete from ["`]sessions["`]/i', $query->sql) === 1) {
            $armed = false;
            app(PurgeSuspension::class)->suspend(CarbonImmutable::now());
        }
    });

    $this->travel(1)->day();
    $interrupted = app(RetentionPurger::class)->run();

    expect($interrupted)->toHaveCount(1)
        ->and($interrupted[0]->scope)->toBe(PurgeScope::FrameworkSessions)
        ->and($interrupted[0]->status)->toBe(PurgeRunStatus::Failed)
        ->and($interrupted[0]->rows_deleted)->toBe(1)
        ->and($interrupted[0]->finished_at)->toBeNull()
        ->and(RetentionRows::seeded(PurgeScope::FrameworkSessions))->toBe(2);

    // Levée : la purge reprend ce qui restait.
    $this->artisan('purge:resume')->assertSuccessful();
    $this->artisan('purge:run', ['--sync' => true])
        ->expectsOutputToContain((string) trans('admin.console.purge.done', [
            'scopes' => count(PurgeScope::implemented()),
            'rows' => 2,
        ], 'fr'))
        ->assertSuccessful();

    expect(RetentionRows::seeded(PurgeScope::FrameworkSessions))->toBe(0);
});
