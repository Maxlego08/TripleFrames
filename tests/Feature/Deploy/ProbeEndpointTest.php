<?php

use App\Enums\ContentAvailability;
use App\Enums\OpsProbe;
use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Http\Middleware\EnsureProbeToken;
use App\Http\Middleware\RobotsDirectives;
use App\Jobs\Ops\WorkerHeartbeat;
use App\Models\Frame;
use App\Models\Game;
use App\Models\PurgeRun;
use App\Models\Room;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Ops\Heartbeat;
use App\Support\Ops\ProbeResponse;
use App\Support\Ops\SystemLoad;
use App\Support\Retention\PurgeHandler;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Sondes d'exploitation — spec 100 § 15
|--------------------------------------------------------------------------
|
| `GET /ops/probe/{probe}`, interrogée de l'extérieur par la supervision :
| `200 {"status":"ok"}` ou `503 {"status":"stale"}`, rien d'autre ; hors du
| groupe `web` ; un jeton absent, faux ou non configuré rend la même 404
| qu'une sonde inconnue.
|
| La machine est simulée ({@see SystemLoad} aux sources injectées) : la sonde
| `load` ne lit jamais le système du poste ni celui de la CI. Chaque requête
| part d'une adresse distincte, pour que le limiteur `ops-probe` ne borne que
| le test qui le prouve.
|
*/

beforeEach(function (): void {
    config(['ops.probe_token' => probeEndpointToken()]);

    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'UTC'));

    $this->machineDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-probe-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->machineDirectory);

    // Une machine au repos : 2 vCPU, charge 0,4, 55 % de mémoire disponible.
    probeEndpointMachine($this->machineDirectory, load: 0.4, cpus: 2, availablePercent: 55.0);
});

afterEach(function (): void {
    File::deleteDirectory($this->machineDirectory);
});

function probeEndpointToken(): string
{
    return 'jeton-de-supervision-de-test-0123456789abcdef';
}

/**
 * Interroge une sonde. `$token` : la valeur de l'en-tête, `null` pour ne pas
 * l'envoyer du tout. Chaque appel part d'une adresse neuve, sauf `$ip` donné.
 */
function probeEndpointRequest(string $probe, ?string $token = 'valid', ?string $ip = null): TestResponse
{
    static $sequence = 0;

    $server = ['REMOTE_ADDR' => $ip ?? '198.51.100.'.(++$sequence % 250 + 1)];

    if ($token !== null) {
        $server['HTTP_X_PROBE_TOKEN'] = $token === 'valid' ? probeEndpointToken() : $token;
    }

    return test()->call('GET', '/ops/probe/'.$probe, server: $server);
}

/**
 * Ce qu'un client voit d'une réponse : statut, corps et en-têtes, sans ceux
 * qui changent d'une requête à l'autre (date, compteur du limiteur).
 *
 * @return array{status: int, body: string|false, headers: array<string, list<string|null>>}
 */
function probeEndpointShape(TestResponse $response): array
{
    $headers = $response->headers->all();
    unset($headers['date'], $headers['x-ratelimit-remaining']);
    ksort($headers);

    return [
        'status' => $response->getStatusCode(),
        'body' => $response->getContent(),
        'headers' => $headers,
    ];
}

/**
 * Remplace les mesures de la machine. `null` = mesure illisible.
 */
function probeEndpointMachine(string $directory, ?float $load, ?int $cpus, ?float $availablePercent): void
{
    $cpuinfo = $directory.DIRECTORY_SEPARATOR.'cpuinfo';
    $meminfo = $directory.DIRECTORY_SEPARATOR.'meminfo';

    File::delete([$cpuinfo, $meminfo]);

    if ($cpus !== null) {
        $lines = array_map(static fn (int $index): string => "processor\t: {$index}\nmodel name\t: vCPU\n", range(0, $cpus - 1));
        File::put($cpuinfo, implode("\n", $lines));
    }

    if ($availablePercent !== null) {
        $total = 4_000_000;
        $available = (int) round($total * $availablePercent / 100);
        File::put($meminfo, "MemTotal:       {$total} kB\nMemFree:          100000 kB\nMemAvailable:   {$available} kB\n");
    }

    app()->instance(SystemLoad::class, new SystemLoad(
        static fn (): array|false => $load === null ? false : [$load, $load, $load],
        $meminfo,
        $cpuinfo,
    ));
}

