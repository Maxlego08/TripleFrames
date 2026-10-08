<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `common` : navigation, boutons, états génériques
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. Il est joint à TOUTE charge utile
    | `translations` ; tout ce qui n’est ni un écran de jeu, ni un écran de
    | salon, ni un écran de compte, ni une page légale vit ici — et rien
    | d’autre, sous peine d’en faire un fourre-tout expédié à chaque réponse.
    |
    */

    'action' => [
        'back' => 'Back',
        'cancel' => 'Cancel',
        'close' => 'Close',
        'confirm' => 'Confirm',
        'continue' => 'Continue',
        'delete' => 'Delete',
        'remove' => 'Remove',
        'save' => 'Save',
    ],

    'state' => [
        'error' => 'Something went wrong.',
        'loading' => 'Loading…',
        'processing' => 'Processing…',
        'saved' => 'Saved.',
    ],

    'nav' => [
        'admin' => 'Admin',
        'dashboard' => 'Dashboard',
        'home' => 'Home',
        'log_in' => 'Log in',
        'log_out' => 'Log out',
        'menu' => 'Navigation menu',
        'menu_description' => 'Site navigation links.',
        'platform' => 'Platform',
        'register' => 'Register',
        'settings' => 'Settings',
        'skip_to_content' => 'Skip to content',
        'toggle_sidebar' => 'Toggle sidebar',
    ],

    'maintenance' => [
        'banner' => 'A site update is being prepared: no new game can be started for now. Games in progress carry on as usual.',
        'launch_blocked' => 'A site update is being prepared: a game cannot be started for now. Please try again a little later.',
    ],

    'connection' => [
        'offline' => 'You are offline. The game does not wait for you: check your connection.',
        'reconnecting' => 'Connection lost, reconnecting… The game keeps going in the meantime.',
        'restored' => 'Connection restored.',
    ],

    'error' => [
        'back_home' => 'Back to home',
        'retry' => 'Try again',
        'reload' => 'Reload the page',
        'join_game' => 'Join a game',
        'other_account' => 'Sign in with another account',
        'forbidden' => [
            'title' => 'Access denied',
            'description' => 'You are not allowed to open this page.',
        ],
        'not_found' => [
            'title' => 'Scene not found',
            'description' => 'This page does not exist, or no longer does. Check the address, or the room code if you were joining a game.',
        ],
        'page_expired' => [
            'title' => 'Session expired',
            'description' => 'The page expired after a period of inactivity. Please try your last action again.',
        ],
        'too_many_requests' => [
            'title' => 'Too many attempts',
            'description' => 'Too many requests were sent. Wait a moment, then try again.',
        ],
        'server_error' => [
            'title' => 'Cut in the control room',
            'description' => 'An internal error is preventing this page from loading. Please try again in a moment.',
        ],
        'service_unavailable' => [
            'title' => 'Intermission',
            'description' => 'The service is temporarily unavailable, most likely for an update. Come back in a few moments.',
        ],
    ],

    // Sélecteur de langue (spec 90 § 8). `changed` est annoncé par
    // l'annonceur une fois le dictionnaire reçu, donc dans la NOUVELLE langue ;
    // `:language` est le libellé natif de la langue choisie.
    'language' => [
        'change' => 'Change language',
        'changed' => 'Language changed to :language.',
        'current' => 'Current language: :language',
        'label' => 'Language',
    ],

    // Noms des fournisseurs de connexion (spec 40 § 12), noms propres.
    'provider' => [
        'discord' => 'Discord',
        'google' => 'Google',
    ],

    // Avatars (spec 40 § 6.7). `alt` n'est lu que loin d'un pseudo affiché :
    // à côté d'un pseudo, l'image est décorative (I5.9). Un libellé de
    // prédéfini n'est que le nom accessible d'une option du sélecteur ; ses
    // clés sont celles d'`AvatarPresetCatalog`, stables quand le pack change.
    'avatar' => [
        'alt' => [
            'initials' => 'Player initials',
            'upload' => 'Player personal avatar',
            'preset' => 'Player avatar',
            'provider' => 'Player profile photo',
        ],
        'picker' => [
            'label' => 'Choose an avatar',
            'account' => 'My avatar',
            'taken' => 'already chosen in this room',
        ],
        // Report of another seat's uploaded image (spec 40 § 11.6, D49 of
        // 01/10). `:nickname`: the targeted seat's nickname.
        'report' => [
            'action' => 'Report avatar',
            'confirm_title' => 'Report :nickname’s avatar?',
            'confirm_body' => 'Report a shocking or inappropriate image. After two reports, it is hidden until an administrator decides.',
            'sent' => 'Report sent. Thank you.',
            'not_reportable' => 'This avatar cannot be reported.',
        ],
        'preset' => [
            'preset-01' => 'Bear',
            'preset-02' => 'Chick',
            'preset-03' => 'Cow',
            'preset-04' => 'Crocodile',
            'preset-05' => 'Dog',
            'preset-06' => 'Duck',
            'preset-07' => 'Elephant',
            'preset-08' => 'Frog',
            'preset-09' => 'Giraffe',
            'preset-10' => 'Hippo',
            'preset-11' => 'Horse',
            'preset-12' => 'Monkey',
            'preset-13' => 'Moose',
            'preset-14' => 'Owl',
            'preset-15' => 'Panda',
            'preset-16' => 'Parrot',
            'preset-17' => 'Penguin',
            'preset-18' => 'Pig',
            'preset-19' => 'Rabbit',
            'preset-20' => 'Rhino',
            'preset-21' => 'Sloth',
            'preset-22' => 'Walrus',
            'preset-23' => 'Whale',
            'preset-24' => 'Zebra',
        ],
    ],

    // Player (spec 90 § 6.4, on the rule of 40 § 13.3; J2, D66 du 07/10).
    // `masked` (“Player :ordinal”) is written with the masked rendering
    // (nickname-moderation). `report.*`: report of another seat's nickname,
    // same pattern as `avatar.report.*`; `:nickname` is the targeted seat's
    // nickname. `report.sent` is identical in every accepted case.
    // `masked_notice` is only rendered to the masked seat itself.
    'player' => [
        'masked' => 'Player :ordinal',
        'masked_notice' => 'Your nickname was reported and is hidden from the other players. You can keep playing as usual.',
        'report' => [
            'action' => 'Report nickname',
            'confirm_title' => 'Report :nickname’s nickname?',
            'confirm_body' => 'Report a shocking or inappropriate nickname. After two reports, it is hidden until an administrator decides.',
            'sent' => 'Report sent. Thank you.',
            'not_reportable' => 'This nickname cannot be reported.',
        ],
    ],

    // Service closure page (spec 90 § 11.7, `site:close` from 100; J2, D66
    // du 07/10). No placeholder, no promised return date (closure is
    // reversible) and no legal commitment: the links lead to the legal pages
    // and to the report page, which stay open.
    'closure' => [
        'title' => 'TripleFrames is closed',
        'body' => 'The service is closed for now: you can no longer create or join a game, or create an account. Your data has not been erased, and you can still log in to export it or delete your account.',
        'legal_link' => 'Legal notice',
        'report_link' => 'Report content',
    ],

    // Accueil (spec 90 § 4.7) : le jeu en une phrase et ses trois entrées ;
    // « Rejoindre » demande le code ET le pseudo (D55 du 02/10).
    // Aucun placeholder. Aucun texte ne dit la longueur ni l'alphabet d'un
    // code de salon : ils appartiennent à `RoomCode` (spec 50), et le nombre
    // d'images d'une manche est un réglage, jamais une règle (« a few »).
    'home' => [
        'create_prompt' => 'Start your own room.',
        'create_prompt_lead' => 'No code yet?',
        'create_room' => 'Create a game',
        'heading' => 'Three frames. One movie to find.',
        'join_heading' => 'Join the game',
        'join_room' => 'Join the game',
        'play_solo' => 'Play solo',
        'room_code_hide' => 'Hide the code',
        'room_code_help' => 'The code is displayed on the host’s screen.',
        'room_code_invalid' => 'This room code is not valid. Check it and try again.',
        'room_code_label' => 'Game code',
        'room_code_placeholder' => 'ABCD',
        'room_code_show' => 'Show the code',
        'nickname_label' => 'Your nickname',
        'nickname_placeholder' => 'E.g. Marty McFly',
        'tagline' => 'Guess the title before your friends. The earlier you find it, the more points you score.',
    ],

    // Generic description and Open Graph (spec 90 § 11.5, D66 du 07/10),
    // server-rendered by `app.blade.php` through `App\Support\Http\PageMeta`,
    // identical on every page. No placeholder: never a room code, a movie
    // title or a nickname (rule 3), and no frame count (a setting, never a rule).
    'meta' => [
        'description' => 'A movie and cartoon guessing game: a few frames, from the most cryptic to the most obvious, and a title to find before your friends. Private rooms, no account needed.',
        'title' => 'TripleFrames, guess the movie from its frames',
    ],

];
