<?php

namespace App\Support\Room;

use App\Enums\PoolRemedyKind;
use App\Enums\SettingPresetKey;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use Carbon\CarbonImmutable;

/**
 * Les charges utiles des réglages de salon vers le client (spec 50 § 2.6,
 * contrat C0 § 3.4) — en DONNÉES : entiers, booléens, codes et clés de thème,
 * jamais une chaîne traduite, jamais un identifiant interne (règle 3, 10 § 1.1).
 *
 * - {@see self::view()} : `RoomSettingsView`, les seize clés de
 *   `RoomSettings::toPayload()` avec `themeIds` remplacé par `themeKeys`. C'est
 *   la SEULE divergence entre le stockage et le client (E10-11).
 * - {@see self::state()} : `RoomSettingsState`, diffusé au salon identique pour
 *   tous (`settings.changed`, `room.replayed`) ; chaque client le met en mots
 *   dans sa propre langue.
 * - {@see self::presets()} : l'aide de grisage des presets, calculée au rendu
 *   du lobby et jamais stockée (§ 5.3).
 * - {@see self::changes()} : le rapport de changements, ciblé vers l'auteur
 *   seul, sous les clés client.
 *
 * Le vivier y est calculé par le constructeur unique de la spec 30 (contrat
 * C2), sur la même non-répétition et la même fenêtre de mémoire que la garde de
 * lancement : un compteur ou un preset affiché jouable ne se bloque pas au
 * lancement pour une autre raison que le catalogue qui a bougé entre-temps.
 *
 * @phpstan-type RoomSettingsViewPayload array<string, list<string>|list<int>|int|string|bool>
 * @phpstan-type PoolReportPayload array{count: int, framesPerRound: int, roundsCount: int, blocked: bool, causes: list<string>, remedies: list<array{kind: string, value: int|null, count: int}>, nearestPlayableFramesPerRound: int|null, themesPruned: bool}
 * @phpstan-type RoomSettingsStatePayload array{settings: RoomSettingsViewPayload, warnings: list<string>, pool: PoolReportPayload}
 * @phpstan-type PresetOptionPayload array{key: string, grayed: bool, nearestPlayableFramesPerRound: int|null}
 */
final readonly class RoomSettingsPresenter
{
    /** Clé de stockage de la sélection de thèmes, remplacée à la frontière. */
    private const string THEME_IDS = 'themeIds';

    /**
     * `RoomSettingsView` : `themeKeys` à la place de `themeIds`, à la même
     * position, dans l'ordre de `themeIds`.
     *
     * Un thème dépublié resté dans les réglages est OMIS : le rapport de vivier
     * le signale par `themesPruned`, que le lobby affiche à tous (§ 9.2). Une
     * sélection vide ne lit aucun thème — le cas du J1, sélecteur masqué.
     *
     * @return RoomSettingsViewPayload
     */
    public static function view(RoomSettings $settings): array
    {
        $view = [];

        foreach ($settings->toPayload() as $field => $value) {
            if ($field === self::THEME_IDS) {
                $view[RoomSettingsEditor::THEME_KEYS] = self::themeKeys($settings->themeIds);

                continue;
            }

            $view[$field] = $value;
        }

        return $view;
    }

    /**
     * `RoomSettingsState` du salon à l'instant `$now`, sur ses réglages tels que
     * relus par le cast.
     *
     * `pool` est exactement `PoolReport::toArray()` (contrat C2, R-11), à une
     * exception près : tant que l'onglet Avancé n'est pas livré, le remède
     * `disable_no_repeat` en est retiré (D28 du 23/09) — l'interrupteur qu'il
     * propose n'existe pas encore à l'écran, et un nouveau salon
     * (`open_new_room`) reste proposé.
     *
     * @return RoomSettingsStatePayload
     */
    public static function state(Room $room, CarbonImmutable $now): array
    {
        $settings = $room->settings;
        $pool = app(PoolReporter::class)
            ->report(PoolScope::forRoom($room, $settings, $now), $settings->roundsCount)
            ->toArray();

        if (! self::advancedTabAvailable()) {
            $pool['remedies'] = array_values(array_filter(
                $pool['remedies'],
                static fn (array $remedy): bool => $remedy['kind'] !== PoolRemedyKind::DisableNoRepeat->value,
            ));
        }

        return [
            'settings' => self::view($settings),
            'warnings' => $settings->warnings(),
            'pool' => $pool,
        ];
    }

    /**
     * L'aide de grisage des quatre presets pour ce salon, triée par position
     * (prop `presets` du lobby, § 5.3).
     *
     * `grayed = PoolReporter::report(PoolScope::forRoom($room, preset, $now), M_preset)->blocked()`,
     * et `nearestPlayableFramesPerRound` lu dans le même rapport : même
     * constructeur, même non-répétition du salon et même fenêtre que la garde de
     * lancement. Une aide seulement : appliquer un preset grisé reste permis.
     *
     * @return list<PresetOptionPayload>
     */
    public static function presets(Room $room, CarbonImmutable $now): array
    {
        $reporter = app(PoolReporter::class);
        $keys = SettingPresetKey::cases();

        usort(
            $keys,
            static fn (SettingPresetKey $a, SettingPresetKey $b): int => SettingPresetCatalog::positionFor($a)
                <=> SettingPresetCatalog::positionFor($b),
        );

        $options = [];

        foreach ($keys as $key) {
            $settings = SettingPresetCatalog::settingsFor($key);
            $report = $reporter->report(PoolScope::forRoom($room, $settings, $now), $settings->roundsCount);

            $options[] = [
                'key' => $key->value,
                'grayed' => $report->blocked(),
                'nearestPlayableFramesPerRound' => $report->nearestPlayableFramesPerRound,
            ];
        }

        return $options;
    }

    /**
     * Le rapport de changements sous les clés client : `themeIds` devient
     * `themeKeys`, tout le reste passe tel quel, dans l'ordre reçu.
     *
     * @param  array<string, string>  $changes  Champ de stockage → code `RoomSettings::CHANGE_*`.
     * @return array<string, string>
     */
    public static function changes(array $changes): array
    {
        $client = [];

        foreach ($changes as $field => $code) {
            $client[$field === self::THEME_IDS ? RoomSettingsEditor::THEME_KEYS : $field] = $code;
        }

        return $client;
    }

    /**
     * `theme.key` des thèmes publiés, dans l'ordre de `$themeIds`, dépubliés
     * omis. Seules les clés quittent le serveur.
     *
     * @param  list<int>  $themeIds
     * @return list<string>
     */
    private static function themeKeys(array $themeIds): array
    {
        if ($themeIds === []) {
            return [];
        }

        $keysById = array_flip(app(PoolQuery::class)->publishedThemeIdsByKey());
        $keys = [];

        foreach ($themeIds as $themeId) {
            if (array_key_exists($themeId, $keysById)) {
                $keys[] = $keysById[$themeId];
            }
        }

        return $keys;
    }

    /**
     * Lecture de `RoomSettingsEditor::ADVANCED_TAB_AVAILABLE` derrière un type
     * `bool` : la constante vaut `false` au J1, et une condition écrite sur elle
     * seule serait lue comme toujours vraie par l'analyse statique.
     */
    private static function advancedTabAvailable(): bool
    {
        return RoomSettingsEditor::ADVANCED_TAB_AVAILABLE;
    }
}
