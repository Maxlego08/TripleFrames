/**
 * Le contrat de props du back-office, écrit une seule fois.
 *
 * Miroir EXACT de `App\Support\Admin\AdminCatalogPresenter` : les noms de
 * champs sont en **snake_case**, comme les colonnes. Un `tmdbId` d'un côté et
 * un `tmdb_id` de l'autre, c'est la journée perdue que ce fichier existe pour
 * éviter.
 *
 * Toute date est une **chaîne ISO-8601** (`string | null`), jamais un texte
 * pré-formaté côté serveur : la mise en forme d'une date est un fait
 * d'affichage (règle 4). Seule `released_on` est un jour nu `YYYY-MM-DD`.
 *
 * Ce fichier n'est PAS généré : il se modifie à la main, en face du présentateur.
 */

/** `{ data, meta }`, jamais le tableau `links` d'un paginateur Laravel. */
export type Paginated<T> = {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

export type ContentAvailability =
    | 'draft'
    | 'published'
    | 'unpublished'
    | 'suspended'
    | 'withdrawn';

export type ContentFlag = 'clear' | 'blocked' | 'unrated_pending';

export type ImportSource = 'discover' | 'paste' | 'demo';

export type ImportRunKind = 'discover' | 'paste' | 'resync';

export type ImportRunStatus = 'running' | 'completed' | 'failed';

export type MovieDifficulty =
    | 'very_easy'
    | 'easy'
    | 'medium'
    | 'hard'
    | 'very_hard';

export type ContentOrigin = 'tmdb' | 'curator';

export type ThemeMembershipState = 'added' | 'removed';

export type TmdbTagKind = 'genre' | 'company';

export type CertificationCountry = 'FR' | 'US';

export type FrameProcessingState = 'pending' | 'ready' | 'failed';

export type FrameLevel = 1 | 2 | 3 | 4 | 5;

/** Une ligne de catalogue — les colonnes partagées par les trois listes. */
export type AdminMovieRow = {
    id: number;
    tmdb_id: number | null;
    title_original: string;
    title_original_latin: string | null;
    original_language: string;
    release_year: number | null;
    vote_count: number;
    availability: ContentAvailability;
    content_flag: ContentFlag;
    import_source: ImportSource;
    is_import_exception: boolean;
    exception_for_language: boolean;
    exception_for_vote_count: boolean;
    exception_for_release_year: boolean;
    levels_count: number;
    levels_mask: number;
    variants_total: number;
    created_at: string | null;
};

/**
 * La fiche film. `content_verified_by` et `curated_by` sont des **noms**, pas
 * des identifiants : la fiche les affiche, elle ne s'en sert pas pour appeler
 * quoi que ce soit.
 */
export type AdminMovieDetail = AdminMovieRow & {
    adult: boolean;
    availability_changed_at: string | null;
    availability_reason: string | null;
    first_published_at: string | null;
    content_verified_by: string | null;
    content_verified_at: string | null;
    movie_difficulty: MovieDifficulty | null;
    movie_difficulty_derived: MovieDifficulty | null;
    movie_difficulty_override: MovieDifficulty | null;
    collection_name: string | null;
    group_label: string | null;
    curated_by: string | null;
    curation_active_seconds: number;
    import_run_id: number | null;
    updated_at: string | null;
};

/**
 * La projection d'un film. `covers_publishable` et `playable_at` sont
 * **dérivés** côté serveur, jamais stockés : la couverture se teste par
 * `levels_mask & 21 = 21`, et l'écran n'a pas à refaire l'arithmétique.
 */
export type AdminMovieProjection = {
    levels_mask: number;
    levels_count: number;
    level_1_variants: number;
    level_2_variants: number;
    level_3_variants: number;
    level_4_variants: number;
    level_5_variants: number;
    variants_total: number;
    title_locale_mask: number;
    title_mask_version: number;
    /** `false` ⇒ masque périmé : l'écran le signale, il ne le recalcule pas. */
    title_mask_current: boolean;
    covers_publishable: boolean;
    playable_at: number[];
    recomputed_at: string | null;
};

/** `locale` est une locale de CATALOGUE — `ja`, `zh-Hant` — jamais l'enum à deux cas. */
export type AdminMovieTitle = {
    locale: string;
    title: string;
    origin: ContentOrigin;
    edited_by: string | null;
};

/** Un alias sert à VALIDER une réponse, jamais à afficher un film. */
export type AdminMovieAlias = {
    locale: string;
    alias: string;
    origin: ContentOrigin;
};

export type AdminMovieCertification = {
    country: CertificationCountry;
    certification: string;
    is_restrictive: boolean;
    /** Jour nu `YYYY-MM-DD`, et non un instant. */
    released_on: string | null;
    read_at: string | null;
};

/** Identifiant TMDB **brut** : le schéma ne stocke aucun libellé de genre. */
export type AdminMovieTag = {
    tag_kind: TmdbTagKind;
    tmdb_tag_id: number;
};

export type AdminMovieTheme = {
    key: string;
    label: string;
    is_auto: boolean;
    manual_state: ThemeMembershipState | null;
    is_active: boolean;
};

/**
 * Quatre champs, et **aucun chemin de fichier** : le § 10 est formel, et
 * `frame.id` lui-même est `#[Hidden]`. La fiche est en lecture seule et n'en a
 * pas besoin ; l'éditeur de la spec 20 devra régler la question autrement.
 */
export type AdminMovieFrame = {
    frame_level: FrameLevel;
    availability: ContentAvailability;
    processing_state: FrameProcessingState;
    /** Clé de traduction, jamais un message brut. */
    processing_error: string | null;
};

export type AdminImportRunRow = {
    id: number;
    run_kind: ImportRunKind;
    status: ImportRunStatus;
    /** `null` quand le compte a disparu : la traçabilité n'en dépend pas. */
    actor_name: string | null;
    is_widened: boolean;
    filter_min_vote_count: number | null;
    filter_languages: string | null;
    filter_min_release_year: number | null;
    total_seen: number;
    total_imported: number;
    total_skipped: number;
    total_refused_content: number;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
    /**
     * `status === 'running' && started_at === null`. « En file » n'est PAS une
     * valeur d'enum — `import_run.status` n'en a que trois et le schéma est
     * clos — c'est l'intervalle pendant lequel aucun worker n'a pris le travail.
     */
    is_queued: boolean;
};

export type AdminImportRunDetail = AdminImportRunRow & {
    tmdb_page_cursor: number | null;
    last_request_at: string | null;
};

export type AdminImportDefaults = {
    min_vote_count: number;
    languages: string[];
    /** SUGGESTIONS d'interface, jamais une liste blanche de validation. */
    language_choices: string[];
    min_release_year: number;
    pages_min: number;
    pages_max: number;
    pages_default: number;
    paste_max_ids: number;
};

export type AdminDashboardStats = {
    movies_total: number;
    availability: Record<ContentAvailability, number>;
    content_flag: Record<ContentFlag, number>;
    exceptions: {
        total: number;
        language: number;
        vote_count: number;
        release_year: number;
    };
    /** Toujours quatre entrées, `N` de 2 à 5. */
    pool: { frames_per_round: number; movies: number }[];
    coverage: {
        /** Toujours cinq entrées, un niveau par ligne. */
        levels: { level: number; variants: number; movies: number }[];
        variants_total: number;
        covers_publishable: number;
        without_frames: number;
        /** Des COUPLES (film, niveau), et non des films. */
        single_variant_levels: number;
    };
};

export type AdminCatalogExceptionFilter =
    | 'any'
    | 'language'
    | 'vote_count'
    | 'release_year';

export type AdminCatalogSortDirection = 'asc' | 'desc';

export type AdminCatalogFilters = {
    q: string | null;
    availability: string | null;
    content_flag: string | null;
    import_source: string | null;
    exception: AdminCatalogExceptionFilter | null;
    playable_at: number | null;
    sort: string;
    direction: AdminCatalogSortDirection;
};

/**
 * Les quatre compteurs d'exception, mesurés sur le jeu filtré COURANT privé de
 * la seule facette `exception`. Sémantique écrite noir sur blanc pour qu'aucun
 * écran n'en invente une autre.
 */
export type AdminCatalogFacets = {
    exception_total: number;
    exception_language: number;
    exception_vote_count: number;
    exception_release_year: number;
};

/** Listes blanches relues du FormRequest : un choix offert est un choix accepté. */
export type AdminCatalogOptions = {
    availability: string[];
    content_flag: string[];
    import_source: string[];
    playable_at: number[];
    exception: string[];
    sort: string[];
    direction: string[];
};
