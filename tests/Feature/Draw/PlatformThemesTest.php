<?php

use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Models\Collection;
use App\Models\Theme;
use App\Support\Catalog\ThemeRules;
use Database\Seeders\PlatformDataSeeder;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Thèmes livrés — spec 30 § 12.2 à 12.4, lot L30-9 (J1 depuis D43 du 01/10)
|--------------------------------------------------------------------------
|
| Les valeurs attendues sont celles de la spec, écrites ici en clair et jamais
| relues dans le seeder : un test qui relirait ses définitions pour fixer son
| attendu ne prouverait rien. Les identifiants TMDB sont publics et ont été
| vérifiés par le porteur le 01/10, hors CI.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->seed(PlatformDataSeeder::class);
});

/**
 * Les thèmes ajoutés par L30-9, nés non publiés (spec 30 § 12.3).
 *
 * @return list<string>
 */
function platformThemesAddedByL309(): array
{
    return [
        'studio.marvel', 'studio.dc',
        'decade.1940', 'decade.1950', 'decade.1960',
        'language.anime',
        'saga.star-wars', 'saga.harry-potter', 'saga.lord-of-the-rings', 'saga.james-bond',
        'saga.indiana-jones', 'saga.back-to-the-future', 'saga.jurassic-park', 'saga.toy-story',
        'saga.pirates-of-the-caribbean', 'saga.shrek', 'saga.avatar', 'saga.iron-man',
    ];
}

function platformTheme(string $key): Theme
{
    return Theme::query()->with('labels')->where('key', $key)->sole();
}

/**
 * @return array<string, string> locale => libellé
 */
function platformThemeLabels(Theme $theme): array
{
    $labels = [];

    foreach ($theme->labels as $label) {
        $labels[$label->locale->value] = $label->label;
    }

    ksort($labels);

    return $labels;
}

it('les décennies livrées vont de 1930 à 2020 sans trou', function (): void {
    $decades = Theme::query()
        ->where('theme_kind', ThemeKind::Decade)
        ->orderBy('sort_order')
        ->pluck('rule_value')
        ->all();

    expect($decades)->toBe(['1930', '1940', '1950', '1960', '1970', '1980', '1990', '2000', '2010', '2020']);

    foreach ([1940, 1950, 1960] as $start) {
        $theme = platformTheme('decade.'.$start);

        expect(platformThemeLabels($theme))->toBe(['en' => $start.'s', 'fr' => 'Années '.$start]);
        expect($theme->sort_order)->toBe(300 + intdiv($start - 1930, 10) + 1);
    }
});

it('Pixar, Ghibli et Marvel sont des thèmes studio, jamais des sagas', function (): void {
    foreach (['studio.pixar' => '3', 'studio.ghibli' => '10342', 'studio.marvel' => '420'] as $key => $rule) {
        $theme = platformTheme($key);

        expect($theme->theme_kind)->toBe(ThemeKind::Studio);
        expect($theme->rule_value)->toBe($rule);
    }

    expect(Theme::query()
        ->where('theme_kind', ThemeKind::Saga)
        ->where(fn ($query) => $query
            ->where('key', 'like', '%pixar%')
            ->orWhere('key', 'like', '%ghibli%')
            ->orWhere('key', 'like', '%marvel%'))
        ->exists())->toBeFalse();
});

it('Marvel est livré sur la société 420 et naît non publié', function (): void {
    $marvel = platformTheme('studio.marvel');

    expect($marvel->rule_value)->toBe('420');
    expect($marvel->rule_negated)->toBeFalse();
    expect($marvel->is_published)->toBeFalse();
    expect($marvel->sort_order)->toBe(204);
    expect(platformThemeLabels($marvel))->toBe(['en' => 'Marvel Studios', 'fr' => 'Marvel Studios']);
});

it('DC est livré sur ses trois sociétés et naît non publié', function (): void {
    $dc = platformTheme('studio.dc');

    expect($dc->rule_value)->toBe('429,128064,184898');
    expect(ThemeRules::isValidStudioRule((string) $dc->rule_value))->toBeTrue();
    expect(ThemeRules::studioCompanyIds($dc->rule_value))->toBe([429, 128_064, 184_898]);
    expect($dc->rule_negated)->toBeFalse();
    expect($dc->is_published)->toBeFalse();
    expect($dc->sort_order)->toBe(205);
    expect(platformThemeLabels($dc))->toBe(['en' => 'DC', 'fr' => 'DC']);
});

it('studio.disney reste livré sur la seule société 2 : sa correction est un geste du back-office', function (): void {
    $disney = platformTheme('studio.disney');

    expect($disney->rule_value)->toBe('2');
    expect($disney->is_published)->toBeTrue();
});

