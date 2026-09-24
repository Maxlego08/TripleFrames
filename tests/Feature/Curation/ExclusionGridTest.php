<?php

use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Enums\ReviewDecision;
use App\Support\Curation\ExclusionGrid;
use Illuminate\Support\Facades\Lang;

/*
|--------------------------------------------------------------------------
| La grille d'exclusion versionnée — spec 20 § 7.1 et § 7.2, contrat C14-bis
|--------------------------------------------------------------------------
|
| Une version publiée est FIGÉE : items, niveaux et drapeau `retroactive`.
| Une revue passée cite ses items par slug ; réécrire un libellé déjà cité
| détruirait sa valeur de preuve. Ces tests lisent la table publiée par
| réflexion — elle est privée, et c'est voulu : aucune API ne la rend
| modifiable, pas même pour un test.
|
*/

/**
 * La table des versions publiées, telle qu'écrite dans le dépôt.
 *
 * @return array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}>
 */
function exclusionGridVersions(): array
{
    /** @var array<int, array{retroactive: bool, items: list<array{slug: string, levels: list<int>}>}> $versions */
    $versions = (new ReflectionClassConstant(ExclusionGrid::class, 'VERSIONS'))->getValue();

    return $versions;
}

/**
 * Un calcul privé de la grille, appliqué à une table de versions fictive.
 */
function exclusionGridCompute(string $method, array $versions, int $version): mixed
{
    return (new ReflectionMethod(ExclusionGrid::class, $method))->invoke(null, $versions, $version);
}

/**
 * Le fichier de langue lui-même, jamais le `Translator` : c'est le contenu
 * versionné que la suite doit refuser.
 *
 * @return array<string, mixed>
 */
function exclusionGridLangFile(): array
{
    /** @var array<string, mixed> $lines */
    $lines = require lang_path('fr/admin.php');

    return $lines;
}

