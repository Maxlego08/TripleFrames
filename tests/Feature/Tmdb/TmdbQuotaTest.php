<?php

use App\Support\Catalog\TmdbQuotaLimiter;
use App\Support\Tmdb\TmdbClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Limiteur de quota TMDB partagé — spec 20 § 3.6, lot L20-24a
|--------------------------------------------------------------------------
|
| Deux instances du limiteur jouent deux processus : elles ne partagent que le
| cache. `Sleep::fake(syncWithCarbon: true)` avance l'horloge du temps dormi,
| si bien que l'instant de chaque appel se lit sur `CarbonImmutable::now()`.
| À 10 appels par seconde, un créneau dure 100 000 µs.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00.000000'));

    Config::set('catalog.import.requests_per_second', 10);
});

afterEach(function (): void {
    Sleep::fake(false);
    CarbonImmutable::setTestNow();
});

/** Microsecondes écoulées depuis l'instant de départ. */
function quotaElapsed(): int
{
    return (int) CarbonImmutable::parse('2026-10-08 12:00:00.000000')->diffInMicroseconds(CarbonImmutable::now(), true);
}

test('le limiteur partagé plafonne la somme des processus', function (): void {
    $first = new TmdbQuotaLimiter;
    $second = new TmdbQuotaLimiter;

    $instants = [];

    foreach (range(1, 10) as $ignored) {
        $first->throttle();
        $instants[] = quotaElapsed();
        $second->throttle();
        $instants[] = quotaElapsed();
    }

    // Vingt appels de deux processus : jamais deux dans le même créneau, et la
    // somme tient le débit configuré — 19 intervalles au moins.
    foreach (array_slice($instants, 1) as $index => $instant) {
        expect($instant - $instants[$index])->toBeGreaterThanOrEqual(100_000);
    }

    expect(end($instants))->toBeGreaterThanOrEqual(19 * 100_000);
});

test('un appel interactif passe avant les balayages et les repousse d\'un créneau', function (): void {
    $sweep = new TmdbQuotaLimiter;
    $interactive = new TmdbQuotaLimiter;

    $sweep->throttle();
    expect(quotaElapsed())->toBe(0);

    // Le créneau suivant des balayages est réservé (100 ms) : l'appel
    // interactif ne l'attend pas.
    $interactive->admit();
    expect(quotaElapsed())->toBe(0);

    // Le balayage, repoussé d'un créneau, attend 200 ms.
    $sweep->throttle();
    expect(quotaElapsed())->toBe(200_000);

    // Deux appels interactifs restent espacés entre eux.
    $interactive->admit();
    $interactive->admit();
    expect(quotaElapsed())->toBe(200_000 + 100_000);
});

test('un appel déjà admis par le balayage n\'est pas compté deux fois', function (): void {
    $sweep = new TmdbQuotaLimiter;
    $other = new TmdbQuotaLimiter;

    $sweep->throttle();
    // L'appel du client qui suit le throttle : déjà admis, rien de réservé.
    $sweep->admit();

    $other->throttle();
    expect(quotaElapsed())->toBe(100_000);
});

test('un débit nul désactive l\'étranglement', function (): void {
    Config::set('catalog.import.requests_per_second', 0);

    $limiter = new TmdbQuotaLimiter;

    $limiter->throttle();
    $limiter->admit();
    $limiter->admit();
    (new TmdbQuotaLimiter)->throttle();

    Sleep::assertNeverSlept();
});

test('le client TMDB fait passer chaque appel interactif par le limiteur partagé du conteneur', function (): void {
    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);

    Http::fake([
        '*themoviedb.org/3/search/movie*' => Http::response(TmdbFixture::json('search-page'), 200, ['Content-Type' => 'application/json']),
    ]);

    expect(app(TmdbQuotaLimiter::class))->toBe(app(TmdbQuotaLimiter::class));

    $client = app(TmdbClient::class);

    $client->search('Alien');
    $client->search('Alien');
    $client->search('Alien');

    expect(quotaElapsed())->toBe(200_000);

    // Un balayage qui suit attend derrière les trois appels interactifs.
    app(TmdbQuotaLimiter::class)->throttle();

    expect(quotaElapsed())->toBe(300_000);
});
