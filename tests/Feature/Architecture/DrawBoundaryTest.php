<?php

use App\Support\Draw\PoolRemedy;
use App\Support\Draw\PoolReport;
use App\Support\Draw\SeededPrf;
use Database\Factories\MovieFactory;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Frontières du tirage — spec 30, lots L30-1 et L30-4
|--------------------------------------------------------------------------
|
| Trois frontières, que rien ne rendrait visibles en relecture :
|
| - la table nominale `N` → niveaux n'a qu'un domicile (L30-1, ci-dessous) ;
| - aucun chemin dépendant de la graine ne tire d'aléa hors de `SeededPrf`
|   (L30-4, § 5.5) ;
| - seuls `PoolReport` et `PoolRemedy` sont sérialisables dans
|   `App\Support\Draw` (L30-4, § 5.5, règle 3).
|
| TABLE NOMINALE (L30-1)
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

/*
|--------------------------------------------------------------------------
| Aléa dérivé de la graine (L30-4, spec 30 § 5.5)
|--------------------------------------------------------------------------
|
| Un `random_int()` non seedé casserait la rejouabilité que la matérialisation
| existe pour garantir ; un `shuffle` seedé retomberait dans l'état de 32 bits
| que la PRF existe pour éviter. D'où deux preuves complémentaires, limitées
| NOMMÉMENT aux chemins qui dépendent de la graine — `App\Support\Draw` et
| `App\Support\Answers` (leurres et ordre du QCM de 70) —, jamais à
| `App\Actions\Game`, où `MintTierServeToken` frappe légitimement son jeton
| par `random_bytes` (§ 5.1) :
|
| - l'analyse d'architecture de Pest (`not->toUse`) sur les fonctions
|   nommées ;
| - un balayage au tokeniseur de TOUTE la liste du § 5.5, que l'analyse
|   d'architecture ne sait pas exprimer sans bannir `Arr` ou `Collection`
|   entiers : appels (qualifiés ou non, casse indifférente — PHP l'ignore),
|   callables en chaîne, `->shuffle(` / `->random(` (collections),
|   `::shuffle(` / `::random(` (`Arr`, sous tout alias).
|
| Le balayage déborde la lettre du § 5.5 pour en tenir l'esprit (E70-3) :
| l'API d'aléa native de PHP 8.2 (`Random\Randomizer`, `Random\Engine\*` —
| `Mt19937` ne prend qu'une graine de 32 bits, soit exactement l'état que la
| PRF existe pour éviter), `lcg_value` et `openssl_random_pseudo_bytes` (une
| seconde source CSPRNG concurrente de `generateSeed()`).
|
| Dans ces deux espaces, `random_bytes` n'est admis que dans
| `SeededPrf::generateSeed()`, seule source de la graine.
|
*/

/**
 * Espaces de noms des chemins qui dépendent de la graine, et leur répertoire.
 *
 * @return array<string, string>
 */
function drawBoundarySeedPaths(): array
{
    return [
        'App\Support\Draw' => 'app/Support/Draw',
        'App\Support\Answers' => 'app/Support/Answers',
    ];
}

/**
 * Fonctions d'aléa interdites dans un chemin dépendant de la graine : la liste
 * du § 5.5, plus `lcg_value` et `openssl_random_pseudo_bytes` (E70-3).
 *
 * @return list<string>
 */
function drawBoundaryForbiddenFunctions(): array
{
    return ['mt_srand', 'srand', 'rand', 'mt_rand', 'random_int', 'array_rand', 'shuffle', 'str_shuffle', 'crc32', 'lcg_value', 'openssl_random_pseudo_bytes'];
}

/**
 * Tirages d'aléa d'une source PHP, commentaires exclus : fonctions interdites
 * et `random_bytes` (appel, appel pleinement qualifié, callable de première
 * classe, callable en chaîne), toute mention de l'API `Random\` de PHP 8.2
 * (`Randomizer`, `Random\Engine\*` : import, instanciation, nom qualifié),
 * méthodes `->shuffle(` / `->random(` / `->shuffleArray(` /
 * `->shuffleBytes(` / `->pickArrayKeys(` et statiques `::shuffle(` /
 * `::random(`. L'appelant décide où `random_bytes` est admis.
 *
 * @return list<array{line: int, form: string}>
 */
