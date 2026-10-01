<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Images de jeu — disque privé, hors du chemin de déploiement
        |--------------------------------------------------------------------------
        |
        | `'serve' => false` : la route native de `FilesystemServiceProvider`
        | n'est PAS enregistrée pour ce disque. Le service passe exclusivement par
        | une route applicative dédiée, qui applique le prédicat de signature en
        | trois parties — `Frame::isServable()`, la garde temporelle du palier et
        | l'appartenance du demandeur à la manche. Un chemin de fichier ne quitte
        | jamais le serveur, et `storage:link` ne lie JAMAIS ce disque.
        |
        | Deux préfixes, deux noms aléatoires indépendants : `game/` (dérivé servi,
        | 1280 px et 150 Ko) et `master/` (source de re-cadrage, jamais servie).
        | Voir `App\Support\Frames\FrameStoragePrefix`.
        |
        | `FRAMES_DISK_ROOT` laissée vide vaut `storage/app/frames`, et c'est un
        | chemin ABSOLU : une racine relative se résoudrait contre le répertoire
        | courant du processus, or celui de php-fpm n'est pas celui d'artisan.
        | En production, la racine se pose hors du répertoire de déploiement.
        |
        */

        'frames' => [
            'driver' => 'local',
            'root' => ((string) env('FRAMES_DISK_ROOT', '')) ?: storage_path('app/frames'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Avatars téléversés — disque privé, servi par une route applicative
        |--------------------------------------------------------------------------
        |
        | Spec 40 § 11.3, D49 du 01/10. `'serve' => false` et aucun `storage:link` :
        | les octets passent par `GET /a/{file}` (`avatar.show`), qui ne sert un
        | fichier que s'il est l'image COURANTE et VISIBLE d'un compte — un
        | masquage s'applique donc aussi à une URL déjà vue. Aucune frame sur ce
        | disque, jamais.
        |
        | `AVATARS_DISK_ROOT` laissée vide vaut `storage/app/avatars`, chemin
        | ABSOLU. En production, la racine se pose hors du répertoire de
        | déploiement, comme celle de `frames`.
        |
        */

        'avatars' => [
            'driver' => 'local',
            'root' => ((string) env('AVATARS_DISK_ROOT', '')) ?: storage_path('app/avatars'),
            'serve' => false,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
