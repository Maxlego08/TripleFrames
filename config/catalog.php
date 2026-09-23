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

];