/**
 * Étiquette un gestionnaire de purge de test pour `$scope` : il compte
 * `$eligible` lignes éligibles et retient l'instant auquel la sonde l'évalue.
 */
function probeEndpointPurgeHandler(PurgeScope $scope, int $eligible): PurgeHandler
{
    $handler = new class($scope, $eligible) implements PurgeHandler
    {
        public ?CarbonImmutable $askedAsOf = null;

        public function __construct(private readonly PurgeScope $served, public int $eligible) {}

        public function scope(): PurgeScope
        {
            return $this->served;
        }

        public function eligibleCount(?CarbonImmutable $asOf = null): int
        {
            $this->askedAsOf = $asOf;

            return $this->eligible;
        }
    };

    $abstract = 'tests.purge-handler.'.$scope->value.'.'.spl_object_id($handler);
    app()->instance($abstract, $handler);
    app()->tag([$abstract], PurgeHandler::class);

    return $handler;
}

/** Une exécution de purge terminée, commencée il y a `$hoursAgo` heures. */
function probeEndpointPurgeRun(PurgeScope $scope, float $hoursAgo, int $rowsDeleted, PurgeRunStatus $status = PurgeRunStatus::Completed): PurgeRun
{
    $startedAt = CarbonImmutable::now()->subSeconds((int) round($hoursAgo * 3600));

    return PurgeRun::factory()->forScope($scope)->create([
        'status' => $status,
        'started_at' => $startedAt,
        'finished_at' => $status === PurgeRunStatus::Completed ? $startedAt->addMinutes(2) : null,
        'rows_deleted' => $rowsDeleted,
        'batches' => $rowsDeleted > 0 ? 1 : 0,
        'ran_at' => $startedAt,
    ]);
}

/**
 * Bascule le cache applicatif sur un VRAI `RedisStore`, dont le client rend
 * chaque valeur en chaîne, comme Redis : le chemin de sérialisation de la
 * production (`CACHE_STORE=redis`), sans serveur. Le limiteur `ops-probe`
 * reste sur le store `array` sur lequel il a été résolu au démarrage.
 */
function probeEndpointRedisCache(): void
{
    $client = new class
    {
        /** @var array<string, string> */
        private array $values = [];

        public function set(string $key, mixed $value): string
        {
            $this->values[$key] = (string) $value;

            return 'OK';
        }

        public function get(string $key): ?string
        {
            return $this->values[$key] ?? null;
        }
    };

    $redis = new class(new PredisConnection($client)) implements RedisFactory
    {
        public function __construct(private readonly PredisConnection $connection) {}

        public function connection($name = null): PredisConnection
        {
            return $this->connection;
        }
    };

    app('cache')->extend('redis-strings', fn (): Repository => Cache::repository(new RedisStore($redis, 'tripleframes-test:')));

    config([
        'cache.stores.redis-strings' => ['driver' => 'redis-strings'],
        'cache.default' => 'redis-strings',
    ]);

    // Le limiteur, résolu au démarrage par `RateLimiter::for`, garde le store
    // d'alors : le client simulé n'a pas à connaître ses commandes.
}

/** Les deux files viennent de battre. */
function probeEndpointFreshHeartbeats(): void
{
    (new WorkerHeartbeat(Heartbeat::GAME))->handle();
    (new WorkerHeartbeat(Heartbeat::DEFAULT))->handle();
}

