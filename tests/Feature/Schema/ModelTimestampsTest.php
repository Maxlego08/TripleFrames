<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Balayage des 36 modèles du dépôt, exigé par le § 1.7 de la spec 10.
 *
 * Trois dérives silencieuses sont couvertes ici :
 *
 * 1. LA FAMILLE D'HORODATAGE. Cinq tables n'ont pas d'`updated_at` (`guess`,
 *    `report`, `admin_action`, `user_consent`, `movie_tmdb_tag`) et deux n'ont
 *    aucune colonne conventionnelle (`frame_review`, `seen_frame`). Sans
 *    `const UPDATED_AT = null` ou `#[WithoutTimestamps]`, Eloquent écrit une
 *    colonne inexistante : 1054 en MySQL, « no such column » en SQLite — et
 *    CHAQUE bonne réponse du jeu échoue, en pleine manche, en production.
 *    Le piège est symétrique : `movie_projection`, `round_choice_set`,
 *    `data_export` et `purge_run` PORTENT des `timestamps()` conventionnels
 *    malgré leurs colonnes datées métier, et les basculer serait le bug miroir.
 * 2. LA DÉRIVE PHPDoc. Le niveau 7 lit les `@property` mais ne les confronte
 *    jamais à la base : une colonne renommée dans une migration laisse un
 *    `@property` fantôme que rien ne signale. Convention du dépôt : `@property`
 *    désigne une COLONNE, `@property-read` une relation ou un attribut calculé.
 * 3. L'ÉCART ENTRE LE MODÈLE ET SA TABLE. Persister puis remettre à jour une
 *    ligne par modèle est la seule preuve que les casts, la famille
 *    d'horodatage et les colonnes `NOT NULL` s'accordent réellement.
 * 4. LES DÉFAUTS SQL NON MIROITÉS. Un `->default(...)` de migration ne remplit
 *    que la LIGNE : l'instance qui vient de l'écrire garde l'attribut absent,
 *    donc `null`. `@property RoundPlayerInputState $input_state` ment alors au
 *    runtime — `$roundPlayer->input_state->isClosed()` lève « on null » en
 *    pleine manche — et PHPStan, qui lit l'annotation, ne peut pas le voir. Le
 *    versant numérique est pire parce que muet : `$projection->levels_mask & 21`
 *    rend 0 et un film fraîchement projeté paraît ne couvrir aucun niveau. Seul
 *    un `protected $attributes` miroir exact ferme la dérive, et seul ce
 *    balayage empêche qu'elle revienne au prochain modèle ajouté.
 */

/**
 * Les 36 modèles : les 35 tables de domaine plus `App\Models\User`.
 *
 * @return list<class-string<Model>>
 */
function schemaModels(): array
{
    /** @var list<class-string<Model>> $models */
    $models = [];

    // Chemin calculé depuis ce fichier : la fonction est appelée au moment de la
    // COLLECTE, avant que l'application ne soit bootée — `app_path()` n'y est
    // pas fiable et rendrait le jeu de données silencieusement vide.
    foreach ((array) glob(dirname(__DIR__, 3).'/app/Models/*.php') as $file) {
        if (! is_string($file)) {
            continue;
        }

        $class = 'App\\Models\\'.Str::before(basename($file), '.php');

        if (is_subclass_of($class, Model::class)) {
            $models[] = $class;
        }
    }

    sort($models);

    return $models;
}

/**
 * Colonnes déclarées par les `@property` d'un modèle — jamais les
 * `@property-read`, qui portent les relations et les attributs ajoutés.
 *
 * @param  class-string<Model>  $model
 * @return list<string>
 */
function documentedColumns(string $model): array
{
    $doc = (new ReflectionClass($model))->getDocComment();

    if ($doc === false) {
        return [];
    }

    $matches = [];
    preg_match_all('/@property(?![-\w])\s+.+?\s+\$(\w+)/', $doc, $matches);

    /** @var list<string> $columns */
    $columns = array_values(array_unique($matches[1]));

    return $columns;
}

/**
 * Représentation textuelle d'un défaut SQL tel que le rend `Schema::getColumns()`.
 *
 * SQLite rend le littéral SQL (`'open'`, guillemets compris, `''` doublé pour
 * l'apostrophe) là où MySQL rend la valeur nue : la comparaison se fait donc sur
 * une forme repliée, jamais sur les octets bruts.
 */
function rawSqlDefault(string $default): string
{
    if (strlen($default) >= 2 && str_starts_with($default, "'") && str_ends_with($default, "'")) {
        return str_replace("''", "'", substr($default, 1, -1));
    }

    return $default;
}

/**
 * Représentation textuelle d'un attribut de modèle AVANT cast — c'est sous cette
 * forme qu'il part en base.
 */