function drawBoundaryRandomnessCalls(string $source): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        static fn (PhpToken $token): bool => ! $token->isIgnorable(),
    ));
    $functions = [...drawBoundaryForbiddenFunctions(), 'random_bytes'];
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $literal = strtolower(substr($token->text, 1, -1));

            if (in_array($literal, $functions, true)) {
                $calls[] = ['line' => $token->line, 'form' => "'{$literal}'"];
            }

            continue;
        }

        // L'API d'aléa native (`Random\Randomizer`, `Random\Engine\Mt19937`…),
        // sous toute forme : import, instanciation, nom qualifié ou non.
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $bare = strtolower(ltrim($token->text, '\\'));

            if ($bare === 'randomizer' || str_starts_with($bare, 'random\\')) {
                $calls[] = ['line' => $token->line, 'form' => $token->text];

                continue;
            }
        }

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || ($tokens[$index + 1] ?? null)?->text !== '(') {
            continue;
        }

        $name = strtolower(ltrim($token->text, '\\'));
        $previous = $tokens[$index - 1] ?? null;

        if ($previous !== null && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            if (in_array($name, ['shuffle', 'random', 'shufflearray', 'shufflebytes', 'pickarraykeys'], true)) {
                $calls[] = ['line' => $token->line, 'form' => "{$previous->text}{$name}("];
            }

            continue;
        }

        if ($previous !== null && $previous->is(T_DOUBLE_COLON)) {
            if (in_array($name, ['shuffle', 'random'], true)) {
                $calls[] = ['line' => $token->line, 'form' => ($tokens[$index - 2]->text ?? '')."::{$name}("];
            }

            continue;
        }

        // Une déclaration ou une instanciation n'est pas un appel de fonction.
        if ($previous !== null && $previous->is([T_FUNCTION, T_NEW, T_CONST])) {
            continue;
        }

        if (in_array($name, $functions, true)) {
            $calls[] = ['line' => $token->line, 'form' => "{$name}("];
        }
    }

    return $calls;
}

