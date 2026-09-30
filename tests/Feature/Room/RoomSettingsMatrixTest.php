<?php

use App\Settings\RoomSettings;
use Tests\Support\Room\RoomSettingsCase;
use Tests\Support\Room\RoomSettingsMatrix;

/*
|--------------------------------------------------------------------------
| Matrice des réglages — bornes croisées côté serveur (règle 2)
|--------------------------------------------------------------------------
|
| Spec 100 § 4 et contrat C18 § 2.7 ; règle d'interaction de la spec 50
| § 4.1. Un FormRequest champ par champ laisserait passer 10 s × 5 images :
| la matrice prouve, combinaison par combinaison aux bornes, que
| `RoomSettings::validate()` refuse EXACTEMENT ce qu'il doit refuser et que
| `warnings()` lève EXACTEMENT ce qu'il doit lever — ni moins, ni plus.
|
| Les verdicts attendus sont écrits à la main par `RoomSettingsMatrix` à
| partir des bornes nommées, jamais recalculés par le code éprouvé. La
| comparaison se fait à l'ordre près : l'ordre du sac d'erreurs n'est pas une
| règle du jeu.
|
*/

it('refuse exactement les champs déclarés pour chaque combinaison aux bornes', function (RoomSettingsCase $case): void {
    expect(array_keys(RoomSettings::validate($case->input)))
        ->toEqualCanonicalizing($case->refusedFields);
})->with('room_settings.matrix');

it('lève exactement les avertissements déclarés pour chaque combinaison acceptée', function (RoomSettingsCase $case): void {
    expect(RoomSettings::fromInput($case->input)->warnings())
        ->toEqualCanonicalizing($case->warnings);
})->with(fn (): iterable => RoomSettingsMatrix::acceptedCases());
