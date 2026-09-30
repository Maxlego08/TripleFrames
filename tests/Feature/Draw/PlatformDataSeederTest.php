<?php

use App\Enums\Locale;
use App\Enums\SettingPresetKey;
use App\Enums\ThemeKind;
use App\Models\SettingPreset;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Settings\RoomSettings;
use App\Settings\SettingPresetCatalog;
use Database\Seeders\PlatformDataSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Seeder de plateforme scindé — spec 30 § 12.5, lot L30-7, contrat C18-bis
|--------------------------------------------------------------------------
|
| Le hook de déploiement rejoue `PlatformDataSeeder` à chaque déploiement
| (étape 6). Il ne RÉCONCILIE que `setting_preset`, dont il est le seul
| écrivain ; thèmes, libellés et collections sont AMORCÉS en insertion si
| absent, pour qu'une édition faite en back-office survive au déploiement.
| `sort_order` suit les blocs de famille (`bloc × 100 + rang`), et une base
| amorcée par l'ancien ordre séquentiel est réalignée une fois par migration.
|
| Les blocs attendus sont ceux de la spec, écrits ici en clair plutôt que lus
| dans le seeder : un test qui relirait `sortOrderFor()` pour fixer son
| attendu ne prouverait rien.
|
*/

/**
 * Blocs de famille de `theme.sort_order`, repris de la spec 30 § 12.5.
 *
 * @return array<string, int>
 */
function platformSeederFamilyBlocks(): array
{
    return [
        ThemeKind::Genre->value => 1,
        ThemeKind::Studio->value => 2,
        ThemeKind::Decade->value => 3,
        ThemeKind::Language->value => 4,
        ThemeKind::Difficulty->value => 5,
        ThemeKind::Saga->value => 6,
    ];
}

/**
 * Photographie brute des tables amorcées, horodatages compris : toute
 * réécriture, même à l'identique, déplacerait `updated_at`.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function platformSeederSnapshot(): array
{
    $snapshot = [];

    foreach (['theme', 'theme_label', 'collection'] as $table) {
        $snapshot[$table] = DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->values()
            ->all();
    }

    return $snapshot;
}

/**
 * Joue la migration de réalignement comme le ferait `migrate` au déploiement.
 */
function platformSeederRealign(): void
{
    $files = glob(database_path('migrations/*_realign_platform_theme_sort_order.php')) ?: [];

    expect($files)->toHaveCount(1);

    /** @var Migration $migration */
    $migration = require $files[0];
    $migration->up();
}

test('rejouer le seeder de plateforme ne réécrit ni un thème, ni un libellé, ni une saga édités en back-office', function (): void {
    $this->seed(PlatformDataSeeder::class);

    // Un thème édité : dépublié, déplacé, sa règle corrigée.
    $theme = Theme::query()->where('key', 'genre.action')->sole();
    $theme->is_published = false;
    $theme->sort_order = 999;
    $theme->rule_value = '99999';
    $theme->rule_negated = true;
    $theme->save();

    // Un libellé édité.
    $label = ThemeLabel::query()
        ->where('theme_id', Theme::query()->where('key', 'studio.ghibli')->sole()->id)
        ->where('locale', Locale::French->value)
        ->sole();
    $label->label = 'Ghibli, édité en back-office';
    $label->save();

    $before = platformSeederSnapshot();

    // Une heure plus tard : une réécriture, même à l'identique, changerait `updated_at`.
    $this->travel(1)->hours();
    $this->seed(PlatformDataSeeder::class);

    expect(platformSeederSnapshot())->toBe($before);

    $theme->refresh();
    expect($theme->is_published)->toBeFalse();
    expect($theme->sort_order)->toBe(999);
    expect($theme->rule_value)->toBe('99999');
    expect($theme->rule_negated)->toBeTrue();
    expect($label->fresh()?->label)->toBe('Ghibli, édité en back-office');

    // Aucune saga n'est livrée au jalon 1 : l'assertion sur une saga livrée
    // arrive avec la liste par défaut de S4 (lot L30-9). La photographie couvre
    // déjà `collection`, que ce passage n'a pas touchée.
});

