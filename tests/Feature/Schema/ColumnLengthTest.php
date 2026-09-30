<?php

use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\User;
use App\Rules\ValidNickname;
use App\Support\Identity\NicknameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Longueurs de colonne, prouvées sur MySQL (spec 100 § 3.3, spec 10 § 15)
|--------------------------------------------------------------------------
|
| SQLite n'applique aucune longueur de `VARCHAR` : il accepte en silence une
| chaîne de 300 caractères dans un `string(20)` (spec 10 § 1.4). MySQL en
| mode strict la refuse en 1406 — en production, au moment où un joueur
| rejoint un salon ou où une manche se clôt. Il en va de même de la plage d'un
| entier : SQLite accepte 256 dans un `unsignedTinyInteger`, MySQL le refuse
| en 1264.
| Ce fichier est donc du groupe `mysql` : exclu de `composer test`, joué par
| le job CI `mysql-redis`, et il ÉCHOUE hors de MySQL au lieu de se sauter.
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
 * @return array{name: string, type_name: string, type: string, nullable: bool}|null
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
 * Pseudo de `$length` lettres latines hors ASCII que la règle de pseudo
 * accepte telles quelles : chacune est admise par
 * `ValidNickname::ALLOWED_PATTERN` (D26 du 23/09, contrat C5) — lu, jamais
 * recopié — et se replie en une seule lettre ASCII, pour que la forme repliée
 * tienne aussi dans `nickname_normalized` (`ß` ou `œ`, qui se replient en deux
 * lettres, feraient refuser le pseudo par `normalized_length`). Elles sont
 * réparties de bout en bout du plan à deux octets d'UTF-8, où chacune pèse
 * deux octets : c'est le pseudo le plus lourd qu'une saisie valide puisse
 * produire à longueur maximale. Déterministe, aucun Faker.
 */
function columnLengthExtendedLatinNickname(int $length): string
{
    $codePoints = array_values(array_filter(
        range(0x0080, 0x07FF),
        static function (int $codePoint): bool {
            $letter = mb_chr($codePoint, 'UTF-8');

            return preg_match(ValidNickname::ALLOWED_PATTERN, $letter) === 1
                && strlen(NicknameNormalizer::normalize($letter)) === 1;
        },
    ));
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
    // Borne de schéma du pseudo (contrat C5, spec 40 § 5.10), la même pour le
    // pseudo affiché et pour sa forme repliée.
    $length = NicknameNormalizer::MAX_LENGTH;
    $nickname = columnLengthExtendedLatinNickname($length);

    expect(mb_strlen($nickname))->toBe($length)
        ->and(strlen($nickname))->toBe(2 * $length)
        // Une saisie que la règle accepte, déjà sous sa forme canonique : ce
        // test ne fait tenir que ce qu'un joueur peut réellement écrire.
        ->and(NicknameNormalizer::canonical($nickname))->toBe($nickname)
        ->and(Validator::make(['nickname' => $nickname], ['nickname' => [new ValidNickname]])->passes())->toBeTrue();

    $seat = Player::factory()->withNickname($nickname)->create();
    $participation = GamePlayer::factory()->frozenFrom($seat)->create();
    $stored = Player::query()->findOrFail($seat->id);

    expect($stored->nickname)->toBe($nickname)
        ->and($stored->nickname_normalized)->toBe(NicknameNormalizer::normalize($nickname))
        ->and(strlen((string) $stored->nickname_normalized))->toBe($length)
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

it('déclare game_player.final_rank en smallint non signé nullable et y fait tenir un 256e rang', function () {
    // E10-04 : `game_player` n'est pas borné (retardataires en rotation, partis
    // et expulsés restent classés). Dans un `unsignedTinyInteger`, le gel d'une
    // partie à plus de 255 sièges classés lèverait 1264 sous MySQL strict.
    $declaration = columnLengthDeclaration('game_player', 'final_rank');

    expect($declaration)->not->toBeNull("La colonne game_player.final_rank n'existe pas.");

    $type = strtolower((string) $declaration['type']);

    expect(strtolower((string) $declaration['type_name']))->toBe('smallint', "game_player.final_rank est déclarée [{$type}].")
        ->and($type)->toContain('unsigned')
        ->and($declaration['nullable'])->toBeTrue('game_player.final_rank reste NULL en solo ou si rounds_played = 0.');

    $participation = GamePlayer::factory()->finished(finalRank: 256)->create();

    expect(GamePlayer::query()->findOrFail($participation->id)->final_rank)->toBe(256);
});