it("aucun chemin dépendant de la graine n'emploie shuffle, mt_rand, srand, random_int, array_rand ni crc32", function () {
    // 1. Analyse d'architecture, sur les fonctions nommées. Une expectation
    // PAR espace de noms : un tableau d'espaces en cible
    // (`expect([...])->not->toUse(...)`) reste vert avec un `mt_rand` dans
    // `App\Support\Draw` (constaté à la porte de l'étape 70, E70-3).
    foreach (array_keys(drawBoundarySeedPaths()) as $namespace) {
        expect($namespace)->not->toUse(drawBoundaryForbiddenFunctions());
    }

    expect('App\Support\Answers')->not->toUse('random_bytes');
    expect('App\Support\Draw')->not->toUse('random_bytes')->ignoring(SeededPrf::class);

    // Témoin : l'analyse voit bien les appels de fonctions globales — sans
    // lui, une analyse qui ne les verrait jamais serait verte pour de
    // mauvaises raisons.
    expect(SeededPrf::class)->toUse(['random_bytes', 'hash_hmac']);

    // 2. Le balayage est prouvé dans les deux sens. Témoin négatif : chaque
    // forme de la liste du § 5.5, puis chaque forme ajoutée par E70-3 (API
    // `Random\`, `lcg_value`, `openssl_random_pseudo_bytes`), est signalée,
    // dans l'ordre.
    $forbidden = drawBoundaryRandomnessCalls(<<<'PHP'
        <?php
        namespace App\Support\Draw;

        use Illuminate\Support\Arr;
        use Random\Engine\Mt19937;

        function witness(array $items, $collection): void
        {
            shuffle($items);
            \mt_rand(1, 6);
            MT_SRAND(42);
            srand(1);
            rand();
            random_int(0, 9);
            array_rand($items);
            str_shuffle('abc');
            crc32('seed');
            Arr::random($items);
            \Illuminate\Support\Arr::shuffle($items);
            $collection->shuffle();
            $collection?->random();
            array_map('mt_rand', $items);
            $draw = shuffle(...);
            random_bytes(16);
            $r = new \Random\Randomizer(new \Random\Engine\Mt19937(42));
            $r->shuffleArray($items);
            (new Randomizer())->getInt(0, 9);
            lcg_value();
            openssl_random_pseudo_bytes(32);
        }
        PHP);

    expect(array_column($forbidden, 'form'))->toBe([
        'Random\Engine\Mt19937',
        'shuffle(',
        'mt_rand(',
        'mt_srand(',
        'srand(',
        'rand(',
        'random_int(',
        'array_rand(',
        'str_shuffle(',
        'crc32(',
        'Arr::random(',
        '\Illuminate\Support\Arr::shuffle(',
        '->shuffle(',
        '?->random(',
        "'mt_rand'",
        'shuffle(',
        'random_bytes(',
        '\Random\Randomizer',
        '\Random\Engine\Mt19937',
        '->shufflearray(',
        'Randomizer',
        'lcg_value(',
        'openssl_random_pseudo_bytes(',
    ]);

    // Témoin positif : ni un commentaire, ni une déclaration, ni une
    // instanciation, ni un texte, ni la PRF elle-même.
    $clean = drawBoundaryRandomnessCalls(<<<'PHP'
        <?php
        namespace App\Support\Draw;

        final class Clean
        {
            public function shuffle(): void {}

            public function run(SeededPrf $prf, array $items): array
            {
                // shuffle($items); mt_rand(); Arr::random($items);
                $label = 'shuffle the deck';
                $order = $prf->permutation(DrawContext::movies(), count($items));
                $pick = $prf->index(DrawContext::variant(1, 1), 3);
                $operand = new Rand();

                return [hash_hmac('sha256', 'x', 'k'), $order, $pick, $label, $operand];
            }
        }
        PHP);

    expect($clean)->toBe([]);

    // 3. Le balayage des deux espaces.
    $generateSeed = new ReflectionMethod(SeededPrf::class, 'generateSeed');
    $seedFile = 'app/Support/Draw/SeededPrf.php';
    $violations = [];
    $admitted = 0;

    foreach (drawBoundaryFiles(array_values(drawBoundarySeedPaths()), ['php']) as $file) {
        foreach (drawBoundaryRandomnessCalls((string) file_get_contents(base_path($file))) as ['line' => $line, 'form' => $form]) {
            $inGenerateSeed = $file === $seedFile
                && $line >= $generateSeed->getStartLine()
                && $line <= $generateSeed->getEndLine();

            if ($form === 'random_bytes(' && $inGenerateSeed) {
                $admitted++;

                continue;
            }

            $violations[] = "{$file}:{$line} : {$form}";
        }
    }

    // La graine a une source, et une seule.
    expect($violations)->toBe([])
        ->and($admitted)->toBe(1);
});

it('seuls PoolReport et PoolRemedy sont sérialisables dans App\Support\Draw', function () {
    $classes = array_map(
        static fn (string $file): string => 'App\\'.str_replace('/', '\\', Str::between($file, 'app/', '.php')),
        drawBoundaryFiles(['app/Support/Draw'], ['php']),
    );

    $serializable = array_values(array_filter(
        $classes,
        static fn (string $class): bool => array_intersect(
            [Arrayable::class, Jsonable::class, JsonSerializable::class],
            class_implements($class) ?: [],
        ) !== [],
    ));

    // Témoin : le balayage voit les classes du lot, et les deux sérialisables
    // le sont bien.
    expect($classes)->toContain(SeededPrf::class, PoolReport::class, PoolRemedy::class)
        ->and($serializable)->toBe([PoolRemedy::class, PoolReport::class]);

    // Et l'analyse d'architecture le redit, pour les classes à venir du
    // tirage (`DrawResult`, `DrawnRound`, `DrawnTier`, `DrawInput`,
    // `VariantCandidate`…).
    expect('App\Support\Draw')
        ->not->toImplement([Arrayable::class, Jsonable::class, JsonSerializable::class])
        ->ignoring([PoolReport::class, PoolRemedy::class]);
});
