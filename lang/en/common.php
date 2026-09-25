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

    'appearance' => [
        'dark' => 'Dark',
        'label' => 'Appearance',
        'light' => 'Light',
        'system' => 'System',
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
        'forbidden' => [
            'title' => 'Access denied',
            'description' => 'You are not allowed to open this page.',
        ],
        'not_found' => [
            'title' => 'Page not found',
            'description' => 'This page does not exist, or no longer does. Check the address, or the room code if you were joining a game.',
        ],
        'page_expired' => [
            'title' => 'Page expired',
            'description' => 'The page expired after a period of inactivity. Please try your last action again.',
        ],
        'too_many_requests' => [
            'title' => 'Too many requests',
            'description' => 'Too many requests in a short time. Please wait a moment before trying again.',
        ],
        'server_error' => [
            'title' => 'Server error',
            'description' => 'Something unexpected went wrong on our side. Please try again in a moment.',
        ],
        'service_unavailable' => [
            'title' => 'Service unavailable',
            'description' => 'The site is temporarily unavailable, most likely for an update. Please try again a little later.',
        ],
    ],

    'language' => [
        'change' => 'Change language',
        'current' => 'Current language: :language',
        'label' => 'Language',
    ],

    // Avatars (spec 40 § 6.7). `alt` n'est lu que loin d'un pseudo affiché :
    // à côté d'un pseudo, l'image est décorative (I5.9). Un libellé de
    // prédéfini n'est que le nom accessible d'une option du sélecteur ; ses
    // clés sont celles d'`AvatarPresetCatalog`, stables quand le pack change.
    'avatar' => [
        'alt' => [
            'initials' => 'Player initials',
            'preset' => 'Player avatar',
            'provider' => 'Player profile photo',
        ],
        'picker' => [
            'label' => 'Choose an avatar',
            'taken' => 'already chosen in this room',
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

    'home' => [
        'deploy' => 'Deploy now',
        'description' => 'We suggest starting with the following.',
        'documentation' => 'Read the documentation',
        'intro' => 'Laravel has an incredibly rich ecosystem.',
        'title' => 'Let’s get started',
        'tutorials' => 'Watch video tutorials at Laracasts',
    ],

];
