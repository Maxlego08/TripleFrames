<?php

namespace App\Settings;

use App\Enums\InputDifficulty;
use App\Enums\SettingPresetKey;

/**
 * Les quatre presets livrés **par le site** (§ 6.3) — leurs cinq chiffres
 * normatifs et leur ordre d'affichage, et rien d'autre.
 *
 * **Pourquoi ce chiffrage ne vit pas dans une factory.** `fakerphp/faker` est en
 * `require-dev`, alors que `Database\Factories\` est déclaré dans `autoload` :
 * une installation `--no-dev` charge donc la classe de fabrique mais pas sa
 * dépendance. Tant que les méthodes statiques ne touchent jamais `fake()`, cela
 * fonctionne — la marge est d'un seul appel. Le jour où quelqu'un ajoute un
 * défaut aléatoire à `SettingPresetFactory`, `php artisan db:seed --force` sur une
 * instance servie tombe sur un `Class "Faker\Generator" not found`, et l'instance
 * démarre **sans aucun preset**, donc sans possibilité de créer un salon.
 *
 * C'est le même motif que celui qui fait vivre les bornes dans
 * {@see RoomSettingsBounds} et jamais dans une migration : un chiffrage normatif
 * n'habite pas `database/`. La fabrique en devient **lectrice**, jamais source, et
 * le seeder de production ne dépend plus que d'`app/`.
 *
 * **Chaque preset passe par {@see RoomSettings::fromInput()}** : un preset livré
 * par le site ne doit jamais produire un salon inlançable (5 × 5 = 25 ≤ 45 pour
 * Hardcore, 5 × 2 = 10 ≤ 15 pour Rapide). Un chiffrage fautif lève au seeding, et
 * non dans un lobby.
 */
final readonly class SettingPresetCatalog
{
    /**
     * Les réglages d'un preset, construits et VALIDÉS par le value object.
     *
     * Seuls cinq champs sont chiffrés par le produit ; tout le reste — paliers,
     * barème, capacité, anti-spam — descend des défauts de
     * {@see RoomSettingsBounds} pour le `N` demandé, ce qui est précisément ce
     * qu'on veut : un preset ne fige jamais une valeur que le site n'a pas
     * explicitement arbitrée.
     */
    public static function settingsFor(SettingPresetKey $key): RoomSettings
    {
        return RoomSettings::fromInput(self::inputFor($key));
    }

    /**
     * Les cinq chiffres normatifs de chaque preset, et rien d'autre.
     *
     * Source unique : `docs/specs/10-catalogue-et-modele-de-donnees.md` § 6.3,
     * repris sans modification de `00-overview.md` et de `questions-ouvertes.md`.
     *
     * @return array<string, mixed>
     */
    public static function inputFor(SettingPresetKey $key): array
    {
        return match ($key) {
            SettingPresetKey::Classic => [
                RoomSettings::INPUT_ROUND_DURATION => 30,
                'framesPerRound' => 3,
                'inputDifficulty' => InputDifficulty::Normal,
                'roundsCount' => 10,
                'revealDuration' => 8,
            ],
            SettingPresetKey::Fast => [
                RoomSettings::INPUT_ROUND_DURATION => 15,
                'framesPerRound' => 2,
                'inputDifficulty' => InputDifficulty::Easy,
                'roundsCount' => 8,
                'revealDuration' => 5,
            ],
            SettingPresetKey::Hardcore => [
                RoomSettings::INPUT_ROUND_DURATION => 45,
                'framesPerRound' => 5,
                'inputDifficulty' => InputDifficulty::Expert,
                'roundsCount' => 10,
                'revealDuration' => 10,
            ],
            SettingPresetKey::Discovery => [
                RoomSettings::INPUT_ROUND_DURATION => 60,
                'framesPerRound' => 3,
                'inputDifficulty' => InputDifficulty::Easy,
                'roundsCount' => 5,
                'revealDuration' => 15,
            ],
        };
    }

    /**
     * L'ordre d'affichage, figé par le site : il ne dépend d'aucun tri
     * alphabétique, qui changerait d'une langue à l'autre.
     */
    public static function positionFor(SettingPresetKey $key): int
    {
        return match ($key) {
            SettingPresetKey::Classic => 1,
            SettingPresetKey::Fast => 2,
            SettingPresetKey::Hardcore => 3,
            SettingPresetKey::Discovery => 4,
        };
    }
}
