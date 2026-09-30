<?php

namespace Database\Factories;

use App\Casts\RoomSettingsCast;
use App\Enums\SettingPresetKey;
use App\Models\SettingPreset;
use App\Settings\RoomSettings;
use App\Settings\SettingPresetCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un preset de réglages livré PAR LE SITE (§ 6.3).
 *
 * Ce ne sont pas des données de démonstration : les quatre presets sont du contenu
 * livré avec le code, réconcilié par un seeder toujours joué, sans aucune route
 * d'écriture et sans policy accordant `update` ou `delete`, y compris à un admin. Le
 * seeder est le seul écrivain.
 *
 * **Cette fabrique est LECTRICE du chiffrage, jamais sa source.** Les quatre presets
 * vivent dans {@see SettingPresetCatalog}, sous `app/` : `fakerphp/faker` est en
 * `require-dev` alors que `Database\Factories\` est dans `autoload`, et le seeder qui
 * pose les presets est le seul joué en production. Les trois méthodes statiques
 * ci-dessous ne sont plus que des renvois, gardés pour les tests qui les nomment.
 *
 * Aucune colonne de libellé : nom et description sont des clés de `lang/`
 * ({@see SettingPresetKey::labelKey()}), contrairement aux `theme_label` qui sont
 * éditables en back-office.
 *
 * `settings_version` n'est jamais posée ici : {@see RoomSettingsCast::set()}
 * l'écrit en même temps que la charge utile, jamais séparément.
 *
 * **Chaque preset passe par {@see RoomSettings::fromInput()}** : un preset livré par le
 * site ne doit jamais produire un salon inlançable (5 × 5 = 25 ≤ 45 pour Hardcore,
 * 5 × 2 = 10 ≤ 15 pour Rapide). Un chiffrage fautif lève au seeding, et non dans un
 * lobby.
 *
 * @extends Factory<SettingPreset>
 */
class SettingPresetFactory extends Factory
{
    /**
     * Compteur de rotation : `setting_preset_key_uq` interdit deux fois la même clé, et
     * la table ne compte que quatre lignes — un défaut fixe rendrait
     * `SettingPreset::factory()->count(4)` structurellement impossible.
     *
     * Quatre valeurs consécutives couvrent toujours les quatre résidus :
     * `->count(4)->create()` rend donc **toujours** les quatre presets du site, à une
     * rotation près. En revanche, un appel ISOLÉ rend l'un des quatre sans garantie
     * duquel — un test qui dépend d'un preset précis le nomme, par {@see self::classic()}
     * ou {@see self::preset()}.
     */
    private static int $rotation = 0;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cases = SettingPresetKey::cases();
        $key = $cases[self::$rotation++ % count($cases)];

        return $this->attributesFor($key);
    }

    /**
     * Classique — 30 s / 3 images / Normal / 10 manches / 8 s de révélation.
     */
    public function classic(): static
    {
        return $this->preset(SettingPresetKey::Classic);
    }

    /**
     * Rapide — 15 s / 2 images / Facile / 8 manches / 5 s de révélation.
     */
    public function fast(): static
    {
        return $this->preset(SettingPresetKey::Fast);
    }

    /**
     * Hardcore — 45 s / 5 images / Expert / 10 manches / 10 s de révélation.
     */
    public function hardcore(): static
    {
        return $this->preset(SettingPresetKey::Hardcore);
    }

    /**
     * Découverte — 60 s / 3 images / Facile / 5 manches / 15 s de révélation.
     */
    public function discovery(): static
    {
        return $this->preset(SettingPresetKey::Discovery);
    }

    /**
     * Le preset nommé, avec sa position figée et son chiffrage validé.
     */
    public function preset(SettingPresetKey $key): static
    {
        return $this->state($this->attributesFor($key));
    }

    /**
     * Les réglages d'un preset — **lus** dans {@see SettingPresetCatalog}, jamais
     * définis ici : un chiffrage normatif n'habite pas `database/factories`, dont
     * la dépendance `fakerphp/faker` est en `require-dev` alors que le seeder qui
     * pose les presets est le seul joué en production.
     */
    public static function settingsFor(SettingPresetKey $key): RoomSettings
    {
        return SettingPresetCatalog::settingsFor($key);
    }

    /**
     * Les cinq chiffres normatifs du preset. Renvoi vers {@see SettingPresetCatalog}.
     *
     * @return array<string, mixed>
     */
    public static function inputFor(SettingPresetKey $key): array
    {
        return SettingPresetCatalog::inputFor($key);
    }

    /**
     * L'ordre d'affichage figé par le site. Renvoi vers {@see SettingPresetCatalog}.
     */
    public static function positionFor(SettingPresetKey $key): int
    {
        return SettingPresetCatalog::positionFor($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(SettingPresetKey $key): array
    {
        return [
            'key' => $key,
            'position' => self::positionFor($key),
            'settings' => self::settingsFor($key),
        ];
    }
}
