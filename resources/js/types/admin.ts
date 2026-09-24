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
 * Cause d'échec du traitement d'une image : les valeurs de
 * `App\Enums\FrameProcessingFailure`, qui SONT les clés de traduction
 * complètes (spec 20 § 5.6). Une valeur ajoutée côté PHP sans sa feuille
 * dans `lang/fr/admin.php` casse `tsc` au lieu d'afficher une clé brute.
 */
export type FrameProcessingFailure =
    | 'admin.frame.processing_error.source_missing'
    | 'admin.frame.processing_error.source_unreadable'
    | 'admin.frame.processing_error.source_format'
    | 'admin.frame.processing_error.source_animated'
    | 'admin.frame.processing_error.source_too_small'
    | 'admin.frame.processing_error.source_aspect'
    | 'admin.frame.processing_error.crop_invalid'
    | 'admin.frame.processing_error.too_heavy'
    | 'admin.frame.processing_error.resource_limit'
    | 'admin.frame.processing_error.withdrawn'
    | 'admin.frame.processing_error.published'
    | 'admin.frame.processing_error.unexpected';

export type FrameSourceKind = 'tmdb' | 'capture';

/**
 * L'état d'une image tel que l'éditeur de la banque l'affiche (spec 20
 * § 6.1) — miroir de `App\Support\Curation\FrameCurationState`. DÉRIVÉ côté
 * serveur (disponibilité, job, dernière revue sur les octets courants),
 * jamais stocké.
 */
export type FrameCurationState =
    | 'locked'
    | 'set_aside'
    | 'processing'
    | 'failed'
    | 'in_play'
    | 'rejected'
    | 'awaiting_review';

/** Rectangle de recadrage, en pixels de l'espace du master (1920 de large). */
export type AdminCropRect = {
    x: number;
    y: number;
    width: number;
    height: number;
};

/**
 * Une image de l'éditeur de la banque — contrat C9, spec 20 § 5.8, miroir de
 * `AdminCatalogPresenter::bankFrame()`.
 *
 * **Aucun chemin disque**, ni `source_hash`, ni `published_hash`, ni
 * `tmdb_file_path` : le lien d'une image à son visuel TMDB ne sort que par
 * les `used_levels` de ce visuel. `id` n'est adressé qu'au back-office
 * (E10-11), jamais à une surface joueur.
 */
export type AdminMovieFrame = {
    id: number;
    frame_level: FrameLevel;
    availability: ContentAvailability;
    processing_state: FrameProcessingState;
    /** Clé de traduction, jamais un message brut. */
    processing_error: FrameProcessingFailure | null;
    /** « Relancer » n'est offert qu'à un échec rejouable. */
    is_retryable: boolean;
    source_kind: FrameSourceKind;
    crop: AdminCropRect;
    /** Route `admin.catalog.frames.game` ; `null` tant qu'aucun rendu n'existe. */
    game_url: string | null;
    /** Route `admin.catalog.frames.master` ; `null` tant qu'aucun rendu n'existe. */
    master_url: string | null;
    /** En jeu, mais revue sous une version antérieure de la grille. */
    review_outdated: boolean;
    curation_state: FrameCurationState;
    /**
     * Sa dernière revue sur ses octets courants, à la version courante de la
     * grille, est rejetée — quelle que soit sa disponibilité. Seul signal
     * d'une image EN JEU rejetée en re-revue, qui reste en jeu jusqu'à
     * décision (§ 7.3, § 7.5) : `curation_state` la dit `in_play`.
     */
    review_rejected: boolean;
};

/**
 * Une image de la FICHE film, en lecture seule : niveau, disponibilité et
 * état du job, sans identifiant ni URL — la fiche ne s'en sert pas.
 */
export type AdminMovieFrameRow = Pick<
    AdminMovieFrame,
    | 'frame_level'
    | 'availability'
    | 'processing_state'
    | 'processing_error'
    | 'is_retryable'
>;

/**
 * Les plafonds du recadreur (R-07), composés par le contrôleur depuis les
 * accesseurs de `PlatformLimits`, jamais depuis `toArray()`.
 */
