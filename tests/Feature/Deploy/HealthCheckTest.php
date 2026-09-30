<?php

use App\Listeners\DiagnoseDependencies;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Redis;
use Predis\PredisException;

/*
|--------------------------------------------------------------------------
| La route de santé `/up` — spec 100 § 15
|--------------------------------------------------------------------------
|
| Un écouteur de `DiagnosingHealth` interroge la base, et Redis SEULEMENT si
| le cache ou la file par défaut en dépendent : la CI (`array`, `sync`) n'a
| pas de Redis, et `/up` doit y rester vert. Le framework répond 500 dès que
| l'écouteur lève.
|
| Redis est rendu injoignable par une vraie connexion vers un port fermé de
| la boucle locale, délai de connexion court et sans réessai : le test ne
| dépend d'aucun service, et ne simule pas le client.
|
*/

beforeEach(function (): void {
    // Le chemin de production : l'exception est rapportée, jamais affichée.
    config(['app.debug' => false]);
    Exceptions::fake();

    // `predis`, le client de la production et de la CI (spec 100 § 2.4), quel
    // que soit le `.env` du poste.
    config(['database.redis.client' => 'predis']);

    foreach (['default', 'cache'] as $connection) {
        config([
            "database.redis.{$connection}.url" => null,
            "database.redis.{$connection}.host" => '127.0.0.1',
            "database.redis.{$connection}.port" => 1,
            "database.redis.{$connection}.timeout" => 0.5,
            "database.redis.{$connection}.max_retries" => 0,
        ]);
    }

    // Le gestionnaire Redis a copié la configuration à sa construction.
    app()->forgetInstance('redis');
    Redis::clearResolvedInstance('redis');
});

it('fait échouer /up quand la base ne répond plus, ou Redis quand il est configuré', function (): void {
    // Un seul écouteur : la découverte automatique, coupée, ne l'enregistre
    // pas une seconde fois — chaque `/up` n'interroge qu'une fois la base.
    expect(Event::getListeners(DiagnosingHealth::class))->toHaveCount(1);

    // CI : SQLite, cache `array`, file `sync`. Redis n'est pas interrogé — il
    // est pourtant injoignable.
    expect(config('cache.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('sync')
        ->and(DiagnoseDependencies::redisConnections())->toBe([]);

    $this->get('/up')->assertOk();
    $this->getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);

    // Le cache sur Redis : ses connexions de données et de verrous.
    config(['cache.default' => 'redis']);

    expect(DiagnoseDependencies::redisConnections())->toBe(['cache', 'default']);
    $this->get('/up')->assertStatus(500);
    $this->getJson('/up')->assertStatus(500)->assertExactJson(['status' => 'down']);

    // La file seule sur Redis.
    config(['cache.default' => 'array', 'queue.default' => 'redis']);

    expect(DiagnoseDependencies::redisConnections())->toBe(['default']);
    $this->get('/up')->assertStatus(500);

    // La base ne répond plus : une connexion par défaut vers une base absente.
    config(['queue.default' => 'sync']);
    $this->get('/up')->assertOk();

    $default = config('database.default');

    config([
        'database.connections.health_missing' => [
            'driver' => 'sqlite',
            'database' => storage_path('framework/testing/absente-'.bin2hex(random_bytes(6)).'.sqlite'),
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
        'database.default' => 'health_missing',
    ]);

    try {
        $this->get('/up')->assertStatus(500);
    } finally {
        config(['database.default' => $default]);
    }

    $this->get('/up')->assertOk();

    // Chaque panne a été rapportée au journal de l'application, et c'est bien
    // la connexion qui a échoué, pas autre chose.
    $reported = collect(Exceptions::reported());

    expect($reported)->toHaveCount(4)
        ->and($reported->filter(static fn (Throwable $exception): bool => $exception instanceof PredisException
            && str_contains($exception->getMessage(), '127.0.0.1:1')))->toHaveCount(3)
        ->and($reported->filter(static fn (Throwable $exception): bool => $exception instanceof QueryException
            && $exception->getPrevious() instanceof SQLiteDatabaseDoesNotExistException))->toHaveCount(1);
});
