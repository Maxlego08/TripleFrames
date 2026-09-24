<?php

use App\Support\Frames\FrameGeometry;
use Illuminate\Support\Facades\Config;

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

    // La voie capture est fermée par défaut, et c'est la SEULE clé du bloc
    // lue de l'environnement.
    expect(Config::boolean('catalog.curation.capture_enabled'))->toBeFalse();

    $source = (string) file_get_contents(config_path('catalog.php'));
    $block = substr($source, (int) strpos($source, "'curation' => ["));

    preg_match_all("/env\\('([A-Z0-9_]+)'/", $block, $matches);

    expect($matches[1])->toBe(['CURATION_CAPTURE_ENABLED']);
    expect((string) file_get_contents(base_path('.env.example')))->toMatch('/^CURATION_CAPTURE_ENABLED=$/m');
});