test('rejouer le seeder de plateforme réconcilie les quatre presets', function (): void {
    $this->seed(PlatformDataSeeder::class);

    expect(SettingPresetKey::cases())->toHaveCount(4);

    // Un chiffrage périmé et une position déplacée sur un preset, un autre disparu.
    $classic = SettingPreset::query()->where('key', SettingPresetKey::Classic)->sole();
    $classic->settings = SettingPresetCatalog::settingsFor(SettingPresetKey::Hardcore);
    $classic->position = 9;
    $classic->save();

    SettingPreset::query()->where('key', SettingPresetKey::Discovery)->delete();

    $this->seed(PlatformDataSeeder::class);

    expect(SettingPreset::query()->count())->toBe(count(SettingPresetKey::cases()));

    foreach (SettingPresetKey::cases() as $key) {
        $preset = SettingPreset::query()->where('key', $key)->sole();

        expect($preset->position)->toBe(SettingPresetCatalog::positionFor($key));
        expect($preset->settings->equals(SettingPresetCatalog::settingsFor($key)))->toBeTrue();
        expect($preset->settings_version)->toBe(RoomSettings::VERSION);
    }
});

test('un thème livré absent est inséré au passage suivant du seeder', function (): void {
    $this->seed(PlatformDataSeeder::class);

    // Libellés compris, par la cascade de `theme_label.theme_id`.
    Theme::query()->where('key', 'decade.1930')->sole()->delete();

    expect(Theme::query()->where('key', 'decade.1930')->exists())->toBeFalse();

    // Sur un thème PRÉSENT, un libellé livré absent : amorcé par
    // `(theme_id, locale)`, sans réécrire ni le libellé présent ni le thème.
    $comedy = Theme::query()->where('key', 'genre.comedy')->sole();
    ThemeLabel::query()
        ->where('theme_id', $comedy->id)
        ->where('locale', Locale::French->value)
        ->delete();

    $comedyRow = (array) DB::table('theme')->where('id', $comedy->id)->first();
    $englishRow = (array) DB::table('theme_label')
        ->where('theme_id', $comedy->id)
        ->where('locale', Locale::English->value)
        ->first();

    // Une heure plus tard : une réécriture, même à l'identique, changerait `updated_at`.
    $this->travel(1)->hours();
    $this->seed(PlatformDataSeeder::class);

    $french = ThemeLabel::query()
        ->where('theme_id', $comedy->id)
        ->where('locale', Locale::French->value)
        ->sole();

    expect($french->label)->toBe('Comédie');
    expect((array) DB::table('theme_label')
        ->where('theme_id', $comedy->id)
        ->where('locale', Locale::English->value)
        ->first())->toBe($englishRow);
    expect((array) DB::table('theme')->where('id', $comedy->id)->first())->toBe($comedyRow);

    $theme = Theme::query()->with('labels')->where('key', 'decade.1930')->sole();

    expect($theme->theme_kind)->toBe(ThemeKind::Decade);
    expect($theme->rule_value)->toBe('1930');
    expect($theme->rule_negated)->toBeFalse();
    expect($theme->is_published)->toBeTrue();
    expect($theme->sort_order)->toBe(301);
    expect($theme->hasEveryLocaleLabel())->toBeTrue();

    // Rien d'autre n'a été inséré : une ligne par thème livré, aucun doublon.
    $keys = Theme::query()->orderBy('key')->pluck('key')->all();
    $delivered = PlatformDataSeeder::deliveredThemeKeys();
    sort($delivered);

    expect($keys)->toBe($delivered);
    expect(ThemeLabel::query()->count())->toBe(count($delivered) * count(Locale::cases()));
});

test('un thème livré naît avec son libellé dans chaque locale activée', function (): void {
    $this->seed(PlatformDataSeeder::class);

    $themes = Theme::query()->with('labels')->get();

    expect($themes)->toHaveCount(count(PlatformDataSeeder::deliveredThemeKeys()));

    foreach ($themes as $theme) {
        expect($theme->missingLabelLocales())->toBe([], "Le thème [{$theme->key}] naît sans libellé dans une locale activée.");
        expect($theme->labels)->toHaveCount(count(Locale::cases()));

        foreach ($theme->labels as $label) {
            expect(trim($label->label))->not->toBe('', "Le thème [{$theme->key}] naît avec un libellé vide en [{$label->locale->value}].");
        }
    }
});

