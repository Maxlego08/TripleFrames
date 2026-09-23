<?php

use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\User;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Longueurs de colonne, prouvées sur MySQL (spec 100 § 3.3, spec 10 § 15)
|--------------------------------------------------------------------------
|
| SQLite n'applique aucune longueur de `VARCHAR` : il accepte en silence une
| chaîne de 300 caractères dans un `string(20)` (spec 10 § 1.4). MySQL en
| mode strict la refuse en 1406 — en production, au moment où un joueur
| rejoint un salon ou où une manche se clôt. Ce fichier est donc du groupe
| `mysql` : exclu de `composer test`, joué par le job CI `mysql-redis`, et il
| ÉCHOUE hors de MySQL au lieu de se sauter.
|
*/

pest()->group('mysql');

beforeEach(fn () => requireMysql());

/**
 * Tous les modèles Eloquent du dépôt, pivots compris : une colonne castée en
 * enum sur un pivot part en base exactement comme sur un modèle.
 *
 * @return list<class-string<Model>>
 */
function columnLengthModels(): array
{
    $models = [];
    $root = app_path('Models');

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR);
        $class = 'App\\Models\\'.str_replace([DIRECTORY_SEPARATOR, '/', '.php'], ['\\', '\\', ''], $relative);

        if (is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

/**
 * Déclaration d'une colonne telle que la base la rend (`type` = type complet,
 * `varchar(40)` sous MySQL), ou `null` si la colonne n'existe pas.
 *
 * @return array{name: string, type_name: string, type: string}|null
 */
function columnLengthDeclaration(string $table, string $column): ?array
{
    foreach (Schema::getColumns($table) as $definition) {
        if ($definition['name'] === $column) {
            return $definition;
        }
    }

    return null;
}

/**
 * Longueur déclarée d'une colonne `varchar(N)` ou `char(N)`, en caractères.
 */
function columnLengthCharacters(string $type): ?int
{
    return preg_match('/^(?:var)?char\((\d+)\)/i', $type, $matches) === 1 ? (int) $matches[1] : null;
}

/**
 * Pourquoi les valeurs d'un enum ne tiennent pas dans une colonne, ou `null`
 * si elles y tiennent toutes.
 *
 * @param  array{type_name: string, type: string}  $declaration
 * @param  list<int|string>  $values
 */
function columnLengthEnumMismatch(array $declaration, array $values): ?string
{
    $typeName = strtolower($declaration['type_name']);
    $type = strtolower($declaration['type']);

    if ($typeName === 'enum') {
        return "ENUM natif [{$type}], interdit sans exception (spec 10 § 1.4)";
    }

    $strings = array_values(array_filter($values, 'is_string'));
    $integers = array_values(array_filter($values, 'is_int'));

    if ($strings !== []) {
        $longest = max(array_map('mb_strlen', $strings));
        $characters = columnLengthCharacters($type);

        if ($characters !== null) {
            return $longest <= $characters
                ? null
                : "valeur de {$longest} caractères dans [{$type}] : 1406 garanti";
        }

        $textBytes = ['tinytext' => 255, 'text' => 65_535, 'mediumtext' => 16_777_215, 'longtext' => PHP_INT_MAX];

        if (array_key_exists($typeName, $textBytes)) {
            $bytes = max(array_map('strlen', $strings));

            return $bytes <= $textBytes[$typeName] ? null : "valeur de {$bytes} octets dans [{$type}]";
        }

        return "enum à valeurs textuelles dans une colonne [{$type}]";
    }

    /** @var array<string, array{0: int, 1: int, 2: int}> $ranges signé min, signé max, non signé max */
    $ranges = [
        'tinyint' => [-128, 127, 255],
        'smallint' => [-32_768, 32_767, 65_535],
        'mediumint' => [-8_388_608, 8_388_607, 16_777_215],
        'int' => [-2_147_483_648, 2_147_483_647, 4_294_967_295],
        'bigint' => [PHP_INT_MIN, PHP_INT_MAX, PHP_INT_MAX],
    ];

    if (! array_key_exists($typeName, $ranges)) {
        return "enum à valeurs entières dans une colonne [{$type}]";
    }

    [$signedMin, $signedMax, $unsignedMax] = $ranges[$typeName];
    $unsigned = str_contains($type, 'unsigned');
    $min = $unsigned ? 0 : $signedMin;
    $max = $unsigned ? $unsignedMax : $signedMax;

    return min($integers) >= $min && max($integers) <= $max
        ? null
        : "valeurs hors de [{$min}, {$max}] pour [{$type}]";
}

/**
 * Pseudo de `$length` lettres latines hors ASCII, toutes admises par la règle
 * de pseudo (D26 du 23/09, contrat C5 : U+00C0–U+00D6, U+00D8–U+00F6,
 * U+00F8–U+017F), réparties de bout en bout des trois plages. Chacune pèse
 * deux octets en UTF-8 : c'est le pseudo le plus lourd qu'une saisie valide
 * puisse produire à longueur maximale. Déterministe, aucun Faker.
 */
function columnLengthExtendedLatinNickname(int $length): string
{
    $codePoints = [...range(0x00C0, 0x00D6), ...range(0x00D8, 0x00F6), ...range(0x00F8, 0x017F)];
    $step = intdiv(count($codePoints), $length);
    $nickname = '';

    for ($index = 0; $index < $length; $index++) {
        $nickname .= mb_chr($codePoints[$index * $step], 'UTF-8');
    }

    return $nickname;
}

it("fait tenir la plus longue valeur d'enum dans chaque colonne castée en enum", function () {
    $checked = [];
    $mismatches = [];

    foreach (columnLengthModels() as $model) {
        $instance = new $model;
        $table = $instance->getTable();

        foreach ($instance->getCasts() as $column => $cast) {
            $enum = Str::before($cast, ':');

            if (! enum_exists($enum) || ! is_a($enum, BackedEnum::class, true)) {
                continue;
            }

            $where = "{$table}.{$column} ({$enum}, {$model})";
            $checked[] = $where;
            $declaration = columnLengthDeclaration($table, $column);

            if ($declaration === null) {
                $mismatches[] = "{$where} : cast sans colonne";

                continue;
            }

            $values = array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases());
            $mismatch = columnLengthEnumMismatch($declaration, $values);

            if ($mismatch !== null) {
                $mismatches[] = "{$where} : {$mismatch}";
            }
        }
    }

    // Un balayage qui ne trouve rien serait vert pour de mauvaises raisons.
    expect($checked)->not->toBeEmpty('Aucune colonne castée en enum trouvée : le balayage est cassé.');
    expect($mismatches)->toBe([]);
});

