<?php

use Database\Factories\MovieFactory;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Frontières du tirage — spec 30, lots L30-1 (puis L30-4)
|--------------------------------------------------------------------------
|
| La répartition nominale `N` → niveaux (`2 → 1,5`, `3 → 1,3,5`,
| `4 → 1,2,4,5`, `5 → 1..5`) n'a qu'un domicile : `FrameLevelCoverage`
| (spec 30 § 2, spec 10 § 15, E10-67). Avant elle, trois copies vivaient dans
| les fabriques et le seeder de démonstration ; une quatrième, oubliée, ferait
| diverger le tirage des fixtures qui le prouvent sans qu'aucun test ne
| rougisse.
|
| Le balayage lit le CODE, jamais les commentaires : un docblock qui cite la
| table pour l'expliquer n'est pas une seconde table. PHP est lu au
| tokeniseur ; TypeScript, sans tokeniseur disponible ici, est dépouillé de
| ses commentaires par expression régulière.
|
| Deux formes sont reconnues, niveaux écrits en entiers ou en cas de
| `FrameLevel` — qualifiés, ou `self::` / `static::` pour une copie logée
| dans l'enum lui-même —, virgule finale admise (Pint et oxfmt l'imposent à
| tout tableau sur plusieurs lignes) :
|
| 1. une ENTRÉE de table — `N => [niveaux nominaux de N]` (PHP) ou
|    `N: [...]` (TypeScript), `default` valant l'entrée de `N` = 3 —,
|    partout ;
| 2. la liste `Level1, Level2, Level4, Level5`, signature propre à la
|    répartition de `N` = 4, dans `app/`, `database/` et `resources/js/` :
|    les tests peuvent légitimement composer une banque d'images à ces
|    niveaux pour prouver un repli.
|
| Exclusions nommées : `FrameLevelCoverage` lui-même, son oracle
| `tests/Feature/Draw/FrameLevelCoverageTest.php` (qui restate la table pour
| la vérifier) et ce fichier.
|
| Le détecteur est prouvé dans les deux sens : positif sur `FrameLevelCoverage`
| (ses quatre entrées vues), négatif sur des témoins littéraux écrits dans les
| formes que le dépôt produit lui-même (table multi-lignes à virgule finale,
| `match` en `self::` dans l'enum, objet TypeScript multi-lignes). Un motif
| affaibli rougit sur l'un ou l'autre.
|
*/

/**
 * Chemins relatifs (séparateur `/`) des fichiers d'extension donnée sous des
 * répertoires du dépôt, triés.
 *
 * @param  list<string>  $directories
 * @param  list<string>  $extensions
 * @return list<string>
 */
function drawBoundaryFiles(array $directories, array $extensions): array
{
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && in_array($file->getExtension(), $extensions, true)) {
                $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Code PHP d'un fichier sans commentaires ni blancs, cas de `FrameLevel`
 * ramenés à leur entier : `FrameLevel::Level3` devient `3`.
 */
function drawBoundaryCompactPhp(string $relativePath): string
{
    return drawBoundaryCompactPhpSource((string) file_get_contents(base_path($relativePath)));
}

/**
 * Même compactage que {@see drawBoundaryCompactPhp()}, sur une source donnée :
 * c'est par lui que passent les témoins négatifs.
 */
function drawBoundaryCompactPhpSource(string $source): string
{
    $code = implode('', array_map(
        static fn (PhpToken $token): string => $token->isIgnorable() ? '' : $token->text,
        PhpToken::tokenize($source),
    ));

    return drawBoundaryNormalizeLevels($code);
}

/**
 * Code TypeScript d'un fichier sans commentaires ni blancs.
 */
function drawBoundaryCompactTs(string $relativePath): string
{
    return drawBoundaryCompactTsSource((string) file_get_contents(base_path($relativePath)));
}

/**
 * Même compactage que {@see drawBoundaryCompactTs()}, sur une source donnée.
 */
function drawBoundaryCompactTsSource(string $source): string
{
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
    $source = (string) preg_replace('#(?<![:"\'])//[^\n]*#', '', $source);

    return (string) preg_replace('/\s+/', '', $source);
}

/**
 * Cas de `FrameLevel` ramenés à leur entier, qualifiés ou non, et sous
 * `self::` / `static::` : une copie logée dans l'enum lui-même
 * (`FrameLevel::nominalFor()`) est l'emplacement le plus tentant. Seul
 * `FrameLevel` porte des cas `LevelN` dans le dépôt : `bit()` devient
 * `1=>1<<0`, `variantsColumn()` `1=>'level_1_variants'`, ni l'un ni l'autre
 * n'est une entrée de table.
 */
function drawBoundaryNormalizeLevels(string $code): string
{
    return (string) preg_replace('/(?:(?:\\\\?App\\\\Enums\\\\)?FrameLevel|self|static)::Level([1-5])\b/', '$1', $code);
}

/**
 * Entrées de table nominale : `N` suivi de sa répartition, en entiers, après
 * `=>` (PHP) ou `:` (TypeScript), virgule finale admise ; `default` porte la
 * répartition de `N` = 3, la valeur par défaut du salon.
 */
function drawBoundaryEntryPattern(): string
{
    return '/(?<![\w.$])(?:2(?:=>|:)\[1,5,?\]|(?:3|default)(?:=>|:)\[1,3,5,?\]|4(?:=>|:)\[1,2,4,5,?\]|5(?:=>|:)\[1,2,3,4,5,?\])/';
}

/**
 * La liste propre à `N` = 4, en entiers normalisés, bornée de part et d'autre
 * pour ne pas matcher une sous-liste d'une liste plus longue — une virgule
 * finale sans niveau derrière reste la même liste.
 */
function drawBoundaryFourPattern(): string
{
    return '/(?<![\d,])1,2,4,5(?!,?\d)/';
}

/**
 * Témoins négatifs : des copies de la table dans les formes que le dépôt
 * produit lui-même, qui doivent toutes être signalées. Écrits en chaînes
 * littérales, donc invisibles au balayage de ce fichier par construction.
 *
 * @return array<string, array{language: 'php'|'ts', source: string, entries: int, four: bool}>
 */
function drawBoundaryNegativeWitnesses(): array
{
    return [
        'table PHP multi-lignes à virgule finale (forme Pint)' => [
            'language' => 'php',
            'source' => <<<'PHP'
                <?php
                return match ($framesPerRound) {
                    2 => [
                        FrameLevel::Level1,
                        FrameLevel::Level5,
                    ],
                    3 => [
                        FrameLevel::Level1,
                        FrameLevel::Level3,
                        FrameLevel::Level5,
                    ],
                    4 => [
                        FrameLevel::Level1,
                        FrameLevel::Level2,
                        FrameLevel::Level4,
                        FrameLevel::Level5,
                    ],
                    5 => [
                        FrameLevel::Level1,
                        FrameLevel::Level2,
                        FrameLevel::Level3,
                        FrameLevel::Level4,
                        FrameLevel::Level5,
                    ],
                };
                PHP,
            'entries' => 4,
            'four' => true,
        ],
        'match en self:: dans l\'enum' => [
            'language' => 'php',
            'source' => <<<'PHP'
                <?php
                enum FrameLevel: int
                {
                    public static function nominalFor(int $n): array
                    {
                        return match ($n) {
                            2 => [self::Level1, self::Level5],
                            3 => [static::Level1, static::Level3, static::Level5],
                            4 => [self::Level1, self::Level2, self::Level4, self::Level5],
                            5 => [self::Level1, self::Level2, self::Level3, self::Level4, self::Level5],
                        };
                    }
                }
                PHP,
            'entries' => 4,
            'four' => true,
        ],
        'repli default sur la table de N = 3, entiers entièrement qualifiés' => [
            'language' => 'php',
            'source' => <<<'PHP'
                <?php
                return match ($n) {
                    default => [
                        \App\Enums\FrameLevel::Level1,
                        \App\Enums\FrameLevel::Level3,
                        \App\Enums\FrameLevel::Level5,
                    ],
                };
                PHP,
            'entries' => 1,
            'four' => false,
        ],
        'objet TypeScript multi-lignes (forme oxfmt)' => [
            'language' => 'ts',
            'source' => <<<'TS'
                export const nominal = {
                    2: [1, 5],
                    3: [
                        1,
                        3,
                        5,
                    ],
                    4: [
                        1,
                        2,
                        4,
                        5,
                    ],
                    5: [1, 2, 3, 4, 5],
                };
                TS,
            'entries' => 4,
            'four' => true,
        ],
    ];
}

it("la table nominale n'est écrite que dans FrameLevelCoverage", function () {
    $home = 'app/ValueObjects/Catalog/FrameLevelCoverage.php';

    $excluded = [
        $home,
        'tests/Feature/Draw/FrameLevelCoverageTest.php',
        'tests/Feature/Architecture/DrawBoundaryTest.php',
    ];

    // Le détecteur voit la table là où elle doit être : ses quatre entrées.
    // Sans cette preuve, un balayage qui ne matche jamais serait vert pour de
    // mauvaises raisons.
    expect(preg_match_all(drawBoundaryEntryPattern(), drawBoundaryCompactPhp($home)))->toBe(4)
        ->and(preg_match(drawBoundaryFourPattern(), drawBoundaryCompactPhp($home)))->toBe(1);

    // Et il voit une copie là où elle ne doit pas être, dans les formes que
    // Pint et oxfmt produisent : sans ces témoins, un motif qui ne reconnaît
    // que la forme sur une ligne du fichier propriétaire resterait vert.
    foreach (drawBoundaryNegativeWitnesses() as $label => $witness) {
        $code = $witness['language'] === 'php'
            ? drawBoundaryCompactPhpSource($witness['source'])
            : drawBoundaryCompactTsSource($witness['source']);

        expect(preg_match_all(drawBoundaryEntryPattern(), $code))
            ->toBe($witness['entries'], "témoin « {$label} » : entrées de table")
            ->and(preg_match(drawBoundaryFourPattern(), $code) === 1)
            ->toBe($witness['four'], "témoin « {$label} » : liste de N = 4");
    }

    $violations = [];

    foreach (drawBoundaryFiles(['app', 'bootstrap', 'config', 'database', 'routes', 'tests'], ['php']) as $file) {
        if (in_array($file, $excluded, true)) {
            continue;
        }

        $code = drawBoundaryCompactPhp($file);

        if (preg_match(drawBoundaryEntryPattern(), $code) === 1) {
            $violations[] = "{$file} : entrée de table N → niveaux";
        }

        if ((str_starts_with($file, 'app/') || str_starts_with($file, 'database/'))
            && preg_match(drawBoundaryFourPattern(), $code) === 1) {
            $violations[] = "{$file} : répartition nominale de N = 4 recopiée";
        }

        // Le helper provisoire des fabriques ne revient sous aucun nom d'appel.
        foreach (PhpToken::tokenize((string) file_get_contents(base_path($file))) as $token) {
            if ($token->is(T_STRING) && $token->text === 'expectedFrameLevels') {
                $violations[] = "{$file}:{$token->line} : expectedFrameLevels, retiré au profit de FrameLevelCoverage::nominal()";
            }
        }
    }

    // Côté client, aucun test ne compose de banque : la liste de N = 4 y est
    // cherchée aussi.
    foreach (drawBoundaryFiles(['resources/js'], ['ts', 'tsx']) as $file) {
        $code = drawBoundaryCompactTs($file);

        if (preg_match(drawBoundaryEntryPattern(), $code) === 1) {
            $violations[] = "{$file} : entrée de table N → niveaux côté client";
        }

        if (preg_match(drawBoundaryFourPattern(), $code) === 1) {
            $violations[] = "{$file} : répartition nominale de N = 4 recopiée côté client";
        }
    }

    expect($violations)->toBe([])
        ->and(method_exists(MovieFactory::class, 'expectedFrameLevels'))->toBeFalse();
});