it('refuse toute sonde sans le jeton de supervision, avec la même réponse qu\'une sonde inconnue', function (): void {
    probeEndpointFreshHeartbeats();

    // Avec le bon jeton, chaque sonde existe et répond.
    foreach (OpsProbe::cases() as $probe) {
        probeEndpointRequest($probe->value)->assertOk();
    }

    $unknown = probeEndpointRequest('inconnue');
    $unknown->assertNotFound();
    expect($unknown->getContent())->toBe('');

    $reference = probeEndpointShape($unknown);

    // Sans en-tête, en-tête vide, jeton faux, jeton tronqué ou changé de
    // casse : exactement la réponse d'une sonde inconnue, sur chaque sonde.
    $refused = [null, '', 'faux', substr(probeEndpointToken(), 0, -1), strtoupper(probeEndpointToken())];

    foreach (OpsProbe::cases() as $probe) {
        foreach ($refused as $token) {
            expect(probeEndpointShape(probeEndpointRequest($probe->value, $token)))->toBe($reference);
        }
    }

    // Et une sonde inconnue sous un jeton faux ne se distingue pas non plus.
    expect(probeEndpointShape(probeEndpointRequest('inconnue', 'faux')))->toBe($reference);
});

it('refuse toute sonde quand aucun jeton de supervision n\'est configuré', function (): void {
    probeEndpointFreshHeartbeats();

    $reference = probeEndpointShape(probeEndpointRequest('inconnue'));

    // `hash_equals('', '')` vaut vrai : sans la garde, un en-tête vide
    // ouvrirait les sondes d'une production qui aurait omis la variable.
    foreach ([null, '', '   '] as $configured) {
        config(['ops.probe_token' => $configured]);

        foreach (OpsProbe::cases() as $probe) {
            foreach ([null, '', '   ', probeEndpointToken()] as $token) {
                expect(probeEndpointShape(probeEndpointRequest($probe->value, $token)))->toBe($reference);
            }
        }
    }
});

it('déclare le worker game périmé au-delà du seuil', function (): void {
    $threshold = config('ops.heartbeat.game_stale_seconds');

    expect($threshold)->toBe(90);

    // Aucun battement jamais écrit : périmé.
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertStatus(503);

    (new WorkerHeartbeat(Heartbeat::GAME))->handle();

    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertOk();

    $this->travel($threshold)->seconds();
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertOk();

    $this->travel(1)->seconds();
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertStatus(503);

    // Le battement de la file `default` ne tient pas lieu de celui de `game`,
    // et chaque file a son propre seuil.
    (new WorkerHeartbeat(Heartbeat::DEFAULT))->handle();
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertStatus(503);

    $this->travel(config('ops.heartbeat.default_stale_seconds'))->seconds();
    probeEndpointRequest(OpsProbe::WorkerDefault->value)->assertOk();

    $this->travel(1)->seconds();
    probeEndpointRequest(OpsProbe::WorkerDefault->value)->assertStatus(503);

    // Sur Redis, le cache de la production, le battement se relit en chaîne
    // numérique : la sonde le lit comme l'entier qu'il est, sans quoi elle
    // resterait en alerte même juste après un battement.
    probeEndpointRedisCache();

    (new WorkerHeartbeat(Heartbeat::GAME))->handle();

    expect(Cache::get(Heartbeat::key(Heartbeat::GAME)))->toBe((string) CarbonImmutable::now()->getTimestampMs());
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertOk();

    $this->travel($threshold + 1)->seconds();
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertStatus(503);
});

