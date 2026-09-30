<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\ExpectationFailedException;

/*
|--------------------------------------------------------------------------
| Groupes Pest — la liste close du contrat C18, rendue mécanique
|--------------------------------------------------------------------------
|
| Spec 100 § 2.1. Trois pannes silencieuses sont fermées ici :
|
| 1. UN GROUPE INVENTÉ. `composer test` exclut `mysql` et `locks-timing`, le
|    job `mysql-redis` joue tout : un troisième nom, ou une faute de frappe
|    (`my-sql`), ferait tourner un test MySQL sur SQLite — ou nulle part.
| 2. UN TEST MYSQL QUI SE SAUTE. Un test du groupe `mysql` qui n'appelle pas
|    `requireMysql()` passe au vert sur SQLite sans rien prouver, et un groupe
|    posé sur un test isolé se perd à la première copie (R-03).
| 3. UN TEST DE CONCURRENCE HORS DE SON RÉPERTOIRE. `locks-timing` s'applique
|    par répertoire, avec `DatabaseTruncation` : ailleurs, le test tournerait
|    dans une transaction englobante qui masque la concurrence qu'il prouve.
|
| Les déclarations sont lues au tokeniseur PHP, jamais par expression
| régulière sur le texte brut : un commentaire ou une chaîne qui CITE
| `->group('mysql')` n'est pas une déclaration.
|
*/

/**
 * Groupes admis, liste close du contrat C18 § 2.1.
 *
 * @return list<string>
 */
function knownTestGroups(): array
{
    return ['mysql', 'locks-timing'];
}

/**
 * Chemins relatifs (`tests/...`, séparateur `/`) de tous les fichiers PHP de
 * `tests/`, triés.
 *
 * @return list<string>
 */
function testGroupsPhpFiles(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', Str::after($file->getPathname(), base_path().DIRECTORY_SEPARATOR));
        }
    }

    sort($files);

    return $files;
}

/**
 * Jetons signifiants d'un fichier : ni espaces, ni commentaires, ni balise.
 *
 * @return list<PhpToken>
 */
function testGroupsTokens(string $relativePath): array
{
    $tokens = PhpToken::tokenize((string) file_get_contents(base_path($relativePath)));

    return array_values(array_filter($tokens, static fn (PhpToken $token): bool => ! $token->isIgnorable()));
}

/**
 * Code d'un fichier réduit à ses jetons signifiants, sans aucun blanc : les
 * comparaisons ne dépendent ni de la mise en forme ni des commentaires.
 */
function testGroupsCompactCode(string $relativePath): string
{
    return implode('', array_map(static fn (PhpToken $token): string => $token->text, testGroupsTokens($relativePath)));
}

/**
 * Valeur d'un littéral de chaîne PHP, ou `null` pour tout autre argument —
 * un groupe calculé ne peut pas être vérifié, donc il est refusé.
 *
 * @param  list<PhpToken>  $argument
 */
function testGroupsLiteral(array $argument): ?string
{
    if (count($argument) !== 1 || ! $argument[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
        return null;
    }

    $text = $argument[0]->text;
    $body = substr($text, 1, -1);

    return $text[0] === "'"
        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
        : stripcslashes($body);
}

/**
 * Déclarations de groupe d'un fichier : `->group(...)`, `::group(...)` et
 * l'attribut `#[Group(...)]` de PHPUnit.
 *
 * `fileLevel` vaut vrai pour `pest()->group(...)` (ou `uses()->group(...)`),
 * seule forme qui porte le groupe sur le fichier entier.
 *
 * @return list<array{line: int, groups: list<string|null>, fileLevel: bool}>
 */
function testGroupsDeclarations(string $relativePath): array
{
    $tokens = testGroupsTokens($relativePath);
    $declarations = [];
    $count = count($tokens);

    for ($index = 1; $index < $count - 1; $index++) {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1];

        if ($tokens[$index + 1]->text !== '(') {
            continue;
        }

        $isMethod = $token->is(T_STRING)
            && strtolower($token->text) === 'group'
            && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]);

        $isAttribute = $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && Str::afterLast($token->text, '\\') === 'Group'
            && ($previous->is(T_ATTRIBUTE) || $previous->text === ',');

        if (! $isMethod && ! $isAttribute) {
            continue;
        }

        $groups = [];
        $argument = [];
        $depth = 0;

        for ($cursor = $index + 2; $cursor < $count; $cursor++) {
            $text = $tokens[$cursor]->text;

            if ($depth === 0 && ($text === ',' || $text === ')')) {
                if ($argument !== []) {
                    $groups[] = testGroupsLiteral($argument);
                }

                $argument = [];

                if ($text === ')') {
                    break;
                }

                continue;
            }

            $depth += match ($text) {
                '(', '[', '{' => 1,
                ')', ']', '}' => -1,
                default => 0,
            };

            $argument[] = $tokens[$cursor];
        }

        $fileLevel = $isMethod
            && $index >= 4
            && $previous->is(T_OBJECT_OPERATOR)
            && $tokens[$index - 2]->text === ')'
            && $tokens[$index - 3]->text === '('
            && $tokens[$index - 4]->is(T_STRING)
            && in_array(strtolower($tokens[$index - 4]->text), ['pest', 'uses'], true)
            && ($index < 5 || ! $tokens[$index - 5]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]));

        $declarations[] = ['line' => $token->line, 'groups' => $groups, 'fileLevel' => $fileLevel];
    }

    return $declarations;
}