it('le thème des animés est un thème de langue sur ja', function (): void {
    $anime = platformTheme('language.anime');

    expect($anime->theme_kind)->toBe(ThemeKind::Language);
    expect($anime->rule_value)->toBe('ja');
    expect($anime->rule_negated)->toBeFalse();
    expect($anime->is_published)->toBeFalse();
    expect($anime->sort_order)->toBe(402);
    expect(platformThemeLabels($anime))->toBe(['en' => 'Japanese animation', 'fr' => 'Animés japonais']);
});

it('chaque thème ajouté par L30-9 naît non publié et les thèmes déjà livrés restent publiés', function (): void {
    $added = platformThemesAddedByL309();

    foreach (Theme::query()->get() as $theme) {
        expect($theme->is_published)->toBe(
            ! in_array($theme->key, $added, true),
            "Le thème [{$theme->key}] naît avec une publication contraire à la spec 30 § 12.3.",
        );
    }

    expect(Theme::query()->whereIn('key', $added)->count())->toBe(count($added));
});

it('les douze sagas sont livrées avec leur collection et leurs libellés dans chaque locale', function (): void {
    /** @var array<string, array{0: int, 1: string, 2: string, 3: string}> $expected */
    $expected = [
        'saga.star-wars' => [10, 'Star Wars Collection', 'Star Wars', 'Star Wars'],
        'saga.harry-potter' => [1241, 'Harry Potter Collection', 'Harry Potter', 'Harry Potter'],
        'saga.lord-of-the-rings' => [119, 'The Lord of the Rings Collection', 'The Lord of the Rings', 'Le Seigneur des Anneaux'],
        'saga.james-bond' => [645, 'James Bond Collection', 'James Bond', 'James Bond'],
        'saga.indiana-jones' => [84, 'Indiana Jones Collection', 'Indiana Jones', 'Indiana Jones'],
        'saga.back-to-the-future' => [264, 'Back to the Future Collection', 'Back to the Future', 'Retour vers le futur'],
        'saga.jurassic-park' => [328, 'Jurassic Park Collection', 'Jurassic Park', 'Jurassic Park'],
        'saga.toy-story' => [10_194, 'Toy Story Collection', 'Toy Story', 'Toy Story'],
        'saga.pirates-of-the-caribbean' => [295, 'Pirates of the Caribbean Collection', 'Pirates of the Caribbean', 'Pirates des Caraïbes'],
        'saga.shrek' => [2150, 'Shrek Collection', 'Shrek', 'Shrek'],
        'saga.avatar' => [87_096, 'Avatar Collection', 'Avatar', 'Avatar'],
        'saga.iron-man' => [131_292, 'Iron Man Collection', 'Iron Man', 'Iron Man'],
    ];

    expect(Theme::query()->where('theme_kind', ThemeKind::Saga)->orderBy('sort_order')->pluck('key')->all())
        ->toBe(array_keys($expected));

    foreach ($expected as $key => [$tmdbId, $name, $english, $french]) {
        $saga = platformTheme($key);
        $collection = Collection::query()->where('tmdb_id', $tmdbId)->sole();

        // `rule_value` est un `collection.id` LOCAL, jamais l'identifiant TMDB.
        expect($saga->rule_value)->toBe((string) $collection->id, "La saga [{$key}] ne désigne pas sa collection.");
        expect($collection->name)->toBe($name);
        expect($saga->rule_negated)->toBeFalse();
        expect(platformThemeLabels($saga))->toBe(['en' => $english, 'fr' => $french]);
    }
});

it('chaque thème livré porte un libellé non vide dans chaque locale activée', function (): void {
    $locales = array_map(static fn (Locale $locale): string => $locale->value, Locale::cases());
    sort($locales);

    foreach (Theme::query()->with('labels')->get() as $theme) {
        $labels = platformThemeLabels($theme);

        expect(array_keys($labels))->toBe($locales, "Le thème [{$theme->key}] manque d'un libellé.");

        foreach ($labels as $label) {
            expect(trim($label))->not->toBe('');
        }
    }
});

it('la collection livrée d\'une saga est prise sans doublon par le catalogue de démonstration', function (): void {
    $collection = PlatformDataSeeder::deliveredSagaCollection('saga.toy-story');

    expect($collection->tmdb_id)->toBe(10_194);
    expect(platformTheme('saga.toy-story')->rule_value)->toBe((string) $collection->id);
    expect(Collection::query()->where('tmdb_id', 10_194)->count())->toBe(1);

    expect(fn () => PlatformDataSeeder::deliveredSagaCollection('saga.matrix'))
        ->toThrow(InvalidArgumentException::class);
});
