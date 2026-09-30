<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Interrupteurs de compte — spec 40 § 8.2
    |--------------------------------------------------------------------------
    |
    | Section lue EXCLUSIVEMENT par `App\Support\Identity\AccountSwitches`.
    |
    | Seules les valeurs `true` et `false`, que `env()` convertit en booléens,
    | sont des déclarations. Toute autre valeur — vide comprise, et c'est ce
    | que livre `.env.example` — vaut « non déclarée » et applique une LISTE
    | BLANCHE : ouvert en `local` et `testing`, fermé partout ailleurs
    | (`production`, `staging`, `prod` et tout nom imprévu compris).
    |
    | L'ouverture au jalon 2 se fait par l'environnement de production seul,
    | jamais par une valeur du dépôt : aucune valeur par défaut n'est écrite
    | ici, et aucune ne doit l'être.
    |
    */

    'registration_open' => env('ACCOUNTS_REGISTRATION_OPEN'),

    'passkeys_enabled' => env('ACCOUNTS_PASSKEYS_ENABLED'),

];
