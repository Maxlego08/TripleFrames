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
        'documentation' => 'Documentation',
        'home' => 'Home',
        'log_in' => 'Log in',
        'log_out' => 'Log out',
        'register' => 'Register',
        'repository' => 'Repository',
        'settings' => 'Settings',
    ],

    'language' => [
        'change' => 'Change language',
        'current' => 'Current language: :language',
        'label' => 'Language',
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