function rawModelAttribute(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if ($value instanceof BackedEnum) {
        return (string) $value->value;
    }

    return is_scalar($value) ? (string) $value : gettype($value);
}

it('sweeps the thirty-six models of the schema', function () {
    expect(schemaModels())->toHaveCount(36);
});

it('never declares a timestamp column its table does not carry', function (string $model) {
    /** @var Model $instance */
    $instance = new $model;

    $table = $instance->getTable();

    expect(Schema::hasTable($table))->toBeTrue(
        "[{$model}] pointe la table [{$table}], qui n'existe pas.",
    );

    if (! $instance->usesTimestamps()) {
        // `frame_review` et `seen_frame`, nommément et exhaustivement.
        expect(Schema::hasColumn($table, 'created_at'))->toBeFalse(
            "[{$model}] renonce aux horodatages alors que [{$table}] porte `created_at`.",
        );
        expect(Schema::hasColumn($table, 'updated_at'))->toBeFalse(
            "[{$model}] renonce aux horodatages alors que [{$table}] porte `updated_at`.",
        );

        return;
    }

    $createdAt = $instance->getCreatedAtColumn();
    $updatedAt = $instance->getUpdatedAtColumn();

    expect($createdAt)->not->toBeNull(
        "[{$model}] écrit des horodatages sans colonne `created_at`.",
    );
    expect(Schema::hasColumn($table, (string) $createdAt))->toBeTrue(
        "[{$model}] écrirait [{$createdAt}], absente de [{$table}] : 1054 garanti.",
    );

    // `const UPDATED_AT = null` est la seule échappatoire, et seulement quand la
    // table n'a effectivement pas la colonne.
    if ($updatedAt === null) {
        expect(Schema::hasColumn($table, 'updated_at'))->toBeFalse(
            "[{$model}] renonce à `updated_at` alors que [{$table}] la porte.",
        );

        return;
    }

    expect(Schema::hasColumn($table, $updatedAt))->toBeTrue(
        "[{$model}] écrirait [{$updatedAt}], absente de [{$table}] : 1054 garanti.",
    );
})->with(schemaModels());

it('documents only columns that really exist', function (string $model) {
    /** @var Model $instance */
    $instance = new $model;

    $table = $instance->getTable();
    $documented = documentedColumns($model);

    expect($documented)->not->toBeEmpty(
        "[{$model}] ne documente aucune colonne : le PHPDoc `@property` est obligatoire au niveau 7.",
    );

    foreach ($documented as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue(
            "[{$model}] documente `@property \${$column}`, absente de la table [{$table}].",
        );
    }
})->with(schemaModels());

it('persists and updates one row per model through its factory', function (string $model) {
    $created = $model::factory()->create();

    expect($created->exists)->toBeTrue();
    expect($model::query()->whereKey($created->getKey())->exists())->toBeTrue(
        "[{$model}] n'a pas persisté la ligne fabriquée.",
    );

    // Une seconde plus tard, `touch()` rend l'attribut réellement sale et émet
    // un vrai UPDATE : c'est l'instant exact où une colonne d'horodatage
    // fantôme tombe. Les modèles en ajout seul n'y écrivent rien, par
    // construction, et traversent donc la même assertion sans requête.
    $this->travel(1)->seconds();

    $created->touch();

    expect($created->fresh())->not->toBeNull(
        "[{$model}] ne se relit plus après `touch()`.",
    );
})->with(schemaModels());

it('mirrors in the model every NOT NULL default its migration declares', function (string $model) {
    /** @var Model $instance */
    $instance = new $model;

    $table = $instance->getTable();
    $attributes = $instance->getAttributes();

    // Une assertion inconditionnelle : les modèles dont aucune colonne ne porte de
    // défaut traversent le balayage sans qu'il soit déclaré « risky », donc sans
    // qu'un futur `failOnRisky` transforme un silence légitime en échec.
    expect($attributes)->toBeArray();

    foreach (Schema::getColumns($table) as $column) {
        /** @var string $name */
        $name = $column['name'];
        $default = $column['default'];

        if ($default === null || $column['nullable'] === true || ($column['auto_increment'] ?? false) === true) {
            continue;
        }

        expect(array_key_exists($name, $attributes))->toBeTrue(
            "[{$model}] ne miroite pas le défaut SQL de [{$table}.{$name}] : l'instance qui vient d'écrire la ligne y lira `null` sur une colonne NOT NULL.",
        );

        expect(rawModelAttribute($attributes[$name]))->toBe(
            rawSqlDefault((string) $default),
            "[{$model}] miroite [{$table}.{$name}] avec une valeur qui n'est pas le défaut SQL.",
        );
    }
})->with(schemaModels());