test('un thème livré naît dans le bloc de sa famille', function (): void {
    $this->seed(PlatformDataSeeder::class);

    $blocks = platformSeederFamilyBlocks();

    foreach (Theme::query()->get() as $theme) {
        expect(intdiv($theme->sort_order, 100))->toBe(
            $blocks[$theme->theme_kind->value],
            "Le thème [{$theme->key}] ({$theme->sort_order}) naît hors du bloc de sa famille.",
        );
        expect($theme->sort_order % 100)->toBeGreaterThanOrEqual(1);
        expect($theme->sort_order)->toBe(PlatformDataSeeder::sortOrderFor($theme->key));
    }

    $sortOrder = fn (string $key): int => Theme::query()->where('key', $key)->sole()->sort_order;

    // Premier de chaque famille, et le rang d'une décennie : (année − 1930) / 10 + 1.
    expect($sortOrder('genre.animation'))->toBe(101);
    expect($sortOrder('studio.disney'))->toBe(201);
    expect($sortOrder('studio.ghibli'))->toBe(203);
    expect($sortOrder('decade.1930'))->toBe(301);
    expect($sortOrder('decade.1970'))->toBe(305);
    expect($sortOrder('decade.2020'))->toBe(310);
    expect($sortOrder('language.international'))->toBe(401);
    expect($sortOrder('difficulty.very_easy'))->toBe(501);
    expect($sortOrder('difficulty.very_hard'))->toBe(505);

    // Les décennies ajoutées plus tard ont déjà leur place, entre 1930 et 1970.
    expect(PlatformDataSeeder::sortOrderFor('decade.1940'))->toBe(302);
    expect(PlatformDataSeeder::sortOrderFor('decade.1960'))->toBe(304);

    // L'ordre d'affichage (`sort_order` puis `key`) range les familles dans l'ordre de leurs blocs.
    $displayed = Theme::query()->orderBy('sort_order')->orderBy('key')->get()
        ->map(fn (Theme $theme): int => $blocks[$theme->theme_kind->value])
        ->all();
    $grouped = $displayed;
    sort($grouped);

    expect($displayed)->toBe($grouped);
});

test('un thème livré inséré après coup se range dans le bloc de sa famille, y compris sur une base amorcée par l\'ancien seeder', function (): void {
    // Base amorcée par l'ancien seeder : ordre séquentiel à partir de 1, dans
    // l'ordre de ses définitions, sans `decade.1930`, le thème livré après coup.
    $this->seed(PlatformDataSeeder::class);
    Theme::query()->where('key', 'decade.1930')->sole()->delete();

    $legacy = array_values(array_diff(PlatformDataSeeder::deliveredThemeKeys(), ['decade.1930']));

    foreach ($legacy as $index => $key) {
        DB::table('theme')->where('key', $key)->update(['sort_order' => $index + 1]);
    }

    // Un thème créé en back-office : sa clé n'est pas livrée, la migration ne le touche pas.
    Theme::factory()->genre(37, 'western')->sortedAt(7)->create();

    // Migration jouée, deux fois : elle est idempotente.
    platformSeederRealign();
    $afterFirstPass = platformSeederSnapshot();
    platformSeederRealign();

    expect(platformSeederSnapshot())->toBe($afterFirstPass);
    expect(Theme::query()->where('key', 'genre.western')->sole()->sort_order)->toBe(7);

    foreach ($legacy as $key) {
        expect(Theme::query()->where('key', $key)->sole()->sort_order)->toBe(PlatformDataSeeder::sortOrderFor($key));
    }

    // Puis insertion au passage suivant du seeder.
    $this->seed(PlatformDataSeeder::class);

    $inserted = Theme::query()->where('key', 'decade.1930')->sole();
    expect($inserted->sort_order)->toBe(301);

    $displayed = Theme::query()
        ->whereIn('key', PlatformDataSeeder::deliveredThemeKeys())
        ->orderBy('sort_order')
        ->orderBy('key')
        ->pluck('key')
        ->all();

    expect($displayed)->toBe(PlatformDataSeeder::deliveredThemeKeys());

    // Le thème inséré se range entre le dernier studio et la décennie suivante.
    $position = array_search('decade.1930', $displayed, true);
    expect($displayed[$position - 1])->toBe('studio.ghibli');
    expect($displayed[$position + 1])->toBe('decade.1970');
});
