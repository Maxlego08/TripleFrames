<?php

use App\Settings\PlatformLimits;
use App\Support\Room\RoomRateLimits;

return [

    /*
    |--------------------------------------------------------------------------
    | Plafonds de plateforme — contrat C0, spec 50 § 2.3
    |--------------------------------------------------------------------------
    |
    | Section lue EXCLUSIVEMENT par `App\Settings\PlatformLimits`, qui garde
    | chaque valeur à sa construction : une surcharge hors bornes fait échouer
    | tout accesseur, jamais un seul en silence. Chaque valeur vaut la
    | constante `DEFAULT_*` de la classe, sans `env()` : la constante reste la
    | source unique, et une surcharge se pose par un déploiement, jamais par
    | un hôte. Aucune n'est résolue par compte, par plan ni par siège.
    |
    | Treize clés, liste close. N'y figurent JAMAIS :
    | - `B_max` (`speed_bonus_*`) : règle de score, constante de code, liée à
    |   la version de score (D22 du 23/09) ;
    | - `tier_grace_ms` et `preload_lead_ms` : constantes d'instance figées
    |   sur `game` au lancement, non surchargeables. Une clé posée ici par un
    |   déploiement serait ignorée : seul un changement de leur défaut dans le
    |   code est possible, et il incrémente la version de score.
    |
    */

    'platform' => [
        // Confort.
        'saved_configs_per_user' => PlatformLimits::DEFAULT_SAVED_CONFIGS_PER_USER,
        'room_seats' => PlatformLimits::DEFAULT_ROOM_SEATS,
        'avatar_presets' => PlatformLimits::DEFAULT_AVATAR_PRESETS,
        'history_window_months' => PlatformLimits::DEFAULT_HISTORY_WINDOW_MONTHS,
        'success_rate_min_rounds' => PlatformLimits::DEFAULT_SUCCESS_RATE_MIN_ROUNDS,
        'frame_upload_max_kilobytes' => PlatformLimits::DEFAULT_FRAME_UPLOAD_MAX_KILOBYTES,

        // Tirage et mémoire (valeurs déclarées par la spec 30).
        'draw_substitute_margin' => PlatformLimits::DEFAULT_DRAW_SUBSTITUTE_MARGIN,
        'room_memory_window_days' => PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_DAYS,
        'room_memory_window_rounds' => PlatformLimits::DEFAULT_ROOM_MEMORY_WINDOW_ROUNDS,
        'theme_selector_min_pool' => PlatformLimits::DEFAULT_THEME_SELECTOR_MIN_POOL,

        // Lobby.
        'lobby_broadcast_debounce_ms' => PlatformLimits::DEFAULT_LOBBY_BROADCAST_DEBOUNCE_MS,

        // Curation (valeurs déclarées par la spec 20).
        'frame_crop_max_width_percent' => PlatformLimits::DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT,
        'frame_crop_min_width_px' => PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX,
    ],

    /*
    |--------------------------------------------------------------------------
    | Constantes du moteur — propriété de la spec 60 (contrat C7 § 5, C8)
    |--------------------------------------------------------------------------
    |
    | Section lue EXCLUSIVEMENT par `App\Settings\EngineConstants`, dont chaque
    | valeur vaudra la constante `EngineConstants::DEFAULT_*`, sans `env()`
    | (spec 60 § 19.1). La classe naît avec le lot L60-1, qui pose ici ses
    | clés : les écrire avant elle en ferait des littéraux sans lecteur, donc
    | une seconde source de vérité. La spec 50 ne lit jamais cette section.
    |
    */

    'engine' => [],

    /*
    |--------------------------------------------------------------------------
    | Limiteurs d'entrée du salon — spec 50 § 17.3
    |--------------------------------------------------------------------------
    |
    | Section lue EXCLUSIVEMENT par `App\Support\Room\RoomRateLimits`. Gardes
    | anti-abus, ni limites de confort ni valeurs de jeu. Aucune section
    | `operations` : la borne et le TTL du drainage vivent dans
    | `config/deploy.php` (contrat C18-bis).
    |
    */

    'room' => [
        'creates_per_hour' => RoomRateLimits::DEFAULT_CREATES_PER_HOUR,
        'joins_per_minute' => RoomRateLimits::DEFAULT_JOINS_PER_MINUTE,
    ],

];
