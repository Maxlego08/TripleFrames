<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    | Reverb écoute en boucle locale et n'est jamais exposé directement :
    | nginx publie le seul chemin WebSocket `/app/`, jamais l'API HTTP
    | `/apps/` (spec 60 § 19.5, spec 100 § 10.5). Le repli d'écoute est donc
    | `127.0.0.1` et non `0.0.0.0` : un `.env` de production qui oublierait
    | `REVERB_SERVER_HOST` n'ouvrirait pas le port à l'extérieur.
    |
    | Fichier publié par `vendor:publish --tag=reverb-config` seul, jamais par
    | `reverb:install`, qui appelle `install:broadcasting` (spec 60, L60-1).
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            // Borne du tampon d'une requête HTTP reçue par Reverb, donc de
            // toute diffusion du moteur : au-delà, Reverb ferme en 413 et
            // l'événement est perdu sans resynchronisation (E83-5). Les
            // 10 000 octets du paquet ne portent même pas `game.ended` d'un
            // salon ordinaire ; 512 Kio couvrent, de peu (~495 Ko), le pire
            // cas mesuré aux bornes — `MAX_ROUNDS_COUNT` manches, `roomSeats`
            // sièges trouveurs, titres de 255 caractères hors du plan
            // multilingue de base, échappés en paires de substitution puis
            // une seconde fois par le protocole Pusher — (`EventPayloadTest`) :
            // c'est un plancher. Le tampon ne grandit qu'à la taille reçue, et
            // l'API `/apps/` ne quitte jamais la boucle locale.
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 524_288),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                // Liste séparée par des virgules ; vide ou absente = `*`, pour le
                // poste seulement. En production : `<DOMAINE>` (spec 100 § 10.5,
                // spec 60 § 19.5), sans quoi toutes les origines seraient admises.
                'allowed_origins' => array_values(array_filter(
                    array_map(trim(...), explode(',', (string) env('REVERB_ALLOWED_ORIGINS', '*'))),
                    static fn (string $origin): bool => $origin !== '',
                )) ?: ['*'],
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                // Aucun événement client relayé : le moteur, serveur autoritaire,
                // n'utilise jamais de chuchotement (`client-*`), et un relais entre
                // membres serait un canal de messagerie entre joueurs, chat hors v1.
                // Reverb ne relaie que `all` et `members` ; `none` les refuse tous.
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'none'),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
