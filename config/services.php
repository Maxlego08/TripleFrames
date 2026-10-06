<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connexion Discord et Google — spec 40 § 12, D51 du 01/10
    |--------------------------------------------------------------------------
    |
    | Un fournisseur est ACTIF si et seulement si ses deux clés sont posées
    | (`App\Support\Identity\OAuthProviders`). Clés vides dans `.env.example`
    | (CI à zéro secret). `redirect` est RELATIF : Socialite le résout contre
    | `APP_URL`, et aucun nom de domaine n'entre dans le dépôt.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => '/auth/google/callback',
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => '/auth/discord/callback',
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TMDB — import et curation seulement
    |--------------------------------------------------------------------------
    |
    | Aucun appel TMDB pendant une partie (CLAUDE.md § 7, règle 6) : ces valeurs
    | ne servent qu'à la commande d'import et au back-office de curation. En jeu,
    | base locale et images stockées.
    |
    | Le JETON v4 PRIME SUR LA CLÉ v3, et c'est une décision de sécurité : le
    | jeton voyage en en-tête `Authorization: Bearer`, la clé v3 voyage en
    | paramètre d'URL — donc dans les journaux d'accès de tout intermédiaire,
    | dans le `Referer` d'une requête d'image, et dans le message de toute
    | exception Guzzle non filtrée. Les deux restent lues, le jeton l'emporte.
    |
    | Les deux variables sont présentes et VIDES dans `.env.example`, que
    | `composer setup` copie : une valeur vide vaut une valeur absente, l'import
    | se désactive avec un message clair et rien d'autre ne casse — la CI tourne
    | sans clé et le catalogue de démonstration reste jouable.
    |
    | Les URL, délais et tentatives ne sont pas des variables d'environnement :
    | une ligne vide dans `.env.example` rendrait `''` et non `null`, ce qui
    | écraserait silencieusement leur valeur par défaut. Ils se surchargent en
    | test par `Config::set()`.
    |
    */

    'tmdb' => [
        'read_access_token' => env('TMDB_API_READ_ACCESS_TOKEN'),
        'api_key' => env('TMDB_API_KEY'),

        'base_url' => 'https://api.themoviedb.org/3',
        'image_base_url' => 'https://image.tmdb.org/t/p',

        // Langue des libellés d'un appel de détail. Les titres localisés du
        // catalogue viennent d'`append_to_response=translations`, jamais d'un
        // appel par langue : le quota est la ressource rare d'un balayage.
        'language' => 'en-US',

        'timeout' => 10,
        'connect_timeout' => 5,

        // Reprise bornée : seuls 429, 5xx et les pannes de transport sont
        // retentés. Un 401 ne l'est jamais — retenter une clé invalide ne fait
        // que retarder le message utile.
        'retry_times' => 3,
        'retry_base_delay_ms' => 500,
        'max_backoff_ms' => 8_000,

        // Plafond d'obéissance à un en-tête `Retry-After` : au-delà, le
        // balayage se suspend et `import_run` reprend plus tard.
        'max_retry_after_seconds' => 60,
    ],

];
