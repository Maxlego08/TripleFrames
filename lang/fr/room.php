<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `room` : création, réglages, lobby, presets
    |--------------------------------------------------------------------------
    |
    | Miroir exact de `lang/en/room.php`. Un preset n’a aucune colonne de
    | libellé : son nom n’existe qu’ici.
    |
    */

    'presets' => [
        'classic' => [
            'label' => 'Classique',
            'description' => 'Le réglage de référence, équilibré pour une partie entre amis.',
        ],
        'fast' => [
            'label' => 'Rapide',
            'description' => 'Manches courtes et propositions à choix multiples : une partie expédiée.',
        ],
        'hardcore' => [
            'label' => 'Hardcore',
            'description' => 'Plus d’images, plus de temps, saisie libre de bout en bout.',
        ],
        'discovery' => [
            'label' => 'Découverte',
            'description' => 'Manches longues et révélations généreuses, pour découvrir le catalogue.',
        ],
    ],

];
