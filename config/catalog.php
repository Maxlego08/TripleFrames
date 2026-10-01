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
    | `preview_ttl_minutes` (spec 20 § 3.3, § 13.7) : durée de vie d'un aperçu
    | à blanc d'un collage, tenu en cache et lisible par son seul auteur. Au
    | moins 1. L'aperçu est indicatif : l'import réel rejoue toutes les gardes.
    |
    | `paste_max_themes` (§ 3.3, D43 du 01/10) : plafond de la multi-sélection
    | de thèmes d'un collage, appliqués en exception `added` aux films du
    | collage. Pas de variable d'environnement. Bornée à [1, 20] par
    | `pasteMaxThemes()` : vingt identifiants de dix chiffres tiennent dans les
    | 255 caractères de `import_run.added_theme_ids`.
    |
    | `seed_list_path` (§ 3.5) : la liste d'amorçage, fichier VERSIONNÉ au
    | format du collage, relatif à la racine du projet (un chemin absolu est
    | lu tel quel). Le bouton « Importer la liste d'amorçage » en importe les
    | `paste_max_ids` premiers identifiants absents du catalogue, un collage à
    | la fois.
    |
    */

    'import' => [
        'requests_per_second' => 35,
        'deduplication_chunk' => 200,
        'paste_max_ids' => 50,
        'paste_max_themes' => 10,
        'pages_min' => 1,
        'pages_max' => 5,
        'language_choices' => ['fr', 'en', 'ja', 'ko', 'it', 'es', 'de', 'zh', 'ru', 'sv'],
        'preview_ttl_minutes' => 60,
        'seed_list_path' => 'database/data/tmdb-seed-list.txt',
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
    | - `capture_enabled` : voie capture (§ 5.4), OUVERTE par défaut (D38 du
    |   28/09) : vide ou absente = ouverte, seule une valeur explicitement
    |   fausse la ferme — en un geste, sans commit, si la licéité de l'acte
    |   de capture venait à être refusée.
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
    |   minute, posé sur l'ajout d'une image, le re-recadrage et la relance :
    |   chaque geste télécharge un original ou distribue un job Imagick, et un
    |   double clic ne doit pas en lancer deux rafales (§ 13.7, C9 § 2).
    |   Au moins 1.
    | - `rate_limits.curation` : limiteur `admin-curation`, par utilisateur et
    |   par minute, posé sur les gestes de curation qui n'écrivent qu'en base —
    |   changer le niveau d'une image, la dépublier ou l'écarter, puis (lots
    |   suivants) revoir, publier, dépublier un film. Au-dessus du débit de
    |   « Entrée = conforme, publier » (§ 13.7). Au moins 1.
    | - `rate_limits.search` : limiteur `admin-tmdb-search`, par utilisateur
    |   et par minute, posé sur la seule recherche TMDB du back-office
    |   (§ 3.4) — distinct d'`admin-import`, que ne consomment que les
    |   écritures qui ouvrent un balayage. Au moins 1.
    | - `images_cache_minutes` : durée de vie, par identifiant TMDB, de la
    |   liste des visuels d'un film que propose l'éditeur de la banque
    |   (§ 6.2). Une liste un peu ancienne est inoffensive : un chemin de
    |   fichier TMDB reste valide. Seule une réponse de TMDB est retenue,
    |   jamais une panne. Au moins 1.
    | - `poll_seconds` : cadence du rechargement partiel de l'éditeur tant
    |   qu'une image du film est en traitement (§ 6.1) : le curateur n'attend
    |   jamais le job. Au moins 1.
    | - `stale_pending_minutes` : au-delà, une image encore en traitement
    |   fait afficher « le traitement d'arrière-plan ne répond pas »
    |   (§ 13.5) — plus long qu'un import qui tient la file `default`, pour
    |   ne jamais crier au loup. Au moins 1.
    |
    | Mesure du débit (§ 10.1, décision 10) :
    |
    | - `idle_seconds` : la fenêtre d'inactivité du temps actif par film.
    |   Vaut 60, et c'est une égalité vérifiée, pas une borne : « pauses de
    |   plus de 60 s exclues » (décision 10, spec 10 § 3.1). Jamais modifiée
    |   pendant un lot pilote.
    | - `heartbeat_seconds` : la cadence du battement posté par l'éditeur, la
    |   fiche et la revue, STRICTEMENT sous `idle_seconds` — sans quoi aucun
    |   écart entre deux battements ne tiendrait jamais dans la fenêtre.
    | - `rate_limits.heartbeat` : limiteur `admin-heartbeat`, par utilisateur
    |   et par minute, au moins `2 × ⌈60 ÷ heartbeat_seconds⌉` : deux onglets
    |   ouverts sur la même page ne reçoivent jamais de 429.
    |
    | Lot pilote (§ 10.3, § 10.4 ; D10 et D11 du 23/09), valeurs FIXÉES AVANT
    | le lot et jamais modifiées pendant :
    |
    | - `composition.{discover, exception}` : la fenêtre stratifiée, les N
    |   premiers films terminés de chaque voie ; `size` en est la somme, et
    |   c'est une contrainte vérifiée (§ 13.7).
    | - `first_rank.{discover, exception}` : le rang, dans sa voie, du premier
    |   film terminé qui entre dans la fenêtre (1 au défaut). Se décale pour
    |   mesurer un second pilote après une re-livraison, ou pour sauter un film
    |   réel terminé à titre d'essai avant le lot. Aucune colonne ne marque le
    |   pilote (B9).
    | - `disqualify_hours` : au-delà de ce temps actif total (films écartés
    |   compris), l'outil est disqualifié.
    | - `j1_target_films`, `reserve_hours` : le jalon 1 reste à
    |   `j1_target_films` films tant que `p90 × (j1_target_films − size)` tient
    |   dans `reserve_hours` ; sinon il compte `⌊reserve_hours ÷ p90⌋` films.
    | - `volume_cap` : plafond de la cible de volume.
    | - `weekly_curation_hours`, `horizon_weeks` : les heures de curation
    |   déclarées, en heures entières par semaine et en semaines, À DÉCLARER
    |   AVANT LE LOT (étape 55 de `REPRISE.md`). Zéro = non déclaré : le
    |   tableau le dit au lieu d'une cible ou d'un quotient, jamais une division
    |   par zéro.
    |
    */

    'curation' => [
        // Voie capture (spec 20 § 5.4) : OUVERTE par défaut (D38 du 28/09). Seule une
        // valeur explicitement fausse la ferme. `env()` rend `false` pour « false »,
        // `''` pour une ligne vide et `null` pour une variable absente : les deux
        // derniers valent « non réglée », donc ouverte.
        'capture_enabled' => in_array(env('CURATION_CAPTURE_ENABLED'), [null, ''], true)
            || filter_var(env('CURATION_CAPTURE_ENABLED'), FILTER_VALIDATE_BOOLEAN),
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
            'curation' => 60,
            'search' => 30,
            'heartbeat' => 12,
        ],

        'images_cache_minutes' => 1_440,
        'poll_seconds' => 3,
        'stale_pending_minutes' => 10,

        'idle_seconds' => 60,
        'heartbeat_seconds' => 15,

        'pilot' => [
            'composition' => [
                'discover' => 15,
                'exception' => 5,
            ],
            'size' => 20,
            'first_rank' => [
                'discover' => 1,
                'exception' => 1,
            ],
            'disqualify_hours' => 10,
            'j1_target_films' => 60,
            'reserve_hours' => 36,
            'volume_cap' => 500,
            'weekly_curation_hours' => 0,
            'horizon_weeks' => 0,
        ],
    ],

];