export type AdminFrameLimits = {
    frameCropMaxWidthPercent: number;
    frameCropMinWidthPx: number;
    frameUploadMaxKilobytes: number;
};

/**
 * Un visuel TMDB proposé par l'éditeur (spec 20 § 6.2) : les backdrops seuls,
 * sans texte d'abord. `file_path` est la référence que l'ajout poste ;
 * `thumb_url` (`w300`) et `image_url` (`w1280`) pointent le serveur d'images
 * de TMDB, dans le navigateur du curateur seulement.
 */
export type AdminBackdrop = {
    file_path: string;
    width: number;
    height: number;
    language_neutral: boolean;
    thumb_url: string;
    image_url: string;
    /** Motif d'un visuel proposé désactivé — le refus que l'ajout opposerait. */
    refusal: 'admin.validation.frame_source.dimensions' | null;
    /** Niveaux des images, ni retirées ni écartées, tirées de ce visuel. */
    used_levels: FrameLevel[];
};

/**
 * La prop différée `backdrops` : un état, jamais une page d'erreur. `failed`
 * et `rate_limited` se rejouent d'un bouton ; `not_configured` non.
 */
export type AdminBackdropSet = {
    status: 'ready' | 'empty' | 'failed' | 'rate_limited' | 'not_configured';
    items: AdminBackdrop[];
};

/** Une séquence de paliers pour un `N` et un masque (spec 20 § 6.7). */
export type AdminSequence = {
    playable: boolean;
    /** Niveaux de `FrameLevelCoverage::select()`, repli compris, croissants. */
    levels: FrameLevel[];
    usesFallback: boolean;
    /** La variante la plus ancienne de chaque niveau, dans l'ordre des paliers. */
    frames: { level: FrameLevel; game_url: string }[];
};

/** La prévisualisation d'un `N`, sur les masques « en jeu » et « après revue ». */
export type AdminSequencePreview = {
    frames_per_round: number;
    in_play: AdminSequence;
    after_review: AdminSequence;
};

/**
 * L'avertissement de perte de couverture (spec 20 § 8.4), servi au
 * rechargement partiel qui ouvre une confirmation ; `null` quand rien n'est
 * à annoncer. `playable_up_to` nul : plus aucun `N` jouable.
 */
export type AdminUnpublishPreview = {
    frame_id: number;
    playable_up_to: number | null;
};

/** Un niveau de l'indicateur de couverture (spec 20 § 6.6). */
export type AdminLevelCoverage = {
    level: FrameLevel;
    /** Variantes JOUABLES, lues sur `movie_projection`. */
    playable: number;
    processing: number;
    awaiting_review: number;
    rejected: number;
    failed: number;
    /** Cible de la passe 2 : objectif de curation, jamais une condition. */
    target: number;
    /** Niveau 1, 3 ou 5 qui ne tient qu'à une variante jouable. */
    single_variant: boolean;
};

export type AdminBankCoverage = {
    levels: AdminLevelCoverage[];
    covers_publishable: boolean;
    pass: 1 | 2;
    target_reached: boolean;
    /** Film publié qui a perdu sa couverture 1-3-5 : il reste publié. */
    incomplete: boolean;
    playable_up_to: number | null;
};

/** Le film de l'éditeur : identité, disponibilité, drapeau, couverture. */
export type AdminBankMovie = {
    id: number;
    tmdb_id: number | null;
    title_original: string;
    title_original_latin: string | null;
    release_year: number | null;
    availability: ContentAvailability;
    content_flag: ContentFlag;
    import_source: ImportSource;
    coverage: AdminBankCoverage;
    /** Une image est en traitement : l'écran se recharge partiellement. */
    has_pending: boolean;
    /** Une image attend son rendu au-delà du délai configuré. */
    processing_stalled: boolean;
};

/** Booléens d'affichage seulement : chaque écriture garde sa policy. */
export type AdminBankAbilities = {
    createFrame: boolean;
};

/** Les gestes de la fiche film, pour l'affichage seulement. */
export type AdminMovieAbilities = {
    curate: boolean;
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
