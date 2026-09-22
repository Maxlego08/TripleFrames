<?php

namespace App\Casts;

use App\Settings\RoomSettings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Cast des quatre colonnes `json` qui portent {@see RoomSettings}.
 *
 * `room.settings`, `setting_preset.settings`, `saved_config.settings` et
 * `game.settings_snapshot`, chacune doublée d'une colonne `settings_version`
 * en clair.
 *
 * Un cast `'array'` nu est IMPOSSIBLE ici : il renvoie `mixed`, interdit au
 * niveau 7 (§ 1.7). D'où l'implémentation typée, `get()` gérant explicitement
 * le cas nul et documenté `@return TGet|null`.
 *
 * **`get()` ne normalise RIEN.** `Model::save()` appelle
 * `mergeAttributesFromCachedCasts()`, qui rappelle `set()` sur l'objet mis en cache
 * par `get()` : si `get()` normalisait, un simple renommage de `saved_config`
 * réécrirait son JSON écrêté sans confirmation, et `settings_version` n'étant pas
 * remontée, le chargement suivant rejouerait la chaîne sur des données déjà
 * normalisées. La normalisation est un appel EXPLICITE de l'action de chargement
 * ({@see RoomSettings::normalize()}), dont le résultat n'est jamais réinjecté ici.
 *
 * **`set()` retourne les DEUX colonnes ensemble**, de sorte que l'état « JSON
 * normalisé + version périmée » soit impossible.
 *
 * @implements CastsAttributes<RoomSettings, RoomSettings>
 */
final class RoomSettingsCast implements CastsAttributes
{
    /**
     * La colonne de version est la même sur les quatre tables porteuses — `game`
     * comprise, dont la charge utile s'appelle pourtant `settings_snapshot`.
     */
    public const string VERSION_COLUMN = 'settings_version';

    public function __construct(private readonly string $versionColumn = self::VERSION_COLUMN) {}

    /**
     * Désérialise FIDÈLEMENT, à la version d'origine, et ne modifie rien.
     *
     * @param  array<string, mixed>  $attributes
     * @return RoomSettings|null
     *
     * @throws UnexpectedValueException Charge utile illisible : l'échec est bruyant.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof RoomSettings) {
            return $value;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException(
                "La colonne [{$key}] de [".$model::class.'] ne porte pas une charge utile JSON.',
            );
        }

        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            throw new UnexpectedValueException(
                "La colonne [{$key}] de [".$model::class.'] ne contient pas un objet JSON valide.',
            );
        }

        $payload = [];

        foreach ($decoded as $field => $item) {
            if (is_string($field)) {
                $payload[$field] = $item;
            }
        }

        return RoomSettings::fromStorage($payload, $this->version($attributes));
    }

    /**
     * Écrit la charge utile ET sa version, ensemble et jamais séparément.
     *
     * La version écrite est celle DONT L'INSTANCE SORT
     * ({@see RoomSettings::$sourceVersion}), jamais la constante courante. Sans cela,
     * `mergeAttributesFromClassCasts()` — rappelé par `Model::save()` sur l'objet mis
     * en cache par {@see self::get()} — ferait passer en v2 une ligne v1 qu'un simple
     * renommage vient de toucher : la charge utile serait réécrite sous la disposition
     * courante, avec les défauts courants pour les champs absents, et le pas v1 → v2 de
     * `RoomSettings::upgrade()` ne s'appliquerait PLUS JAMAIS à cette ligne. Seul un
     * passage explicite par `fromInput()` ou `normalize()` fait avancer la version.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|int>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes)
    {
        if (! $value instanceof RoomSettings) {
            throw new InvalidArgumentException(
                "La colonne [{$key}] de [".$model::class.'] n\'accepte qu\'une instance de '.RoomSettings::class.'.',
            );
        }

        return [
            $key => $value->toJson(),
            $this->versionColumn => $value->sourceVersion,
        ];
    }

    /**
     * Version sous laquelle la charge utile a été écrite — lue EN COLONNE, jamais
     * à l'intérieur du JSON (§ 1.6).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function version(array $attributes): int
    {
        $version = $attributes[$this->versionColumn] ?? null;

        if (is_int($version)) {
            return $version;
        }

        if (is_string($version) && preg_match('/^\d+$/', $version) === 1) {
            return (int) $version;
        }

        // Une ligne sans version lisible est une ligne écrite hors du cast : on la
        // lit sous la disposition courante plutôt que de casser un salon en jeu.
        return RoomSettings::VERSION;
    }
}
