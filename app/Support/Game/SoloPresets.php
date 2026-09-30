<?php

namespace App\Support\Game;

use App\Actions\Game\StartSoloGame;
use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Draw\PoolQuery;
use App\Support\Draw\PoolReport;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\ValueObjects\Game\SoloPresetSettings;
use Illuminate\Validation\ValidationException;

/**
 * Les réglages du solo (D19 du 23/09, spec 60 § 16.3 ; contrat C7 § 4.12) :
 * le joueur choisit **un des quatre presets du site**, sans formulaire de
 * réglages.
 *
 * **Vivier catalogue**, jamais celui d'un salon : `PoolScope::catalogue(
 * $themeIds, N)` (contrat C2 ; un film et son remake ne comptent qu'une
 * fois), sans mémoire ni non-répétition. Si le vivier ne tient pas les `M`
 * œuvres du preset à son `N`, le **`N` jouable le plus proche** — toujours
 * vers le bas (30 § 4.4) — s'applique d'office : les champs dérivés sont
 * redérivés par la règle Simple ({@see RoomSettingsEditor::simple()},
 * contrat C0 § 3.3, D34 du 23/09) — barème par défaut du nouveau `N`,
 * paliers réégalisés sur la même durée `D`, plafond de tentatives inchangé
 * (`D` ne bouge pas). Sans aucun `N` jouable : refus, rapport en données.
 *
 * Au J1, sur un catalogue en passe 1 (niveaux 1, 3 et 5), **Hardcore se joue
 * en Expert à N = 3**.
 *
 * Lecture seule : aucune transaction ouverte, aucun verrou, aucune écriture.
 * {@see StartSoloGame} l'appelle dans sa transaction, avant toute écriture de
 * partie ; `OpenGame` rejoue ensuite la garde sur le même périmètre (C6 O2,
 * O3), seule à faire autorité.
 */
final readonly class SoloPresets
{
    public function __construct(
        private PoolReporter $reporter,
        private PoolQuery $pool,
    ) {}

    /**
     * Les réglages à jouer pour ce preset (D19).
     *
     * @throws ValidationException Jamais attendue : un preset du site et son
     *                             `N` abaissé passent par le seul
     *                             constructeur borné — un chiffrage fautif
     *                             lève, comme au seeding.
     */
    public function resolve(SettingPresetKey $key): SoloPresetSettings
    {
        $settings = SettingPresetCatalog::settingsFor($key);
        $report = $this->report($settings);

        if (! $report->blocked()) {
            return new SoloPresetSettings($key, $settings, $report, $settings->framesPerRound);
        }

        $nearest = $report->nearestPlayableFramesPerRound;

        if ($nearest === null) {
            return new SoloPresetSettings($key, null, $report, $settings->framesPerRound);
        }

        $derived = RoomSettingsEditor::simple(
            $settings,
            ['framesPerRound' => $nearest],
            $this->pool->publishedThemeIdsByKey(),
        );

        return new SoloPresetSettings($key, RoomSettings::fromInput($derived['input']), $report, $settings->framesPerRound);
    }

    /**
     * Les quatre presets dans l'ordre du site (`SettingPresetCatalog::
     * positionFor()`), chacun avec son `N` jouable le plus proche sur le
     * vivier catalogue : props `presets` de `room/solo` et de `game/solo`
     * (§ 16.4), même forme que les presets du lobby (spec 50 § 5.3). Un
     * preset « grisé » se joue quand même, à `nearestPlayableFramesPerRound`
     * images par manche ; sans `N` jouable, son démarrage est refusé.
     *
     * @return list<array{key: string, grayed: bool, nearestPlayableFramesPerRound: int|null}>
     */
    public function options(): array
    {
        $keys = SettingPresetKey::cases();

        usort(
            $keys,
            static fn (SettingPresetKey $a, SettingPresetKey $b): int => SettingPresetCatalog::positionFor($a)
                <=> SettingPresetCatalog::positionFor($b),
        );

        $options = [];

        foreach ($keys as $key) {
            $report = $this->report(SettingPresetCatalog::settingsFor($key));

            $options[] = [
                'key' => $key->value,
                'grayed' => $report->blocked(),
                'nearestPlayableFramesPerRound' => $report->nearestPlayableFramesPerRound,
            ];
        }

        return $options;
    }

    /** Le rapport du vivier catalogue aux thèmes et au `N` des réglages, pour leurs `M` manches. */
    private function report(RoomSettings $settings): PoolReport
    {
        return $this->reporter->report(
            PoolScope::catalogue($settings->themeIds, $settings->framesPerRound),
            $settings->roundsCount,
        );
    }
}
