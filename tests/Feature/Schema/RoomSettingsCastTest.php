<?php

use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\SavedConfig;
use App\Models\Theme;
use App\Settings\RoomSettings;
use Illuminate\Support\Facades\DB;

/**
 * Les deux gardes que le § 1.6 nomme et que le § 3.7 suppose.
 *
 * 1. LE CAST NE NORMALISE RIEN, NI LA CHARGE UTILE, NI SA VERSION.
 *    `Model::save()` appelle `mergeAttributesFromClassCasts()`, qui rappelle
 *    `set()` sur l'objet mis en cache par `get()` : un simple renommage de
 *    configuration suffit donc à réécrire la colonne. Le test est écrit dans sa
 *    forme SÉVÈRE — la lecture précède le `touch()` —, parce que c'est la seule
 *    qui exerce réellement le cache de cast, et la seule que traverse un écran de
 *    configurations sauvegardées.
 * 2. LES DEUX MOITIÉS DE L'UNIQUE `belongsToMany` EXPOSENT LE MÊME PIVOT.
 *    Une asymétrie n'y lève rien : elle rend une collection vide, en silence.
 */
it('leaves a stored payload byte for byte identical across a read and a touch', function () {
    $config = SavedConfig::factory()->create();

    // Charge utile canonique en tous points SAUF `capacity`, hors de la borne
    // courante : exactement le cas « borne resserrée » du § 1.6. `fromStorage()`
    // n'écrête rien, donc la valeur doit survivre — la normalisation est un appel
    // explicite de l'action de chargement, jamais un geste du cast.
    $payload = RoomSettings::defaults()->toPayload();
    $payload['capacity'] = 99;
    $stored = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    DB::table('saved_config')
        ->where('id', $config->id)
        ->update(['settings' => $stored, 'settings_version' => 1]);

    $reloaded = SavedConfig::query()->findOrFail($config->id);

    // LA LECTURE D'ABORD : c'est elle qui met l'objet en cache de cast.
    expect($reloaded->settings->capacity)->toBe(99);
    expect($reloaded->settings->sourceVersion)->toBe(1);

    $reloaded->touch();

    $row = DB::table('saved_config')->where('id', $config->id)->first();

    expect($row)->not->toBeNull();
    expect($row->settings)->toBe($stored);
    expect((int) $row->settings_version)->toBe(1);
});

it('writes the version the instance comes from, never the constant', function () {
    // Tant que `VERSION` vaut 1 les deux valeurs coïncident : l'assertion porte donc
    // sur la PROVENANCE, seule propriété qui restera vraie au premier passage à 2.
    $fromInput = RoomSettings::fromInput([]);
    $fromStorage = RoomSettings::fromStorage(RoomSettings::defaults()->toPayload(), 1);

    expect($fromInput->sourceVersion)->toBe(RoomSettings::VERSION);
    expect($fromStorage->sourceVersion)->toBe(1);
    expect(RoomSettings::normalize([], 1)['settings']->sourceVersion)->toBe(RoomSettings::VERSION);
    expect(RoomSettings::defaults()->sourceVersion)->toBe(RoomSettings::VERSION);
});

it('exposes the same pivot on both halves of the only belongsToMany', function () {
    $movieSide = (new Movie)->themes();
    $themeSide = (new Theme)->movies();

    expect($themeSide->getPivotColumns())->toBe($movieSide->getPivotColumns());
    expect($movieSide->getPivotClass())->toBe($themeSide->getPivotClass());
});

it('hydrates the movie theme pivot with the types the canonical model documents', function () {
    $movieTheme = MovieTheme::factory()->create();

    $movie = Movie::query()->with('themes')->findOrFail($movieTheme->movie_id);
    $theme = Theme::query()->with('movies')->findOrFail($movieTheme->theme_id);

    foreach ([$movie->themes->first()?->pivot, $theme->movies->first()?->pivot] as $pivot) {
        expect($pivot)->not->toBeNull();
        expect($pivot?->is_auto)->toBeBool();
        expect($pivot?->is_active)->toBeBool();
    }
});
