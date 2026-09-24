<?php

use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;

/*
|--------------------------------------------------------------------------
| Presets livrés — contrat C0, spec 50 § 5, contrat C18 (jeu `room_settings.presets`)
|--------------------------------------------------------------------------
|
| Un preset livré par le site ne doit jamais produire un salon que l'hôte
| n'aurait pas pu régler lui-même : il passe par le chemin d'entrée de l'hôte
| (`fromInput()`), n'y poste que des clés de l'onglet Simple, et n'y lève
| aucune erreur. Un chiffrage fautif lève ici, et non dans un lobby.
|
*/

it('construit chaque preset livré par le chemin d\'entrée de l\'hôte sans erreur', function (SettingPresetKey $preset): void {
    $input = SettingPresetCatalog::inputFor($preset);

    // Seules des clés que l'onglet Simple, seul onglet du J1, sait poster.
    expect(array_values(array_diff(array_keys($input), RoomSettingsEditor::SIMPLE_KEYS)))->toBe([]);

    expect(RoomSettings::validate($input))->toBe([]);

    $settings = RoomSettings::fromInput($input);

    expect($settings->sourceVersion)->toBe(RoomSettings::VERSION);
    expect($settings->advanced)->toBeFalse();
    expect(SettingPresetCatalog::settingsFor($preset)->equals($settings))->toBeTrue();
})->with('room_settings.presets');