test('chaque item de chaque version a ses clés label et help dans lang/fr/admin.php', function (): void {
    $lines = exclusionGridLangFile();

    foreach (ExclusionGrid::versions() as $version) {
        $slugs = ExclusionGrid::slugs($version);

        foreach ($slugs as $slug) {
            foreach (['label' => ExclusionGrid::labelKey($slug, $version), 'help' => ExclusionGrid::helpKey($slug, $version)] as $leaf => $key) {
                $text = data_get($lines, "exclusion_grid.v{$version}.{$slug}.{$leaf}");

                expect($text)->toBeString("Feuille absente du fichier : {$key}")
                    ->and(trim((string) $text))->not->toBe('', "Feuille vide : {$key}")
                    ->and(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé introuvable : {$key}")
                    ->and(__($key, [], Locale::French->value))->toBe($text);
            }
        }

        // Ni plus ni moins : un item retiré de la grille ne laisse pas de
        // libellé orphelin, et une feuille par item seulement.
        $inFile = data_get($lines, "exclusion_grid.v{$version}");

        expect($inFile)->toBeArray()
            ->and(array_keys($inFile))->toEqualCanonicalizing($slugs);

        foreach ($inFile as $slug => $leaves) {
            expect(array_keys($leaves))->toEqualCanonicalizing(['label', 'help'], (string) $slug);
        }
    }

    // Aucune version sans grille publiée dans le dictionnaire. Seuls les
    // nœuds de version `v{n}` sont comparés : la famille sœur
    // `admin.exclusion_grid.retroactive.*` (C14-bis § 2, geste du J2, § 7.7)
    // vit à côté d'eux sans être une version — et c'est la seule admise.
    $nodes = array_map(strval(...), array_keys((array) data_get($lines, 'exclusion_grid')));
    $versionNodes = array_values(preg_grep('/^v\d+$/', $nodes) ?: []);

    expect($versionNodes)
        ->toEqualCanonicalizing(array_map(static fn (int $version): string => "v{$version}", ExclusionGrid::versions()))
        ->and(array_values(array_diff($nodes, $versionNodes)))->each->toBe('retroactive');
});

test('les clés vivent sous admin.exclusion_grid', function (): void {
    expect(ExclusionGrid::labelKey('no_title_card'))->toBe('admin.exclusion_grid.v1.no_title_card.label')
        ->and(ExclusionGrid::helpKey('no_title_card'))->toBe('admin.exclusion_grid.v1.no_title_card.help')
        ->and(ExclusionGrid::labelKey('no_lead_face', 1))->toBe('admin.exclusion_grid.v1.no_lead_face.label');

    foreach (ExclusionGrid::versions() as $version) {
        foreach (ExclusionGrid::slugs($version) as $slug) {
            expect(ExclusionGrid::labelKey($slug, $version))->toStartWith("admin.exclusion_grid.v{$version}.")
                ->and(ExclusionGrid::helpKey($slug, $version))->toStartWith("admin.exclusion_grid.v{$version}.");
        }
    }

    // Jamais un domaine `curation` : le back-office passe tout entier par le
    // domaine `admin` (spec 20 § 13.2).
    expect(is_file(lang_path('fr/curation.php')))->toBeFalse()
        ->and(Lang::hasForLocale('curation.exclusion_grid.v1.no_title_card', Locale::French->value))->toBeFalse();

    // Un slug hors de la version citée n'a pas de clé.
    expect(fn () => ExclusionGrid::labelKey('no_such_item'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ExclusionGrid::helpKey('no_title_card', 2))->toThrow(InvalidArgumentException::class);
});

test('l\'empreinte de la version 1 est figée', function (): void {
    $version1 = exclusionGridVersions()[1];

    // Changer un item, un niveau ou le drapeau de la version 1 casse cette
    // empreinte : la modification appartient à une NOUVELLE version, avec un
    // slug nouveau (spec 20 § 7.2, point 1).
    expect(hash('sha256', json_encode($version1, JSON_THROW_ON_ERROR)))
        ->toBe('43c628182f3fcaf9d2e39eab0a7462aeadaeccfb3f418dff9487f00140811e5a');

    expect(ExclusionGrid::slugs(1))->toBe([
        'no_poster_or_cover',
        'no_title_card',
        'no_studio_logo',
        'no_credits',
        'no_identifying_text',
        'no_watermark_or_copyright',
        'no_broadcaster_or_trailer_overlay',
        'no_promotional_still_or_portrait',
        'no_lead_face',
    ]);

    // `CURRENT_VERSION` vaut la plus grande clé de la table.
    expect(ExclusionGrid::CURRENT_VERSION)->toBe(max(ExclusionGrid::versions()));
});

test('la version 1 n\'est pas rétroactive', function (): void {
    expect(ExclusionGrid::isRetroactive(1))->toBeFalse()
        ->and(ExclusionGrid::retroactiveLevels(1))->toBe([])
        ->and(ExclusionGrid::retroactiveLevels())->toBe([]);

    // Première version : tous ses items sont des ajouts, et aucun ne sort
    // pourtant une image du jeu.
    expect(ExclusionGrid::addedSlugs(1))->toBe(ExclusionGrid::slugs(1));

    expect(fn () => ExclusionGrid::isRetroactive(99))->toThrow(InvalidArgumentException::class);
});

test('retroactiveLevels ne rend que les niveaux des items ajoutés', function (): void {
    // Une table fictive : la version 2 ajoute `c` pour motif juridique, la
    // version 3 ajoute `d` sans motif juridique.
    $versions = [
        1 => ['retroactive' => false, 'items' => [
            ['slug' => 'a', 'levels' => [1, 2, 3, 4, 5]],
            ['slug' => 'b', 'levels' => [1, 2]],
        ]],
        2 => ['retroactive' => true, 'items' => [
            ['slug' => 'a', 'levels' => [1, 2, 3, 4, 5]],
            ['slug' => 'b', 'levels' => [1, 2]],
            ['slug' => 'c', 'levels' => [4, 2]],
        ]],
        3 => ['retroactive' => false, 'items' => [
            ['slug' => 'a', 'levels' => [1, 2, 3, 4, 5]],
            ['slug' => 'b', 'levels' => [1, 2]],
            ['slug' => 'c', 'levels' => [4, 2]],
            ['slug' => 'd', 'levels' => [3]],
        ]],
    ];

    expect(exclusionGridCompute('addedSlugsIn', $versions, 2))->toBe(['c'])
        ->and(exclusionGridCompute('retroactiveLevelsIn', $versions, 2))->toBe([FrameLevel::Level2, FrameLevel::Level4]);

    // Ni les niveaux de `a` (1 à 5), ni ceux de `b` : seuls ceux de l'ajout.
    expect(exclusionGridCompute('retroactiveLevelsIn', $versions, 2))->not->toContain(FrameLevel::Level1)
        ->and(exclusionGridCompute('retroactiveLevelsIn', $versions, 2))->not->toContain(FrameLevel::Level5);

    // Une version prospective n'en rend aucun, même quand elle ajoute.
    expect(exclusionGridCompute('addedSlugsIn', $versions, 3))->toBe(['d'])
        ->and(exclusionGridCompute('retroactiveLevelsIn', $versions, 3))->toBe([]);

    // Et la table publiée, elle, n'a aucune version rétroactive.
    foreach (ExclusionGrid::versions() as $version) {
        expect(ExclusionGrid::retroactiveLevels($version))->toBe([]);
    }
});

test('decisionFor exige exactement les items applicables', function (): void {
    $version = ExclusionGrid::CURRENT_VERSION;
    $level3 = array_fill_keys(ExclusionGrid::slugsFor(FrameLevel::Level3, $version), true);
    $level1 = array_fill_keys(ExclusionGrid::slugsFor(FrameLevel::Level1, $version), true);

    // `no_lead_face` ne vaut qu'aux niveaux 1 et 2.
    expect(array_keys($level3))->not->toContain('no_lead_face')
        ->and(array_keys($level1))->toContain('no_lead_face');

    // L'ensemble exact, tout conforme : la seule revue passante.
    expect(ExclusionGrid::decisionFor($level3, FrameLevel::Level3, $version))->toBe(ReviewDecision::Passed)
        ->and(ExclusionGrid::decisionFor($level1, FrameLevel::Level1, $version))->toBe(ReviewDecision::Passed)
        ->and(ExclusionGrid::decisionFor(array_reverse($level3, true), FrameLevel::Level3, $version))->toBe(ReviewDecision::Passed);

    // Un point en défaut.
    expect(ExclusionGrid::decisionFor(['no_credits' => false] + $level3, FrameLevel::Level3, $version))->toBe(ReviewDecision::Rejected);

    // Un item manquant, un item en trop, aucune réponse : jamais passante.
    $missing = $level1;
    unset($missing['no_lead_face']);

    expect(ExclusionGrid::decisionFor($missing, FrameLevel::Level1, $version))->toBe(ReviewDecision::Rejected)
        ->and(ExclusionGrid::decisionFor($level1, FrameLevel::Level3, $version))->toBe(ReviewDecision::Rejected)
        ->and(ExclusionGrid::decisionFor([], FrameLevel::Level5, $version))->toBe(ReviewDecision::Rejected)
        ->and(ExclusionGrid::decisionFor(['inconnu' => true] + $level3, FrameLevel::Level3, $version))->toBe(ReviewDecision::Rejected);

    // Seul `true` est conforme : ni `1`, ni `'1'`, ni `null`.
    foreach ([1, '1', 'true', null] as $loose) {
        expect(ExclusionGrid::decisionFor(['no_credits' => $loose] + $level3, FrameLevel::Level3, $version))
            ->toBe(ReviewDecision::Rejected, var_export($loose, true));
    }
});
