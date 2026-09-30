<?php

namespace App\Avatars;

use App\Settings\PlatformLimits;
use InvalidArgumentException;

/**
 * Registre des avatars prédéfinis — contrat C5, seconde moitié (spec 40 § 6.3).
 *
 * **Seul registre des clés.** La règle de formulaire (`avatarPresetRules()`), la
 * signature et le décodage du `player_token`, les fabriques et la couverture de
 * traduction passent par lui ; personne d'autre n'écrit `preset-%02d`.
 *
 * **Une clé n'est jamais un chemin** (I5.7) : `preset-01` à `preset-NN` restent
 * stables quand le pack change. Changer de pack, c'est changer les fichiers de
 * `public/avatars/`, les libellés `common.avatar.preset.*` et la table clé →
 * fichier d'origine de `public/avatars/LICENSE.md`, sans migration de schéma ni
 * réécriture de ligne (10 § 5.1).
 *
 * Le nombre de clés vaut {@see PlatformLimits::avatarPresets()} (24 par défaut),
 * jamais un littéral : une valeur changée sans les fichiers correspondants fait
 * échouer `AvatarPresetTest`. La garde `roomSeats() ≤ avatarPresets()` (C0,
 * prouvée dans `PlatformLimitsTest`) garantit qu'un salon plein trouve toujours
 * un prédéfini libre.
 *
 * Aucun état statique : la liste est recalculée depuis `PlatformLimits`, lié
 * `scoped` dans le conteneur, pour qu'un test qui change la configuration ne lise
 * jamais la liste d'un test précédent.
 */
final class AvatarPresetCatalog
{
    /** Format d'une clé, indexée de 1 à {@see PlatformLimits::avatarPresets()}. */
    public const string KEY_FORMAT = 'preset-%02d';

    /** Préfixe des libellés, domaine `common` (embarqué par toute page, 05 C15). */
    public const string LABEL_KEY_PREFIX = 'common.avatar.preset.';

    /**
     * Clés du catalogue, dans l'ordre de présélection.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        for ($index = 1; $index <= PlatformLimits::avatarPresets(); $index++) {
            $keys[] = sprintf(self::KEY_FORMAT, $index);
        }

        return $keys;
    }

    /** Vrai si la clé appartient au catalogue, à l'octet près. */
    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /**
     * Clé de traduction du libellé d'un prédéfini (« Hibou »), qui ne sert que
     * de nom accessible d'une option du sélecteur (I5.9).
     *
     * @throws InvalidArgumentException si la clé n'appartient pas au catalogue :
     *                                  une clé de traduction n'est jamais bâtie
     *                                  sur une valeur que le registre ne connaît pas.
     */
    public static function labelKey(string $key): string
    {
        if (! self::has($key)) {
            throw new InvalidArgumentException(sprintf('Avatar prédéfini inconnu : [%s].', $key));
        }

        return self::LABEL_KEY_PREFIX.$key;
    }

    /**
     * Présélection déterministe (I5.8) :
     *
     * 1. `$preferred` s'il appartient au catalogue et n'est pas pris ;
     * 2. sinon la première clé libre dans l'ordre de {@see self::keys()} ;
     * 3. sinon — impossible sous la garde `roomSeats() ≤ avatarPresets()` —
     *    `$preferred` s'il est valide, ou la première clé.
     *
     * C'est une suggestion, jamais une contrainte : le doublon reste permis, le
     * pseudo (unique par salon) est le discriminant.
     *
     * @param  string|null  $preferred  Revendication `avatar` du jeton courant.
     * @param  list<string>  $taken  Avatars des sièges tenus dans le salon.
     */
    public static function suggest(?string $preferred, array $taken): string
    {
        $keys = self::keys();
        $taken = array_flip($taken);
        $valid = $preferred !== null && in_array($preferred, $keys, true);

        if ($valid && ! isset($taken[$preferred])) {
            return $preferred;
        }

        foreach ($keys as $key) {
            if (! isset($taken[$key])) {
                return $key;
            }
        }

        if ($valid) {
            return $preferred;
        }

        return $keys[0] ?? throw new InvalidArgumentException('Le catalogue des avatars prédéfinis est vide.');
    }

    /**
     * Options du sélecteur, dans l'ordre de {@see self::keys()} : données, jamais
     * de libellé résolu côté serveur (règle 4).
     *
     * @return list<array{key: string, url: string, labelKey: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (string $key): array => [
                'key' => $key,
                'url' => AvatarRef::presetUrl($key),
                'labelKey' => self::labelKey($key),
            ],
            self::keys(),
        );
    }
}