it('ne rend qu\'un statut, sans aucune donnée', function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    probeEndpointFreshHeartbeats();

    foreach (OpsProbe::cases() as $probe) {
        $response = probeEndpointRequest($probe->value);

        $response->assertOk()->assertHeader('Content-Type', 'application/json');
        expect($response->getContent())->toBe('{"status":"'.ProbeResponse::STATUS_OK.'"}');
    }

    // Chaque sonde en alerte : battements vieillis, frame retirée dont les
    // fichiers restent, périmètre de purge sans exécution, machine saturée.
    $this->travel(config('ops.heartbeat.default_stale_seconds') + 1)->seconds();
    Frame::factory()->withdrawn()->create();
    probeEndpointPurgeHandler(PurgeScope::FrameworkSessions, eligible: 3);
    probeEndpointMachine($this->machineDirectory, load: 9.0, cpus: 2, availablePercent: 2.0);

    foreach (OpsProbe::cases() as $probe) {
        $response = probeEndpointRequest($probe->value);

        $response->assertStatus(503)->assertHeader('Content-Type', 'application/json');
        expect($response->getContent())->toBe('{"status":"'.ProbeResponse::STATUS_STALE.'"}');
    }
});

it('répond sans session ni cookie, en noindex et no-store', function (): void {
    // Même quand l'indexation est levée : aucune route `ops.` ne porte le
    // drapeau, ses réponses restent `noindex`.
    config(['app.indexable' => true]);

    $route = Route::getRoutes()->getByName('ops.probe');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('ops/probe/{probe}')
        ->and($route?->methods())->toBe(['GET', 'HEAD'])
        ->and($route?->gatherMiddleware())->toBe(['throttle:ops-probe', EnsureProbeToken::class])
        ->and(array_key_exists(RobotsDirectives::ROUTE_FLAG, $route?->defaults ?? []))->toBeFalse();

    probeEndpointFreshHeartbeats();

    $responses = [
        'ok' => probeEndpointRequest(OpsProbe::WorkerGame->value),
        'jeton absent' => probeEndpointRequest(OpsProbe::WorkerGame->value, null),
        'sonde inconnue' => probeEndpointRequest('inconnue'),
    ];

    $this->travel(config('ops.heartbeat.default_stale_seconds') + 1)->seconds();
    $responses['stale'] = probeEndpointRequest(OpsProbe::WorkerDefault->value);

    $responses['ok']->assertOk();
    $responses['stale']->assertStatus(503);
    $responses['jeton absent']->assertNotFound();
    $responses['sonde inconnue']->assertNotFound();

    foreach ($responses as $case => $response) {
        expect($response->headers->getCookies())->toBe([], "{$case} : cookie posé")
            ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$case} : Set-Cookie")
            ->and($response->headers->get('X-Robots-Tag'))->toBe(RobotsDirectives::NOINDEX, "{$case} : X-Robots-Tag")
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    }
});

it('signale une sonde d\'intégrité non nulle', function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    $this->seed(DatabaseSeeder::class);

    // Le catalogue de démonstration est intègre.
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // Sonde n° 1 de 10 § 11.3 : une partie jamais clôturée depuis 24 h.
    $recent = Game::factory()->create(['started_at' => CarbonImmutable::now()->subHours(24)]);
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    $forgotten = Game::factory()->create(['started_at' => CarbonImmutable::now()->subHours(24)->subSecond()]);
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);

    $forgotten->forceFill(['ended_at' => CarbonImmutable::now()])->save();
    $recent->forceFill(['ended_at' => CarbonImmutable::now()])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // Sonde n° 2 : un salon jamais archivé, inactif depuis 48 h.
    $room = Room::factory()->create(['last_activity_at' => CarbonImmutable::now()->subHours(48)->subSecond()]);
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);

    $room->forceFill(['archived_at' => CarbonImmutable::now()])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // Sonde n° 3 : une frame retirée dont les fichiers n'ont pas été supprimés.
    $withdrawn = Frame::factory()->withFiles()->withdrawn()->create();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);

    $withdrawn->forceFill(['files_deleted_at' => CarbonImmutable::now()])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // Sondes de frame de 20 § 5.9 : une frame publiée sans revue désignée,
    // puis des octets servis qui ne sont pas ceux que la revue a hachés.
    $orphan = Frame::factory()->published()->create();
    $review = $orphan->published_review_id;
    $orphan->forceFill(['published_review_id' => null])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);

    $orphan->forceFill(['published_review_id' => $review])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    $orphan->forceFill(['published_hash' => hash('sha256', 'autres octets')])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);
});