it('fait tenir un pseudo de longueur maximale en lettres latines étendues dans player.nickname et game_player.display_nickname', function () {
    // Borne produit du pseudo. `NicknameNormalizer::MAX_LENGTH` (contrat C5) la
    // remplacera ; la constante de fabrique disparaîtra avec lui, et ce test
    // cessera alors de compiler au lieu de tester une borne périmée.
    $length = PlayerFactory::NICKNAME_MAX_LENGTH;
    $nickname = columnLengthExtendedLatinNickname($length);

    expect(mb_strlen($nickname))->toBe($length)
        ->and(strlen($nickname))->toBe(2 * $length);

    $seat = Player::factory()->withNickname($nickname)->create();
    $participation = GamePlayer::factory()->frozenFrom($seat)->create();

    expect(Player::query()->findOrFail($seat->id)->nickname)->toBe($nickname)
        ->and(GamePlayer::query()->findOrFail($participation->id)->display_nickname)->toBe($nickname);
});

it("déclare la même longueur pour les trois colonnes de clé d'avatar prédéfini", function () {
    $columns = [
        User::class => 'avatar_preset',
        Player::class => 'avatar_preset',
        GamePlayer::class => 'display_avatar_preset',
    ];

    $lengths = [];

    foreach ($columns as $model => $column) {
        $table = (new $model)->getTable();
        $declaration = columnLengthDeclaration($table, $column);

        expect($declaration)->not->toBeNull("La colonne {$table}.{$column} n'existe pas.");

        $length = columnLengthCharacters((string) $declaration['type']);

        expect($length)->not->toBeNull(
            "{$table}.{$column} est déclarée [{$declaration['type']}] au lieu d'une chaîne de longueur bornée.",
        );

        $lengths["{$table}.{$column}"] = $length;
    }

    expect(array_unique($lengths))->toHaveCount(1, 'Longueurs divergentes : '.json_encode($lengths));
});
