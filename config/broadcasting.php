<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option controls the default broadcaster that will be used by the
    | framework when an event needs to be broadcast. You may set this to
    | any of the connections defined in the "connections" array below.
    |
    | Supported: "reverb", "pusher", "ably", "mercure", "redis", "log", "null"
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    /*
    |--------------------------------------------------------------------------
    | Broadcast Connections
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the broadcast connections that will be used
    | to broadcast events to other systems or over WebSockets. Samples of
    | each available type of connection are provided inside this array.
    |
    | Le moteur de partie diffuse par `reverb` (spec 60 § 11.2, contrat C7) ;
    | les tests tournent sur `null` (phpunit.xml). Les connexions `pusher` et
    | `ably` du fichier publié sont RETIRÉES (spec 60 § 19.5, spec 100 § 7.2) :
    | inutilisées, et la connexion `pusher` bâtit un hôte littéral de l'API
    | Pusher que `NoLiteralDomainTest` refuse. Ce fichier est publié par
    | `config:publish broadcasting`, JAMAIS par `install:broadcasting`, qui
    | enregistrerait un second `/broadcasting/auth` sans le middleware du
    | moteur (spec 60, lot L60-1).
    |
    */

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
            // Ce que le NAVIGATEUR vise, lu au runtime par la prop partagée
            // `realtime` (spec 60 § 10.5, contrat C7 § 2.6), jamais figé au
            // build par une variable `VITE_REVERB_*` (A-27) : un artefact
            // construit en CI se promeut sans rebuild. Vide = celui de
            // `window.location` (spec 100 § 10.10). À ne pas confondre avec
            // `options`, publication SERVEUR vers Reverb en boucle locale.
            'client' => [
                'host' => env('REVERB_CLIENT_HOST'),
                'port' => env('REVERB_CLIENT_PORT'),
                'scheme' => env('REVERB_CLIENT_SCHEME'),
            ],
        ],

        'mercure' => [
            'driver' => 'mercure',
            'url' => env('MERCURE_URL'),
            'public_url' => env('MERCURE_PUBLIC_URL'),
            'secret' => env('MERCURE_JWT_SECRET'),
            'encryption_key' => env('MERCURE_ENCRYPTION_KEY'),
            'claims' => [
                'iss' => env('MERCURE_JWT_ISSUER'),
                'client_id' => env('APP_NAME'),
            ],
            'cookie_name' => env('MERCURE_COOKIE_NAME'),
            'subscribe_expiration' => (int) env('MERCURE_SUBSCRIBE_EXPIRATION', 5),
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
