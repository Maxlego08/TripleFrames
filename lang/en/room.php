<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `room` : création, réglages, lobby, presets
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. Il ne porte que des lignes appelées par
    | du code livré : les écrans de création et d’entrée (L50-3b), les refus
    | de geste de salon — {@see \App\Enums\RoomRefusal} construit
    | `room.refusal.<valeur>` — et l’échec technique du lancement (L50-7a),
    | la page du salon et son vivier (L50-4 : `room.lobby.*`, `room.pool.*`),
    | et les huit lignes des presets — {@see \App\Enums\SettingPresetKey} construit
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

    /*
    | Refus d’un geste de salon (spec 50 § 12.5 et § 20.3, contrat C6),
    | construits par `RoomRefusal::messageKey()` et rendus à l’auteur seul.
    | Le refus de drainage emploie `common.maintenance.launch_blocked` :
    | `room.refusal.draining` n’existe pas (R-09).
    */

    'refusal' => [
        'not_host' => 'Only the host can do this.',
        'room_archived' => 'This room has expired.',
        'not_in_lobby' => 'A game is already running in this room.',
        'settings_outdated' => 'The settings were updated: check them, then start again.',
        'not_enough_players' => 'At least :min connected players are needed to start.',
        'pool_insufficient' => 'Only :playable playable movies for :required rounds.',
        'game_not_ended' => 'The game is not over yet.',
    ],

    /*
    | Échec technique d’un lancement (spec 50 § 12.5) : la transaction est
    | annulée, le salon reste au lobby, et l’hôte peut recommencer.
    */

    'errors' => [
        'launch_failed' => 'Starting the game failed. Please try again in a moment.',
    ],

    /*
    | Page du salon (spec 50 § 8.1 et § 20.3, lot L50-4) : code et lien de
    | partage, sièges, lancement, annonces du lobby. Les gestes d’hôte
    | (retirer, nommer hôte, quitter) arrivent avec le lot L50-6. Les nombres
    | passent en placeholders, formatés par le client.
    */

    'lobby' => [
        'title' => 'Room',
        'code_label' => 'Code',
        'copy_link' => 'Copy link',
        'link_copied' => 'Link copied',
        'share_hint' => 'Share this link or code with your friends.',
        'share' => 'Share',
        'players' => 'Players (:count of :capacity)',
        'host_badge' => 'Host',
        'you' => 'You',
        'seat' => [
            'disconnected' => 'Disconnected',
            'left' => 'Left',
            'kicked' => 'Removed',
        ],
        'host_changed' => ':nickname is now the host.',
        'you_are_host' => 'You are the host: you set up and start the game.',
        'read_only' => 'Only the host can change the settings.',
        'settings_updated' => 'The host changed the settings.',
        'waiting_for_host' => 'Waiting for the host to start.',
        'waiting_next_game' => 'A game is in progress: you will play the next one.',
        'launch' => 'Start game',
        'launching' => 'Starting…',
        'need_players' => 'At least :min connected players are needed.',
    ],

    /*
    | Vivier au lobby (spec 50 § 9.2 et § 20.3) : compteur et blocage, rendus
    | pour tous à partir du rapport en données ; une cause par cas de
    | `PoolFault`, un remède par cas de `PoolRemedyKind`, construits côté
    | client par une table. `:value` n’existe que pour les deux remèdes qui
    | proposent une valeur.
    */

    'pool' => [
        'counter' => 'Playable movies: :playable for :required rounds.',
        'blocked' => 'Cannot start: not enough playable movies with these settings.',
        'no_remedy' => 'The catalogue does not hold enough movies for a game yet.',
        'cause' => [
            'themeKeys' => 'The chosen themes narrow the choice too much.',
            'framesPerRound' => 'Too few movies have enough frames for this number of frames per round.',
            'roundsCount' => 'There are more rounds than playable movies.',
            'noRepeatMovies' => 'This room has already played most of the available movies.',
        ],
        'themes_pruned' => 'A chosen theme is no longer offered: it no longer filters anything.',
        'remedy' => [
            'open_new_room' => 'Open a new room (:count playable movies with these settings)',
            'disable_no_repeat' => 'Allow movies already played (:count playable movies)',
            'clear_themes' => 'Clear the themes (:count playable movies)',
            'lower_frames_per_round' => 'Switch to :value frames per round (:count playable movies)',
            'reduce_rounds_count' => 'Play :value rounds (:count playable movies)',
        ],
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