/**
 * Groupes sélectionnés (`--group`) ou exclus (`--exclude-group`) par un
 * script Composer, une valeur par option.
 *
 * La valeur n'est jamais découpée à la virgule : PHPUnit 13 ne la découpe pas
 * non plus, et `--exclude-group=mysql,locks-timing` n'exclurait RIEN (il vise
 * un groupe nommé « mysql,locks-timing »). Une telle valeur ressort donc ici
 * comme un groupe inconnu.
 *
 * @return list<string>
 */
function testGroupsScriptOptions(string $script, string $option): array
{
    /** @var array{scripts: array<string, list<string>|string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $groups = [];

    foreach ((array) ($composer['scripts'][$script] ?? []) as $command) {
        preg_match_all('/(?<![\w-])--'.preg_quote($option, '/').'[= ]([^\s"\']+)/', (string) $command, $matches);

        array_push($groups, ...$matches[1]);
    }

    sort($groups);

    return $groups;
}

it("n'emploie que les groupes Pest connus", function () {
    $known = knownTestGroups();
    $seen = [];
    $violations = [];

    foreach (testGroupsPhpFiles() as $file) {
        foreach (testGroupsDeclarations($file) as $declaration) {
            foreach ($declaration['groups'] as $group) {
                if ($group === null) {
                    $violations[] = "{$file}:{$declaration['line']} : groupe non littéral, invérifiable";
                } elseif (! in_array($group, $known, true)) {
                    $violations[] = "{$file}:{$declaration['line']} : groupe inconnu [{$group}]";
                } else {
                    $seen[$group] = true;
                }
            }
        }
    }

    expect($violations)->toBe([]);

    // Le balayage voit bien les déclarations existantes : sinon il serait vert
    // pour de mauvaises raisons.
    expect(array_keys($seen))->toEqualCanonicalizing($known);

    // La suite par défaut exclut exactement ces groupes, `test:mysql` joue
    // exactement ceux-là : un groupe ajouté sans eux tournerait sur SQLite.
    $sorted = $known;
    sort($sorted);

    expect(testGroupsScriptOptions('test', 'exclude-group'))->toBe($sorted)
        ->and(testGroupsScriptOptions('test', 'group'))->toBe([])
        ->and(testGroupsScriptOptions('test:mysql', 'group'))->toBe($sorted)
        ->and(testGroupsScriptOptions('test:mysql', 'exclude-group'))->toBe([]);
});

it('fait échouer hors MySQL tout test du groupe mysql', function () {
    $mysqlFiles = [];
    $violations = [];

    foreach (testGroupsPhpFiles() as $file) {
        $declarations = array_values(array_filter(
            testGroupsDeclarations($file),
            static fn (array $declaration): bool => in_array('mysql', $declaration['groups'], true),
        ));

        if ($declarations === []) {
            continue;
        }

        if ($file === 'tests/Pest.php') {
            $violations[] = "{$file} : le groupe mysql se déclare fichier par fichier, jamais par répertoire";

            continue;
        }

        $mysqlFiles[] = $file;

        foreach ($declarations as $declaration) {
            if (! $declaration['fileLevel']) {
                $violations[] = "{$file}:{$declaration['line']} : groupe mysql posé sur un test isolé (R-03), "
                    ."déclarer pest()->group('mysql'); en tête de fichier";
            }
        }

        // `beforeEach(fn () => requireMysql());` ou sa forme en fermeture.
        $hook = '/(?<![>:\w])beforeEach\((?:static)?(?:fn\(\)(?::void)?=>requireMysql\(\)'
            .'|function\(\)(?::void)?\{requireMysql\(\);\})\);/';

        if (preg_match($hook, testGroupsCompactCode($file)) !== 1) {
            $violations[] = "{$file} : n'appelle pas beforeEach(fn () => requireMysql()); il se sauterait hors MySQL";
        }
    }

    expect($violations)->toBe([]);
    expect($mysqlFiles)->toContain('tests/Feature/Schema/ColumnLengthTest.php');

    // Le répertoire des tests de concurrence porte le même crochet.
    expect(testGroupsCompactCode('tests/Pest.php'))
        ->toContain("pest()->beforeEach(fn()=>requireMysql())->in('Concurrency');");

    // Et le crochet échoue — il ne saute pas — dès que le pilote n'est pas
    // MySQL, quel que soit le moteur sur lequel tourne cette suite.
    $default = DB::getDefaultConnection();

    config([
        'database.connections.test_groups_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'database.connections.test_groups_mysql' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'test_groups', 'username' => 'test_groups',
            'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ],
    ]);

    try {
        DB::setDefaultConnection('test_groups_sqlite');
        expect(fn () => requireMysql())->toThrow(ExpectationFailedException::class);

        // Aucune connexion n'est ouverte : le pilote se lit dans la configuration.
        DB::setDefaultConnection('test_groups_mysql');
        requireMysql();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('test_groups_sqlite');
        DB::purge('test_groups_mysql');
    }
});

it('ne place un test locks-timing que sous tests/Concurrency', function () {
    $violations = [];

    foreach (testGroupsPhpFiles() as $file) {
        $locksTiming = array_filter(
            testGroupsDeclarations($file),
            static fn (array $declaration): bool => in_array('locks-timing', $declaration['groups'], true),
        );

        if ($file === 'tests/Pest.php') {
            continue;
        }

        if ($locksTiming !== [] && ! str_starts_with($file, 'tests/Concurrency/')) {
            $violations[] = "{$file} : groupe locks-timing hors de tests/Concurrency";
        }

        if (str_starts_with($file, 'tests/Concurrency/')
            && preg_match('#^tests/Concurrency/[A-Z][A-Za-z]*/[A-Z][A-Za-z0-9]*Test\.php$#', $file) !== 1) {
            $violations[] = "{$file} : hors de la forme tests/Concurrency/<Domaine>/<Sujet>Test.php";
        }
    }

    expect($violations)->toBe([]);

    // Le groupe n'est posé qu'une fois, par répertoire, avec la troncature :
    // deux connexions doivent voir les écritures l'une de l'autre.
    $pestDeclarations = array_filter(
        testGroupsDeclarations('tests/Pest.php'),
        static fn (array $declaration): bool => in_array('locks-timing', $declaration['groups'], true),
    );

    expect($pestDeclarations)->toHaveCount(1)
        ->and(testGroupsCompactCode('tests/Pest.php'))->toContain(
            "pest()->extend(TestCase::class)->use(DatabaseTruncation::class)->group('locks-timing')->in('Concurrency');",
        );

    // La suite PHPUnit qui porte le répertoire existe.
    $phpunit = simplexml_load_file(base_path('phpunit.xml'));

    expect($phpunit)->not->toBeFalse();

    $suites = [];

    foreach ($phpunit->testsuites->testsuite ?? [] as $suite) {
        $suites[(string) $suite['name']] = array_map('strval', iterator_to_array($suite->directory, false));
    }

    expect($suites)->toHaveKey('Concurrency')
        ->and($suites['Concurrency'])->toBe(['tests/Concurrency']);
});

/*
| Report de L100-1 vers L100-3 (écart E6-2) rendu mécanique : `ci:check` ne
| peut pas jouer `npm run test` avant que le script existe (« Missing script:
| test »), mais il doit le jouer dès qu'il existe, entre `npm run types:check`
| et `@test` (spec 100 § 2.4). Sans ce test, Vitest livré sans toucher
| `composer.json` ne tournerait ni en local ni en CI.
*/

it('joue Vitest dans ci:check dès que package.json déclare le script test', function () {
    /** @var array{scripts: array<string, list<string>|string>} $composer */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    /** @var array{scripts?: array<string, string>} $package */
    $package = json_decode((string) file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

    $ciCheck = array_values((array) $composer['scripts']['ci:check']);

    if (! array_key_exists('test', $package['scripts'] ?? [])) {
        expect($ciCheck)->not->toContain('npm run test');

        return;
    }

    expect($ciCheck)->toContain('npm run types:check', 'npm run test', '@test');

    $position = array_flip($ciCheck);

    expect($position['npm run test'])->toBeGreaterThan($position['npm run types:check'])
        ->and($position['npm run test'])->toBeLessThan($position['@test']);
});
