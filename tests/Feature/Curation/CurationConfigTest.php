<?php

use App\Models\User;
use App\Support\Admin\SeedList;
use App\Support\Curation\CurationQueue;
use App\Support\Frames\FrameGeometry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Configuration de curation — spec 20 § 13.7
|--------------------------------------------------------------------------
|
| Aucune de ces valeurs n'est lue de l'environnement, hormis
| `capture_enabled` : toute modification passe par un commit, et ce test est
| la garde de leurs bornes croisées.
|
| Les deux valeurs du worker `default` sont RECOPIÉES de la spec 100 § 10.4,
| où elles sont des valeurs de départ recalibrées par D33 du 23/09 : le commit
| qui les change chez `100` met ce test à jour.
|
*/

/** `MemoryMax` du worker `default`, en Mo (spec 100 § 10.4, drop-in systemd). */
const CURATION_WORKER_DEFAULT_MEMORY_MAX_MB = 512;

/** Mémoire laissée à PHP sous ce plafond, en Mo (spec 20 § 13.7). */
const CURATION_WORKER_PHP_RESERVE_MB = 128;

/** `--timeout` du worker `default`, en secondes (spec 100 § 10.4). */
const CURATION_WORKER_DEFAULT_TIMEOUT_S = 120;

/** `frame.crop_seconds` est un `unsignedSmallInteger` (spec 10 § 4.1). */
const CURATION_UNSIGNED_SMALL_INTEGER_MAX = 65_535;

/** Un original 4K (3 840 × 2 160, 8,3 Mpx) tient sous la surface admise. */
const CURATION_MIN_AREA_MPX = 9;

/**
 * « Pauses de plus de 60 s exclues » : la fenêtre d'inactivité de la décision
 * 10, recopiée de la spec 10 § 3.1 (colonne `curation_active_seconds`).
 */
const CURATION_DECISION_10_IDLE_SECONDS = 60;

/** Les onglets qu'un curateur peut ouvrir sur la même page sans 429 (§ 13.7). */
const CURATION_HEARTBEAT_TABS = 2;

/** La minute du limiteur, en secondes. */
const CURATION_SECONDS_PER_MINUTE = 60;

it('les bornes croisées de la configuration de curation tiennent', function (): void {
    $int = static fn (string $key): int => Config::integer('catalog.curation.'.$key);

    // Qualités WebP.
    $master = $int('webp.master_quality');
    $start = $int('webp.game_quality_start');
    $minimum = $int('webp.game_quality_min');
    $step = $int('webp.game_quality_step');

    expect($master)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(100);
    expect($minimum)->toBeGreaterThanOrEqual(1);
    expect($minimum)->toBeLessThanOrEqual($start);
    expect($start)->toBeLessThanOrEqual(100);
    expect($step)->toBeGreaterThanOrEqual(1);

    // Limites Imagick contre le worker `default`.
    expect($int('imagick.memory_mb') + $int('imagick.map_mb') + CURATION_WORKER_PHP_RESERVE_MB)
        ->toBeLessThanOrEqual(CURATION_WORKER_DEFAULT_MEMORY_MAX_MB);
    expect($int('imagick.memory_mb'))->toBeGreaterThanOrEqual(1);
    expect($int('imagick.map_mb'))->toBeGreaterThanOrEqual(1);
    expect($int('imagick.time_s'))->toBeGreaterThanOrEqual(1);
    expect($int('imagick.time_s'))->toBeLessThan(CURATION_WORKER_DEFAULT_TIMEOUT_S);
    expect($int('imagick.area_mpx'))->toBeGreaterThanOrEqual(CURATION_MIN_AREA_MPX);
    expect($int('imagick.width_px'))->toBeGreaterThanOrEqual(FrameGeometry::MASTER_WIDTH);
    expect($int('imagick.height_px'))->toBeGreaterThanOrEqual(FrameGeometry::MASTER_WIDTH);

    // Mesure du débit et original téléchargé.
    expect($int('crop_seconds_max'))->toBeGreaterThanOrEqual(1);
    expect($int('crop_seconds_max'))->toBeLessThanOrEqual(CURATION_UNSIGNED_SMALL_INTEGER_MAX);
    expect($int('tmdb_original_max_kilobytes'))->toBeGreaterThanOrEqual(1);

    // Limiteurs par utilisateur des gestes du back-office.
    expect($int('rate_limits.frame'))->toBeGreaterThanOrEqual(1);
    expect($int('rate_limits.curation'))->toBeGreaterThanOrEqual(1);
    expect($int('rate_limits.search'))->toBeGreaterThanOrEqual(1);

    // Import : durée de vie d'un aperçu à blanc, liste d'amorçage lisible.
    expect(Config::integer('catalog.import.preview_ttl_minutes'))->toBeGreaterThanOrEqual(1);
    expect(SeedList::path())->toBeReadableFile();

    // Éditeur de la banque : cache des visuels TMDB, rechargement partiel
    // pendant un traitement, délai d'alerte d'un traitement en panne.
    expect($int('images_cache_minutes'))->toBeGreaterThanOrEqual(1);
    expect($int('poll_seconds'))->toBeGreaterThanOrEqual(1);
    expect($int('stale_pending_minutes'))->toBeGreaterThanOrEqual(1);

    // Lot pilote (§ 10.3, § 10.4) : une fenêtre non vide par voie, des rangs
    // qui partent de 1, des seuils positifs, des heures déclarées jamais
    // négatives — zéro vaut « non déclaré ».
    foreach (CurationQueue::ENTRIES as $entry) {
        expect($int('pilot.composition.'.$entry))->toBeGreaterThanOrEqual(1);
        expect($int('pilot.first_rank.'.$entry))->toBeGreaterThanOrEqual(1);
    }

    expect($int('pilot.disqualify_hours'))->toBeGreaterThanOrEqual(1);
    expect($int('pilot.reserve_hours'))->toBeGreaterThanOrEqual(1);
    expect($int('pilot.j1_target_films'))->toBeGreaterThanOrEqual($int('pilot.size'));
    expect($int('pilot.volume_cap'))->toBeGreaterThanOrEqual($int('pilot.j1_target_films'));
    expect($int('pilot.weekly_curation_hours'))->toBeGreaterThanOrEqual(0);
    expect($int('pilot.horizon_weeks'))->toBeGreaterThanOrEqual(0);

    // La voie capture est fermée par défaut, et c'est la SEULE clé du bloc
    // lue de l'environnement.
    expect(Config::boolean('catalog.curation.capture_enabled'))->toBeFalse();

    $source = (string) file_get_contents(config_path('catalog.php'));
    $block = substr($source, (int) strpos($source, "'curation' => ["));

    preg_match_all("/env\\('([A-Z0-9_]+)'/", $block, $matches);

    expect($matches[1])->toBe(['CURATION_CAPTURE_ENABLED']);
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^CURATION_CAPTURE_ENABLED=$/m');
});

