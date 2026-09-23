<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `room` : création, réglages, lobby, presets
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. Il ne porte pour l’instant que les huit
    | lignes déjà appelées par du code livré — {@see \App\Enums\SettingPresetKey}
    | construit `room.presets.<clé>.{label,description}` — : la spec interdit
    | d’inventer des clés pour des écrans absents, mais celles-ci ne sont pas
    | inventées. `setting_preset` n’a **aucune colonne de libellé** ni table de
    | libellé par locale (spec 10 § 6.3) : un preset est du contenu livré avec
    | le code, et ce fichier est le seul endroit où son nom puisse vivre.
    |
    | Les libellés et les bornes de chaque réglage appartiennent à la spec
    | `50-salon-reglages-presets-et-lobby.md`.
    |
    */

    'presets' => [

        // Aucune valeur chiffrée dans ces descriptions : les cinq chiffres d’un
        // preset vivent dans `SettingPresetCatalog` et sont rendus depuis les
        // réglages stockés. Les réécrire ici en ferait une seconde vérité.
        'classic' => [
            'label' => 'Classic',
            'description' => 'The reference setup, balanced for a game among friends.',
        ],
        'fast' => [
            'label' => 'Fast',
            'description' => 'Short rounds and multiple-choice answers: a game over quickly.',
        ],
        'hardcore' => [
            'label' => 'Hardcore',
            'description' => 'More frames, more time, free-text answers all the way through.',
        ],
        'discovery' => [
            'label' => 'Discovery',
            'description' => 'Long rounds and generous reveals, to get to know the catalogue.',
        ],
    ],

];
