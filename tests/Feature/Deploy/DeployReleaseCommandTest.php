<?php

use App\Enums\DrainPhase;
use App\Enums\Locale;
use App\Support\Deploy\DeployDrain;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/*
|--------------------------------------------------------------------------
| `deploy:release` — spec 100 § 11.3 et § 11.5 (étape 12 du hook), contrat C18-bis
|--------------------------------------------------------------------------
|
| Retire le drapeau quelle que soit sa phase et sort toujours en code 0 ;
| c'est aussi le remède d'un hook qui a échoué avant son étape 12.
|
*/

beforeEach(function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-09-26 14:00:00.250'));
});

function deployReleaseText(string $key): string
{
    $text = trans($key, [], Locale::French->value);

    expect($text)->toBeString()->not->toBe($key);

    return (string) $text;
}

it('lève le drapeau quelle que soit sa phase', function (): void {
    $drain = app(DeployDrain::class);
    $key = config()->string('deploy.cache_key');
    $released = deployReleaseText('admin.console.deploy.released');

    // Phase `draining`.
    $drain->start(DeployDrain::defaultTimeoutMinutes());

    expect($drain->state()?->phase)->toBe(DrainPhase::Draining);

    $this->artisan('deploy:release')->expectsOutputToContain($released)->assertExitCode(0)->run();

    expect($drain->isDraining())->toBeFalse()
        ->and(Cache::get($key))->toBeNull();

    // Phase `window`.
    $drain->start(DeployDrain::defaultTimeoutMinutes());
    $drain->openWindow(config()->integer('deploy.window_minutes'));

    expect($drain->state()?->phase)->toBe(DrainPhase::Window);

    $this->artisan('deploy:release')->expectsOutputToContain($released)->assertExitCode(0)->run();

    expect($drain->isDraining())->toBeFalse()
        ->and(Cache::get($key))->toBeNull();

    // Sans drapeau : rien à lever, toujours code 0 (idempotente).
    $this->artisan('deploy:release')
        ->expectsOutputToContain(deployReleaseText('admin.console.deploy.nothing_to_release'))
        ->assertExitCode(0)
        ->run();

    // Même une entrée illisible est retirée : la clé est rendue au prochain
    // drainage.
    Cache::put($key, 'illisible', 600);

    $this->artisan('deploy:release')->assertExitCode(0)->run();

    expect(Cache::get($key))->toBeNull();

    // Un drainage peut repartir aussitôt.
    expect($drain->start(DeployDrain::defaultTimeoutMinutes())->phase)->toBe(DrainPhase::Draining);
});