test('idle_seconds vaut soixante et heartbeat_seconds lui est inférieur', function (): void {
    $idle = Config::integer('catalog.curation.idle_seconds');
    $heartbeat = Config::integer('catalog.curation.heartbeat_seconds');

    // Une égalité, pas une borne : la décision 10 exclut les pauses de plus
    // de 60 s, et la valeur ne bouge jamais pendant un lot pilote.
    expect($idle)->toBe(CURATION_DECISION_10_IDLE_SECONDS);

    // Strictement inférieur : sans quoi aucun écart entre deux battements ne
    // tiendrait jamais dans la fenêtre, et le temps actif resterait nul.
    expect($heartbeat)->toBeGreaterThanOrEqual(1)
        ->and($heartbeat)->toBeLessThan($idle);
});

test('le limiteur du battement tolère deux onglets', function (): void {
    $heartbeat = Config::integer('catalog.curation.heartbeat_seconds');
    $perMinute = Config::integer('catalog.curation.rate_limits.heartbeat');

    // Deux onglets à la cadence du battement, sur une minute.
    $needed = CURATION_HEARTBEAT_TABS * (int) ceil(CURATION_SECONDS_PER_MINUTE / $heartbeat);

    expect($perMinute)->toBeGreaterThanOrEqual($needed);

    // Et le limiteur nommé lit bien cette valeur, par utilisateur et par
    // minute — jamais un littéral posé à côté.
    $curator = User::factory()->curator()->create();
    $request = Request::create('/admin/catalog/1/heartbeat', 'POST');
    $request->setUserResolver(fn (): User => $curator);

    $limiter = RateLimiter::limiter('admin-heartbeat');

    expect($limiter)->toBeInstanceOf(Closure::class);

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe($perMinute)
        ->and($limit->decaySeconds)->toBe(CURATION_SECONDS_PER_MINUTE)
        ->and($limit->key)->toBe((string) $curator->id);
});

test('la taille du pilote est la somme de ses deux compositions', function (): void {
    $composition = array_sum(array_map(
        static fn (string $entry): int => Config::integer('catalog.curation.pilot.composition.'.$entry),
        CurationQueue::ENTRIES,
    ));

    expect(Config::integer('catalog.curation.pilot.size'))->toBe($composition);
});
