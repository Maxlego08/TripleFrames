<?php

use App\Enums\SettingPresetKey;
use Tests\Support\Room\RoomSettingsMatrix;

/*
|--------------------------------------------------------------------------
| Matrice des réglages — jeux nommés du contrat C18 § 2.7 (spec 100 § 4)
|--------------------------------------------------------------------------
|
| Déterministes, aux étiquettes stables. Chaque jeu est une fermeture : Pest
| la rappelle à chaque résolution, jamais un générateur déjà consommé.
|
| - `room_settings.matrix` : tous les cas, `RoomSettingsCase`, refusés comme
|   acceptés (`RoomSettingsMatrixTest`).
| - `room_settings.accepted` : chaque combinaison acceptée, en `RoomSettings`
|   construit par `fromInput()` au moment du test (fermeture liée). Lu par 60
|   (`RoundTierMaterializationTest`) et 80 (`SpeedBonusTest`).
| - `room_settings.presets` : les presets livrés, `SettingPresetKey`, étiquetés
|   par leur clé (`PresetValidityTest` de 50).
|
*/

dataset('room_settings.matrix', fn (): iterable => RoomSettingsMatrix::cases());

dataset('room_settings.accepted', fn (): iterable => RoomSettingsMatrix::accepted());

dataset('room_settings.presets', function (): iterable {
    foreach (SettingPresetKey::cases() as $preset) {
        yield $preset->value => [$preset];
    }
});
