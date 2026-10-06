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
    | les gestes d’hôte et le départ (L50-6 : `room.lobby.{kick,transfer,leave}*`),
    | la page du salon expiré (L50-8 : `room.expired.*`), « Rejouer » sur le
    | podium (L50-7b : `room.replay.*`), les retardataires (L50-9 :
    | `room.join.late_join`, l’interrupteur `room.settings.allowLateJoin.*` et
    | le titre `room.lobby.settings_title`), le formulaire Simple, ses
    | presets et ses avertissements (L50-5 : `room.settings.*` de chaque clé
    | éditable ou rapportable, `room.warnings.*`, `room.lobby.{presets_title,
    | changes_title,preset_grayed,preset_unplayable}`), la page d’entrée du
    | solo (L60-15, préfixe rédigé par 60 : `room.solo.*`),
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
        'intro' => 'Pick a nickname: you will set up the game, and your avatar, in the room.',
        'submit' => 'Create room',
    ],

    /*
    | Pseudo d’un siège (aucun avatar à l’entrée, D55 du 02/10), champ commun aux formulaires de création
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
    | `messageKey()` ; `late_join` annonce l'entrée d'un retardataire à la
    | manche suivante (§ 15, L50-9).
    */

    'join' => [
        'title' => 'Join the room',
        'submit' => 'Join',
        'kicked' => 'The host removed you from this room: you cannot come back.',
        'full' => 'This room is full.',
        'in_progress' => 'A game is in progress: you will wait in the room and play the next one.',
        'late_join' => 'A game is in progress: you will join from the next round, with no points yet.',
    ],

    /*
    | Page d’entrée du solo `room/solo` (spec 60 § 16.4, lot L60-15 ; préfixe
    | rédigé par 60, écart (l) du § 22 bis) : choix d’un des quatre presets du
    | site, puis pseudo et avatar du premier siège solo. `choose_preset` et
    | `start` servent aussi la relance depuis `game/solo` (L60-16). Aucune
    | unicité du pseudo en solo : l’aide ne la mentionne pas.
    */

    'solo' => [
        'title' => 'Play solo',
        'intro' => 'Practise on your own on the catalogue: pick a preset and a nickname.',
        'choose_preset' => 'Choose a preset',
        'start' => 'Start training',
        'nickname_hint' => 'Between :min and :max characters.',
    ],

    /*
    | Réglages du salon (spec 50 § 20.1 et § 20.2, lots L50-5 et L50-9) :
    | libellé et aide de chaque champ, `room.settings.<champ>.{label,help}`,
    | pour chaque clé de `SIMPLE_KEYS ∪ ADVANCED_KEYS` — le libellé d’un champ
    | de l’onglet Avancé nomme aussi ce champ dans le rapport de changements
    | (`:attribute`) ; les options de la difficulté de saisie et l’instant des
    | propositions en Normal ; `D` remonté par le client quand `N` augmente ;
    | le rapport de changements, un code par `RoomSettings::CHANGE_*`.
    */

    'settings' => [
        'themeKeys' => [
            'label' => 'Themes',
            'help' => 'A movie counts if it belongs to any chosen theme. No theme: the whole catalogue.',
        ],
        'roundsCount' => [
            'label' => 'Rounds',
            'help' => 'One round, one movie.',
        ],
        'framesPerRound' => [
            'label' => 'Frames per round',
            'help' => 'From the most cryptic to the most obvious: more frames, more tiers.',
        ],
        'roundDuration' => [
            'label' => 'Round duration',
            'help' => 'Split equally between the frames.',
            'raised' => 'Round duration raised to :seconds s, the minimum for this number of frames.',
        ],
        'revealDuration' => [
            'label' => 'Reveal duration',
            'help' => 'Pause between rounds, time to see the answer.',
        ],
        'inputDifficulty' => [
            'label' => 'Answer mode',
            'help' => 'How players answer.',
            'option' => [
                'easy' => 'Easy: choices from the first frame',
                'normal' => 'Normal: free text, then choices on the last frame',
                'expert' => 'Expert: free text only',
            ],
            'choices_at' => 'Choices appear at :seconds s, :percent% of the round.',
        ],
        'capacity' => [
            'label' => 'Seats',
            'help' => 'Maximum number of players, never below the players present.',
        ],
        'allowLateJoin' => [
            'label' => 'Late arrivals',
            'help' => 'Allow joining mid-game, from the next round, with no points yet.',
        ],
        'advanced' => [
            'label' => 'Advanced settings',
            'help' => 'Tier detail, scoring and limits.',
        ],
        'tierDurations' => [
            'label' => 'Tier durations',
            'help' => 'The round lasts the sum of the tiers.',
        ],
        'tierPoints' => [
            'label' => 'Points per tier',
            'help' => 'Points earned when the answer lands in this tier.',
        ],
        'speedBonus' => [
            'label' => 'Speed bonus',
            'help' => 'A bonus that decreases within each tier.',
        ],
        'noRepeatMovies' => [
            'label' => 'No repeated movies',
            'help' => 'Leaves out movies recently played in this room.',
        ],
        'attemptsPerSecond' => [
            'label' => 'Attempts per second',
            'help' => 'Maximum rate of free-text answers.',
        ],
        'attemptsPerRound' => [
            'label' => 'Attempts per round',
            'help' => 'Maximum number of free-text answers in a round.',
        ],
        'maxAnswerLength' => [
            'label' => 'Maximum answer length',
            'help' => 'In characters.',
        ],
        'disconnectGraceSeconds' => [
            'label' => 'Time before “left”',
            'help' => 'Time given to a disconnected player to come back.',
        ],
        'change' => [
            'defaulted' => '“:attribute” was set to its default.',
            'dropped' => '“:attribute” no longer exists and was removed.',
            'clamped' => '“:attribute” was brought back within its limits.',
            'resized' => '“:attribute” was re-split for the new number of frames.',
            'pruned' => '“:attribute”: a theme no longer on the site was removed.',
            'coerced' => '“:attribute” was unreadable and reset to its default.',
            'equalized' => '“:attribute”: tiers were reset to equal durations.',
            'reset' => '“:attribute”: the custom scoring was replaced by the default one.',
            'raised' => '“:attribute” was raised to the number of players present.',
            'overwritten' => '“:attribute”: your advanced setting was replaced.',
        ],
    ],

    /*
    | Avertissements non bloquants (spec 50 § 4.5 et § 20.2, lot L50-5) : un
    | code par `RoomSettings::WARNING_*`, diffusé en données et rendu par
    | chaque client dans sa langue ; `:seconds` reçoit le seuil lu dans
    | `bounds.warningThresholds`.
    */

    'warnings' => [
        'short_reveal' => 'Short reveal: :seconds s are recommended to leave time to read the answer.',
        'long_round' => 'Round longer than :seconds s: a player who finds early will wait a long time.',
        'non_decreasing_points' => 'A later tier is worth as much as or more than an earlier one: waiting may pay off.',
        'all_tiers_zero' => 'No tier is worth any points: a scoreless game, the ranking follows the tie-breakers.',
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
    | Échec technique d’un lancement ou d’un « Rejouer » (spec 50 § 12.5,
    | § 13) : la transaction est annulée, le salon garde son statut — au
    | lobby, ou sur son podium —, et l’hôte peut recommencer.
    */

    'errors' => [
        'launch_failed' => 'Starting the game failed. Please try again in a moment.',
    ],

    /*
    | Page du salon (spec 50 § 8.1 et § 20.3, lot L50-4) : code et lien de
    | partage, sièges, lancement, annonces du lobby ; gestes d’hôte et départ
    | (spec 50 § 11.3 et § 11.4, lot L50-6) : retirer, nommer hôte, quitter,
    | leurs confirmations et leurs refus ; titre de la section des réglages
    | (L50-9) ; presets, leur grisage et le rapport de changements (L50-5).
    | Les nombres passent en placeholders, formatés par le client.
    */

    'lobby' => [
        'title' => 'Room',
        'code_label' => 'Game code',
        'copy_code' => 'Copy',
        'copy_room_code' => 'Copy code',
        'code_copied' => 'Code copied',
        'show_code' => 'Show',
        'hide_code' => 'Hide',
        'copy_link' => 'Copy link',
        'link_copied' => 'Link copied',
        'share_hint' => 'Share this link or code with your friends.',
        'share' => 'Share',
        'players' => 'Players (:count of :capacity)',
        'player_count' => ':count / :capacity players',
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
        'kick' => 'Remove from room',
        'kick_confirm' => 'Remove :nickname? This player won’t be able to come back to this room.',
        'cannot_kick_self' => 'You cannot remove yourself: leave the room instead.',
        'transfer' => 'Make host',
        'transfer_confirm' => 'Hand the host role to :nickname?',
        'transfer_unavailable' => 'This player is not connected.',
        'leave' => 'Leave room',
        'leave_confirm' => 'Leave the room? You can come back with the link.',
        'settings_title' => 'Settings',
        'configure' => 'Configure',
        'screen_waiting' => 'The show is about to begin',
        'presets_title' => 'Presets',
        'changes_title' => 'Settings adjusted',
        'preset_grayed' => 'Not enough movies for this preset: playable with :frames frames per round.',
        'preset_unplayable' => 'Not enough movies for this preset.',
        'avatar_taken' => 'Another player already has this avatar.',
        // Seat avatar picker, in the lobby only (D55 of 02/10).
        'avatar' => [
            'title' => 'Your avatar',
            'hint' => 'You can change it until the game starts.',
            'change' => 'Change avatar',
            'done' => 'Done',
            'apply' => 'Use this avatar',
            'previous' => 'Previous avatar',
            'next' => 'Next avatar',
        ],
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

    /*
    | « Rejouer » (spec 50 § 13 et § 20.3, lot L50-7b) : sur le podium, le
    | geste de l’hôte qui ramène le salon au lobby ; les autres joueurs
    | attendent l’hôte.
    */

    'replay' => [
        'action' => 'Play again',
        'waiting' => 'Waiting for the host to play again.',
    ],

    /*
    | Salon expiré (spec 50 § 16.3, lot L50-8) : la page `game/room-expired`,
    | rendue en 410 par le lien d’un salon archivé. Aucune autre information
    | sur le salon ; deux sorties, un nouveau salon et l’accueil.
    */

    'expired' => [
        'title' => 'Room expired',
        'description' => 'This room was closed after a period of inactivity. Create a new one to play again.',
        'create' => 'Create a room',
        'home' => 'Home',
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