it('signale une frame publiée hors du plancher de recadrage', function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    $this->seed(DatabaseSeeder::class);

    $frame = Frame::factory()->published()->create();
    $conforming = $frame->only(['crop_x', 'crop_y', 'crop_width', 'crop_height']);

    expect($frame->availability)->toBe(ContentAvailability::Published);
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // Le visuel presque entier, publié : l'engagement « image transformée »
    // n'est plus tenu.
    $frame->forceFill(['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1920, 'crop_height' => 1080])->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);

    $frame->forceFill($conforming)->save();
    probeEndpointRequest(OpsProbe::Integrity->value)->assertOk();

    // La sonde lit les valeurs COURANTES de `PlatformLimits` : un plancher
    // durci rend non conformes les cadres déjà publiés.
    platformLimitsConfigure(['frame_crop_max_width_percent' => PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT]);
    probeEndpointRequest(OpsProbe::Integrity->value)->assertStatus(503);
});

it('signale un périmètre qui n\'a rien supprimé en 48 h alors que des lignes sont éligibles', function (): void {
    $staleHours = config('ops.purge.stale_hours');

    expect($staleHours)->toBe(48);

    $sessions = probeEndpointPurgeHandler(PurgeScope::FrameworkSessions, eligible: 0);
    $failedJobs = probeEndpointPurgeHandler(PurgeScope::FrameworkFailedJobs, eligible: 0);
    probeEndpointPurgeRun(PurgeScope::FrameworkFailedJobs, hoursAgo: 10, rowsDeleted: 0);

    // La dernière nuit n'a rien supprimé, et rien n'était éligible.
    $lastNight = probeEndpointPurgeRun(PurgeScope::FrameworkSessions, hoursAgo: 10, rowsDeleted: 0);
    probeEndpointPurgeRun(PurgeScope::FrameworkSessions, hoursAgo: 34, rowsDeleted: 0);

    probeEndpointRequest(OpsProbe::Purge->value)->assertOk();

    // Des lignes que la dernière exécution aurait dû supprimer existent
    // encore : le périmètre est bloqué.
    $sessions->eligible = 4;
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    // L'éligibilité se lit au début de la dernière exécution terminée.
    expect($sessions->askedAsOf?->equalTo($lastNight->started_at))->toBeTrue();

    // Une exécution qui a supprimé des lignes dans la fenêtre : le périmètre
    // avance, le reste est le travail de la nuit suivante.
    probeEndpointPurgeRun(PurgeScope::FrameworkSessions, hoursAgo: $staleHours - 1, rowsDeleted: 12);
    probeEndpointRequest(OpsProbe::Purge->value)->assertOk();

    // Deux heures plus tard, cette exécution sort de la fenêtre.
    $this->travel(2)->hours();
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    // Une exécution plantée ou en cours n'en tient jamais lieu, même si elle
    // a supprimé des lignes avant de s'arrêter.
    probeEndpointPurgeRun(PurgeScope::FrameworkSessions, hoursAgo: 1, rowsDeleted: 50, status: PurgeRunStatus::Failed);
    probeEndpointPurgeRun(PurgeScope::FrameworkSessions, hoursAgo: 0.5, rowsDeleted: 50, status: PurgeRunStatus::Running);
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    // Plus rien d'éligible : la sonde revient au vert. L'autre périmètre, sain,
    // n'a jamais pesé.
    $sessions->eligible = 0;
    probeEndpointRequest(OpsProbe::Purge->value)->assertOk();
    expect($failedJobs->askedAsOf)->not->toBeNull();
});

it('met la sonde purge en alerte quand un périmètre n\'a aucune exécution terminée dans la fenêtre', function (): void {
    // Aucun périmètre implémenté ni gestionnaire : rien à surveiller.
    expect(PurgeScope::implemented())->not->toContain(PurgeScope::StaleLobby);
    probeEndpointRequest(OpsProbe::Purge->value)->assertOk();

    probeEndpointPurgeHandler(PurgeScope::PurgeRun, eligible: 0);

    // L'absence de ligne est une panne.
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    // Une ligne en cours ou plantée n'en tient pas lieu.
    probeEndpointPurgeRun(PurgeScope::PurgeRun, hoursAgo: 1, rowsDeleted: 0, status: PurgeRunStatus::Running);
    probeEndpointPurgeRun(PurgeScope::PurgeRun, hoursAgo: 2, rowsDeleted: 0, status: PurgeRunStatus::Failed);
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    // Une ligne terminée hors de la fenêtre non plus.
    probeEndpointPurgeRun(PurgeScope::PurgeRun, hoursAgo: config('ops.purge.stale_hours') + 0.1, rowsDeleted: 0);
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);

    probeEndpointPurgeRun(PurgeScope::PurgeRun, hoursAgo: 20, rowsDeleted: 0);
    probeEndpointRequest(OpsProbe::Purge->value)->assertOk();

    // Deux gestionnaires pour un même périmètre : en alerte.
    probeEndpointPurgeHandler(PurgeScope::PurgeRun, eligible: 0);
    probeEndpointRequest(OpsProbe::Purge->value)->assertStatus(503);
});

