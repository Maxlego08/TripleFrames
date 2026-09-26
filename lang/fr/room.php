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

    /*
    | Création du salon (spec 50 § 6.2) : le salon naît aux réglages par
    | défaut, tout se règle ensuite dans le lobby.
    */

    'create' => [
        'title' => 'Créer un salon',
        'intro' => 'Choisissez un pseudo et un avatar : vous réglerez la partie dans le salon.',
        'submit' => 'Créer le salon',
    ],

    /*
    | Pseudo et avatar d’un siège, champs communs aux formulaires de création
    | et d’entrée (spec 50 § 6.2 et § 7.2). Les bornes arrivent en props,
    | depuis `NicknameNormalizer`, et le client les formate.
    */

    'identity' => [
        'nickname_label' => 'Pseudo',
        'nickname_hint' => 'Entre :min et :max caractères, unique dans le salon.',
    ],

    /*
    | Entrée dans un salon (spec 50 § 7.2 et § 7.3). `kicked` et `full` sont
    | aussi les messages des refus `JoinRefusal`, construits par
    | `messageKey()` ; `late_join` arrive avec les retardataires (L50-9).
    */

    'join' => [
        'title' => 'Rejoindre le salon',
        'submit' => 'Entrer',
        'kicked' => 'L’hôte vous a retiré de ce salon : vous ne pouvez pas y revenir.',
        'full' => 'Ce salon est complet.',
        'in_progress' => 'Une partie est en cours : vous attendrez dans le salon et jouerez la suivante.',
    ],

    /*
    | Refus d’un geste de salon (spec 50 § 12.5 et § 20.3, contrat C6),
    | construits par `RoomRefusal::messageKey()` et rendus à l’auteur seul.
    | Le refus de drainage emploie `common.maintenance.launch_blocked` :
    | `room.refusal.draining` n’existe pas (R-09).
    */

    'refusal' => [
        'not_host' => 'Seul l’hôte peut faire cela.',
        'room_archived' => 'Ce salon a expiré.',
        'not_in_lobby' => 'Une partie est déjà en cours dans ce salon.',
        'settings_outdated' => 'Les réglages ont été mis à jour : vérifiez-les, puis relancez.',
        'not_enough_players' => 'Il faut au moins :min joueurs connectés pour lancer.',
        'pool_insufficient' => 'Seulement :playable films jouables pour :required manches.',
        'game_not_ended' => 'La partie n’est pas encore terminée.',
    ],

    /*
    | Échec technique d’un lancement (spec 50 § 12.5) : la transaction est
    | annulée, le salon reste au lobby, et l’hôte peut recommencer.
    */

    'errors' => [
        'launch_failed' => 'Le lancement a échoué. Réessayez dans un instant.',
    ],

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
