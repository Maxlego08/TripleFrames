<?php

use App\Enums\FrameLevel;
use App\Http\Controllers\Admin\GuideController;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\ExclusionGrid;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| La page « premiers pas du curateur » — spec 20 § 13.6, L20-18
|--------------------------------------------------------------------------
|
| Livrée avec l'outil et condition du lot pilote. La page ne recopie aucun
| texte normatif : l'échelle 1-5 (§ 6.5), la grille d'exclusion COURANTE
| (§ 7.1) et les raccourcis de débit (§ 6.4) y sont rendus depuis leurs
| propres clés, que le serveur lui envoie. Ces tests vérifient qu'elle reçoit
| des CLÉS — jamais des textes —, que ce sont celles des écrans qui les
| affichent déjà, et que chacune a son texte dans le dictionnaire servi.
|
| L'accès (porte seule, sans `can:`, ligne 9) est joué par la matrice des
| capacités, `AuthorizationMatrixTest`.
|
*/

beforeEach(function (): void {
    // Le rendu de la page, pas la présence d'un fichier dans le manifeste Vite.
    $this->withoutVite();
});

/**
 * Les props de la page, telles que le serveur les rend : le dictionnaire
 * partagé est une table plate `clé → texte` dont les clés portent des
 * points, que la notation pointée d'`AssertableInertia` ne sait pas lire.
 *
 * @return array<string, mixed>
 */
function guidePageProps(TestResponse $response): array
{
    $page = $response->viewData('page');

    expect($page)->toBeArray()
        ->and($page['props'] ?? null)->toBeArray();

    /** @var array{props: array<string, mixed>} $page */
    return $page['props'];
}

/**
 * Un nombre attendu, quelle que soit sa forme après le passage en JSON des
 * props : `64.0` y devient `64`, et la page ne voit qu'un `number`.
 *
 * @return Closure(mixed): bool
 */
function guidePageNumber(float $expected): Closure
{
    return static fn (mixed $actual): bool => (is_int($actual) || is_float($actual))
        && abs((float) $actual - $expected) < 1e-9;
}

test('la page de premiers pas rend l\'échelle, la grille courante et les raccourcis depuis leurs clés', function (): void {
    $levels = array_map(static fn (FrameLevel $level): int => $level->value, FrameLevel::cases());
    $slugs = ExclusionGrid::slugs();

    $response = $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.guide'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('admin/guide')
        ->has('scale', count($levels))
        ->where('grid.version', ExclusionGrid::CURRENT_VERSION)
        ->has('grid.items', count($slugs))
        ->where('shortcuts', GuideController::SHORTCUT_KEYS)
        ->has('floor'));

    $props = guidePageProps($response);
    $keys = [];

    // L'échelle : les cinq niveaux, du plus cryptique au plus évident, chacun
    // avec les deux clés que le sélecteur de niveau affiche déjà.
    expect(array_column($props['scale'], 'level'))->toBe($levels);

    foreach ($props['scale'] as $row) {
        expect($row)->toBe([
            'level' => $row['level'],
            'label_key' => "admin.level.{$row['level']}.label",
            'guide_key' => "admin.level.{$row['level']}.guide",
        ]);

        $keys[] = $row['label_key'];
        $keys[] = $row['guide_key'];
    }

    // La grille COURANTE, item par item et dans l'ordre de la revue : ses
    // clés gelées avec la version, et les niveaux auxquels chaque item
    // s'applique — le visage du personnage principal aux niveaux 1 et 2
    // seulement.
    expect(array_column($props['grid']['items'], 'slug'))->toBe($slugs);

    foreach ($props['grid']['items'] as $item) {
        $applies = array_values(array_filter(
            $levels,
            static fn (int $level): bool => ExclusionGrid::appliesTo($item['slug'], FrameLevel::from($level)),
        ));

        expect($item)->toBe([
            'slug' => $item['slug'],
            'levels' => $applies,
            'label_key' => ExclusionGrid::labelKey($item['slug'], ExclusionGrid::CURRENT_VERSION),
            'help_key' => ExclusionGrid::helpKey($item['slug'], ExclusionGrid::CURRENT_VERSION),
        ]);

        $keys[] = $item['label_key'];
        $keys[] = $item['help_key'];
    }

    $leadFace = collect($props['grid']['items'])->firstWhere('slug', 'no_lead_face');

    expect($leadFace)->not->toBeNull()
        ->and($leadFace['levels'])->toBe([FrameLevel::Level1->value, FrameLevel::Level2->value]);

    // Les raccourcis : les trois gestes de débit, depuis les clés du rappel
    // de l'éditeur.
    expect($props['shortcuts'])->toBe([
        'admin.shortcuts.classify',
        'admin.shortcuts.neighbour',
        'admin.shortcuts.pass',
    ]);

    $keys = [...$keys, ...$props['shortcuts']];

    // Depuis leurs clés : chaque valeur envoyée est une clé du domaine
    // `admin`, jamais un texte, et chacune a son texte français dans le
    // dictionnaire que la page reçoit — une clé sans texte s'afficherait
    // brute.
    $translations = $props['translations'];

    expect($translations)->toBeArray();

    foreach ($keys as $key) {
        expect($key)->toStartWith('admin.')
            ->and(array_key_exists($key, $translations))->toBeTrue("clé absente du dictionnaire : {$key}")
            ->and($translations[$key])->toBeString()->not->toBe('')->not->toBe($key);
    }

    // Et aucune de ces valeurs n'est déjà un texte : la page traduit, le
    // serveur ne pré-formate rien (règle 4).
    $texts = array_map(static fn (string $key): string => $translations[$key], $keys);

    expect(array_intersect($keys, $texts))->toBe([]);
});

test('le plancher de recadrage de la page de premiers pas est celui que la plateforme applique', function (): void {
    $curator = User::factory()->curator()->create();

    // Le réglage par défaut : 80 % de la largeur et de la hauteur, donc 64 %
    // de la surface au plus, et 640 pixels de large au moins.
    $this->actingAs($curator)
        ->get(route('admin.guide'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/guide')
            ->where('floor.max_width_percent', PlatformLimits::DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT)
            ->where('floor.max_surface_percent', guidePageNumber(64.0))
            ->where('floor.min_width_px', PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX));

    // Recalibré au lot pilote, au plus à 83 (§ 5.2) : la page suit, sans
    // qu'aucun chiffre ne soit écrit dans un texte.
    platformLimitsConfigure(['frame_crop_max_width_percent' => 83, 'frame_crop_min_width_px' => 704]);

    $this->actingAs($curator)
        ->get(route('admin.guide'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('floor.max_width_percent', 83)
            ->where('floor.max_surface_percent', guidePageNumber(68.89))
            ->where('floor.min_width_px', 704));
});