it('compare la charge moyenne au nombre de vCPU et la mémoire disponible à son plancher', function (): void {
    expect(config('ops.load.max_per_cpu'))->toBe(1.5)
        ->and(config('ops.load.min_available_percent'))->toBe(10.0)
        ->and(config('ops.load.cpu_count'))->toBe(0);

    $directory = $this->machineDirectory;
    $probe = static fn (): TestResponse => probeEndpointRequest(OpsProbe::Load->value);

    // Deux vCPU : la borne est à 3,0.
    probeEndpointMachine($directory, load: 3.0, cpus: 2, availablePercent: 50.0);
    $probe()->assertOk();

    probeEndpointMachine($directory, load: 3.01, cpus: 2, availablePercent: 50.0);
    $probe()->assertStatus(503);

    // Le relevé du VPS prime sur `/proc/cpuinfo`.
    config(['ops.load.cpu_count' => 4]);
    $probe()->assertOk();
    config(['ops.load.cpu_count' => 0]);

    // La mémoire disponible sous son plancher.
    probeEndpointMachine($directory, load: 0.5, cpus: 2, availablePercent: 9.5);
    $probe()->assertStatus(503);

    // Mémoire illisible (hors `open_basedir`) : la sonde ne mesure que la charge.
    probeEndpointMachine($directory, load: 0.5, cpus: 2, availablePercent: null);
    $probe()->assertOk();

    // Une charge ou un nombre de vCPU illisibles ne sont jamais verts.
    probeEndpointMachine($directory, load: null, cpus: 2, availablePercent: 50.0);
    $probe()->assertStatus(503);

    probeEndpointMachine($directory, load: 0.5, cpus: null, availablePercent: 50.0);
    $probe()->assertStatus(503);
});

it('borne les essais de jeton par adresse avant de lire le jeton', function (): void {
    probeEndpointFreshHeartbeats();

    $address = '203.0.113.50';

    for ($attempt = 1; $attempt <= 30; $attempt++) {
        probeEndpointRequest(OpsProbe::WorkerGame->value, 'faux-'.$attempt, $address)->assertNotFound();
    }

    // Au-delà, même le bon jeton est refusé depuis cette adresse…
    probeEndpointRequest(OpsProbe::WorkerGame->value, 'valid', $address)->assertStatus(429);

    // … et pas depuis une autre.
    probeEndpointRequest(OpsProbe::WorkerGame->value)->assertOk();
});
