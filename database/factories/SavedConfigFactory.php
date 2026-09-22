<?php

namespace Database\Factories;

use App\Models\SavedConfig;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Une configuration de salon sauvegardée par un compte (§ 6.3).
 *
 * C'est la raison n° 1 de créer un compte : le jeu reste entier sans lui, seule la
 * persistance est débloquée (règle 11).
 *
 * **`name` et `name_normalized` ne se posent jamais séparément** : la seconde porte
 * `saved_config_user_name_uq`, et sans elle « Ma config » et « ma config » fusionnent
 * en MySQL et coexistent en SQLite — le même test passerait au vert d'un côté et au
 * rouge de l'autre. {@see self::named()} est le seul chemin de renommage.
 *
 * `default_slot` est un CRÉNEAU d'unicité (`null` ou `'d'`), pas un booléen : c'est la
 * seule écriture portable de « au plus une configuration par défaut par utilisateur »,
 * MySQL 8 n'ayant aucun index partiel. {@see self::asDefault()} l'occupe — un second
 * appel pour le même compte doit échouer, et c'est le comportement voulu.
 *
 * Le plafond de {@see PlatformLimits::savedConfigsPerUser()} n'est PAS une contrainte
 * de base : il est appliqué par l'action de création, jamais par cette fabrique.
 *
 * @extends Factory<SavedConfig>
 */
class SavedConfigFactory extends Factory
{
    /**
     * Borne produit du nom, **et** longueur des deux colonnes `name` et
     * `name_normalized` (§ 1.3). Un seul chiffre pour les deux : les borner
     * séparément est ce qui laisse passer une 1406 en MySQL et une troncature muette
     * en SQLite.
     */
    public const int NAME_MAX_LENGTH = 40;

    /**
     * Compteur de noms : deux configurations d'un même compte ne peuvent pas porter la
     * même forme normalisée.
     */
    private static int $nameSequence = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Config '.(++self::$nameSequence);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'name_normalized' => self::normalizeName($name),
            'settings' => RoomSettings::defaults(),
            'default_slot' => null,
        ];
    }

    /**
     * Le nom affiché ET sa forme normalisée, ensemble et dans la même écriture.
     *
     * **Les deux colonnes sont bornées ensemble**, et à la même longueur : `name`
     * est un `varchar(40)`, donc un nom plus long lèverait une 1406 en MySQL strict
     * et serait tronqué en silence en SQLite — le même test au vert d'un côté et au
     * rouge de l'autre, c'est-à-dire exactement la divergence de moteurs que
     * `name_normalized` a été introduite pour fermer.
     */
    public function named(string $name): static
    {
        return $this->state([
            'name' => mb_substr($name, 0, self::NAME_MAX_LENGTH),
            'name_normalized' => self::normalizeName($name),
        ]);
    }

    /**
     * Les réglages exacts — toujours une instance du value object : le cast n'accepte
     * rien d'autre, et une charge utile JSON brute serait refusée.
     */
    public function withSettings(RoomSettings $settings): static
    {
        return $this->state(['settings' => $settings]);
    }

    /**
     * La configuration proposée d'office à la création d'un salon.
     */
    public function asDefault(): static
    {
        return $this->state(['default_slot' => SavedConfig::DEFAULT_SLOT]);
    }

    public function notDefault(): static
    {
        return $this->state(['default_slot' => null]);
    }

    /**
     * Normalisation de fixture : repli d'alphabet, minuscules, espaces réduits, borne
     * à 40 caractères.
     *
     * > **Provisoire, et nommément.** La normalisation canonique du produit appartient
     * > à `70-validation-des-reponses.md`, qui n'est pas écrite. Le jour où le
     * > normaliseur partagé existe, cette méthode disparaît et l'action de création
     * > devient la seule source — la fabrique ne doit surtout pas en devenir une
     * > seconde. `ext-intl` est absent de cet environnement : `Str::ascii()` est le
     * > seul repli d'alphabet disponible, et il est identique dans les deux moteurs
     * > puisqu'il s'exécute en PHP (§ 1.4).
     */
    public static function normalizeName(string $name): string
    {
        $folded = Str::ascii($name);
        $collapsed = preg_replace('/\s+/', ' ', $folded) ?? $folded;

        // `mb_substr` et non `Str::limit` : celle-ci compte une LARGEUR
        // (`mb_strwidth`), donc un pseudo CJK de 40 caractères serait replié à 20.
        // La colonne est un `varchar(40)` : elle borne des caractères.
        return mb_substr(Str::lower(trim($collapsed)), 0, self::NAME_MAX_LENGTH);
    }
}
