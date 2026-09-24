<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filtre d'import — le filtre de GOÛT, et lui seul
    |--------------------------------------------------------------------------
    |
    | Décision 11 : au moins 500 votes, langue originale dans {fr, en, ja},
    | sortie à partir de 1970. Ces trois valeurs gouvernent **uniquement** le
    | balayage `discover` (§ 9.2). La voie d'exception — collage d'identifiants
    | ou d'URL TMDB — les ignore entièrement et marque le film
    | `is_import_exception` avec son motif : c'est elle qui fait entrer Parasite,
    | Le Labyrinthe de Pan et tout l'âge d'or Disney antérieur à 1970.
    |
    | Elles vivent ici et jamais en littéral dans une migration ni dans un
    | FormRequest (règle 2) : `App\ValueObjects\Catalog\ImportFilter` en est le
    | seul lecteur, et les colonnes `import_run.filter_*` en portent la copie
    | figée qui permet de **rejouer** un balayage.
    |
    | Les filtres de CONTENU — `adult`, FR -18, US NC-17, US X — ne sont PAS
    | ici, et c'est structurel : un réglage de configuration est contournable,
    | et la décision 12 interdit qu'un filtre de contenu le soit par quelque
    | voie que ce soit. Ils vivent en constantes de
    | `App\Support\Catalog\RestrictiveCertifications`.
    |
    */

    'import_filter' => [
        'min_vote_count' => 500,
        'languages' => ['fr', 'en', 'ja'],
        'min_release_year' => 1970,
    ],

    /*
    |--------------------------------------------------------------------------
    | Normalisation des clés de réponse (§ 3.5)
    |--------------------------------------------------------------------------
    |
    | Séparateurs de sous-titre et longueur minimale d'un préfixe. Ces deux
    | réglages vivent en configuration et non en table : une table éditable
    | ferait d'un réglage de normalisation une donnée modifiable sans
    | reprojection, donc un index silencieusement incohérent avec la règle qui
    | l'a produit. Tout changement est un déploiement suivi de
    | `catalog:reproject`.
    |
    | L'ordre des séparateurs n'a aucune importance : le découpage retient
    | toujours la position la plus à gauche, tous séparateurs confondus.
    |
    */

    'subtitle_separators' => [' : ', ': ', ' - ', ' – ', ' — '],

    'min_prefix_length' => 4,

    /*
    |--------------------------------------------------------------------------
    | Quota TMDB — un limiteur de débit en code, pas une table (§ 9.2)
    |--------------------------------------------------------------------------
    |
    | Le schéma ne porte que l'état reprenable du balayage (`tmdb_page_cursor`,
    | `last_request_at`, les quatre compteurs). L'étranglement lui-même est ce
    | réglage, appliqué par `App\Support\Catalog\TmdbQuotaLimiter`.
    |
    | `requests_per_second` est délibérément sous la limite annoncée par TMDB :
    | le client retente déjà les 429, et un balayage qui passe son temps en
    | retrait exponentiel est plus lent qu'un balayage qui ne déclenche jamais
    | le quota.
    |
    | `deduplication_chunk` est la taille de lot du `SELECT id, availability
    | FROM movie WHERE tmdb_id IN (…)` servi par `movie_tmdb_uq` (§ 9.2).
    |
    | Les quatre dernières valeurs bornent les DEUX formulaires du back-office,
    | et elles vivent ici pour la même raison que le filtre de goût : un plafond
    | écrit en littéral dans un FormRequest est un réglage de jeu codé en dur
    | (règle 2). Elles bornent une requête WEB, pas la console — `composer dev`
    | lance `queue:listen --timeout=900`, et un envoi dont le travail dépasse ce
    | budget serait tué au milieu en laissant un balayage `running` sans cause
    | visible. La console, elle, reste libre : `catalog:import-ids --file=` avale
    | la liste d'amorçage de 200 identifiants d'un geste.
    |
    | `language_choices` est une liste de SUGGESTIONS d'interface, jamais une
    | liste blanche : la validation accepte n'importe quel code de deux lettres,
    | et c'est ce qui rend le balayage élargi possible — donc traçable par
    | `import_run.is_widened`, et non interdit.
    |
    */

    'import' => [
        'requests_per_second' => 35,
        'deduplication_chunk' => 200,
        'paste_max_ids' => 50,
        'pages_min' => 1,
        'pages_max' => 5,
        'language_choices' => ['fr', 'en', 'ja', 'ko', 'it', 'es', 'de', 'zh', 'ru', 'sv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Curation — chaîne d'image et bornes du back-office (spec 20 § 13.7)
    |--------------------------------------------------------------------------
    |
    | Aucune de ces valeurs n'est une valeur de jeu : aucune ne vient de
    | `room_settings`, aucune n'atteint une partie. Aucune n'est lue de
    | l'environnement, hormis `capture_enabled` : toute modification passe par
    | un commit, et `tests/Feature/Curation/CurationConfigTest.php` est la
    | garde de leurs bornes croisées.
    |
    | Les constantes de FORMAT (16:9, 1280 × 720, master de 1920, plafond de
    | 150 Ko, quantum de 8 192 octets) ne sont PAS ici : elles vivent dans
    | `App\Support\Frames\FrameGeometry`, jamais surchargeables. Le plancher
    | de recadrage non plus : il vit dans `PlatformLimits` (`config/game.php`).
    |
    | - `capture_enabled` : voie capture fermée au jalon 1 (§ 5.4), faute
    |   d'arbitrage de licéité ; vide ou absente = fermée.
    | - `tmdb_original_max_kilobytes` : l'original TMDB transite par la
    |   mémoire de la requête HTTP qui le télécharge (§ 5.3).
    | - `crop_seconds_max` : un cadre oublié ouvert ne fausse pas la médiane
    |   du débit ; sous 65 535, `frame.crop_seconds` étant un
    |   `unsignedSmallInteger` (spec 10 § 4.1).
    | - `webp.*` : qualité du master de re-recadrage (jamais servi à un
    |   joueur), et descente de qualité du dérivé servi, de `start` à `min` par
    |   pas de `step`, jusqu'à passer sous `FrameGeometry::gameEncodeCeilingBytes()`
    |   (§ 5.5) ; la revue sur le rendu final juge le résultat.
    | - `imagick.*` : `Imagick::setResourceLimit`, posé avant toute lecture.
    |   `memory_mb + map_mb + 128 ≤ 512` : le `MemoryMax` du worker `default`
    |   (spec 100 § 10.4), 128 Mo laissés à PHP. `time_s` sous le `--timeout`
    |   de 120 s du même worker : Imagick échoue avant que le worker ne tue le
    |   job. `width_px` / `height_px` refusent une source absurde avant
    |   décodage ; `area_mpx` laisse un original 4K (8,3 Mpx) en mémoire.
    | - `rate_limits.frame` : limiteur `admin-frame`, par utilisateur et par
    |   minute, posé sur l'ajout d'une image (puis, au lot L20-8, sur le
    |   re-recadrage et la relance) : chaque geste télécharge un original ou
    |   distribue un job Imagick, et un double clic ne doit pas en lancer deux
    |   rafales (§ 13.7, C9 § 2). Au moins 1.
    |
    */

    'curation' => [
        'capture_enabled' => filter_var(env('CURATION_CAPTURE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'tmdb_original_max_kilobytes' => 16_384,
        'crop_seconds_max' => 600,

        'webp' => [
            'master_quality' => 90,
            'game_quality_start' => 85,
            'game_quality_min' => 40,
            'game_quality_step' => 5,
        ],

        'imagick' => [
            'memory_mb' => 128,
            'map_mb' => 192,
            'area_mpx' => 16,
            'width_px' => 8_192,
            'height_px' => 8_192,
            'time_s' => 60,
        ],

        'rate_limits' => [
            'frame' => 30,
        ],
    ],

];
