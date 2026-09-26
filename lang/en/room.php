<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `room` : création, réglages, lobby, presets
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. Il ne porte que des lignes appelées par
    | du code livré : les écrans de création et d’entrée (L50-3b) et les huit
    | lignes des presets — {@see \App\Enums\SettingPresetKey} construit
    | `room.presets.<clé>.{label,description}` — : la spec interdit d’inventer
    | des clés pour des écrans absents. `setting_preset` n’a **aucune colonne
    | de libellé** ni table de libellé par locale (spec 10 § 6.3) : un preset
    | est du contenu livré avec le code, et ce fichier est le seul endroit où
    | son nom puisse vivre.
    |
    | Les libellés et les bornes de chaque réglage appartiennent à la spec
    | `50-salon-reglages-presets-et-lobby.md`.
    |
    */

    /*
    | Création du salon (spec 50 § 6.2) : le salon naît aux réglages par
    | défaut, tout se règle ensuite dans le lobby.
    */

    'create' => [
        'title' => 'Create a room',
        'intro' => 'Pick a nickname and an avatar: you will set up the game in the room.',
        'submit' => 'Create room',
    ],

    /*
    | Pseudo et avatar d’un siège, champs communs aux formulaires de création
    | et d’entrée (spec 50 § 6.2 et § 7.2). Les bornes arrivent en props,
    | depuis `NicknameNormalizer`, et le client les formate.
    */

    'identity' => [
        'nickname_label' => 'Nickname',
        'nickname_hint' => 'Between :min and :max characters, unique in the room.',
    ],

    /*
    | Entrée dans un salon (spec 50 § 7.2 et § 7.3). `kicked` et `full` sont
    | aussi les messages des refus `JoinRefusal`, construits par
    | `messageKey()` ; `late_join` arrive avec les retardataires (L50-9).
    */

    'join' => [
        'title' => 'Join the room',
        'submit' => 'Join',
        'kicked' => 'The host removed you from this room: you cannot come back.',
        'full' => 'This room is full.',
        'in_progress' => 'A game is in progress: you will wait in the room and play the next one.',
    ],

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
