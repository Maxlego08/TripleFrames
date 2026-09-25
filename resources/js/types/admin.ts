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

import type { TranslationKey } from '@/types/translations';

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
 * Une ligne de la file de curation (spec 20 § 4.1) : son rang dans la file
 * filtrée, et l'instant de sa dernière retouche — `null` pour un film non
 * entamé.
 */
export type AdminCurationQueueRow = AdminMovieRow & {
    rank: number;
    is_started: boolean;
    touched_at: string | null;
};

/** Un film écarté (spec 20 § 4.2), motif relu tel quel. */
export type AdminSetAsideRow = AdminMovieRow & {
    availability_reason: string | null;
    availability_changed_at: string | null;
};

/** Les deux voies d'entrée du lot pilote (`is_import_exception`). */
export type AdminCurationEntry = 'discover' | 'exception';

/** Les trois motifs d'exception. */
export type AdminExceptionMotive = 'language' | 'vote_count' | 'release_year';

/** Les filtres de la file, miroir de la query string retenue. */
export type AdminCurationFilters = {
    entry: AdminCurationEntry | null;
    motive: AdminExceptionMotive | null;
    content_flag: ContentFlag | null;
};

/** Listes blanches relues de `CurationQueue` : un choix offert est un choix accepté. */
export type AdminCurationOptions = {
    entry: AdminCurationEntry[];
    motive: AdminExceptionMotive[];
    content_flag: ContentFlag[];
};

/** Le reste à curer, par voie d'entrée, filtres ignorés. */
export type AdminCurationTotals = Record<AdminCurationEntry, number>;

/** Les trois états de curation dérivés (`CurationStatus`). */
export type AdminCurationStatus =
    | 'ready_to_publish'
    | 'incomplete'
    | 'set_aside';

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

/**
 * Un alias sert à VALIDER une réponse, jamais à afficher un film. `id`
 * adresse « Retirer » (spec 20 § 9.2) ; `created_by` est le NOM de l'auteur
 * d'un alias curé, nul pour un alias TMDB.
 */
export type AdminMovieAlias = {
    id: number;
    locale: string;
    alias: string;
    origin: ContentOrigin;
    created_by: string | null;
};

/**
 * Une forme acceptée du film, en lecture seule (spec 20 § 9.2) : ce que le
 * jeu compare vraiment à une réponse. `is_ambiguous` ne concerne que les
 * natures dérivées, `prefix` et `subtitle`.
 */
export type AdminAnswerKeyRow = {
    form: string;
    kind: AnswerKeyKind;
    is_ambiguous: boolean;
};

/**
 * Une locale ACTIVÉE et sa couverture de titre (spec 20 § 9.3), lue dans
 * `movie_projection.title_locale_mask` ; `covered` nul quand le masque est
 * périmé ou absent — jamais lu comme valide.
 */
export type AdminTitleLocale = {
    locale: string;
    covered: boolean | null;
};

/** L'identité courte d'un film cité par la fiche d'un autre. */
export type AdminMovieIdentity = {
    id: number;
    title_original: string;
    release_year: number | null;
    availability: ContentAvailability;
};

/**
 * Le groupe « même œuvre » d'un film (spec 20 § 9.4) : libellé interne,
 * jamais montré à un joueur, et ses films, du plus ancien au plus récent.
 */
export type AdminMovieGroup = {
    id: number;
    label: string;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    movies: AdminMovieIdentity[];
};

/**
 * Un candidat exact au regroupement : un film au titre normalisé identique,
 * son groupe éventuel, et le libellé que le regroupement pré-remplirait.
 */
export type AdminGroupCandidate = AdminMovieIdentity & {
    group_label: string | null;
    same_group: boolean;
    default_label: string;
};

/** Ce qu'un texte saisi deviendra : un titre ou un alias (`TextTarget`). */
export type AdminTextTarget = 'title' | 'alias';

/**
 * L'aperçu d'un titre ou d'un alias saisi, servi au rechargement partiel qui
 * ouvre sa confirmation (spec 20 § 9.1, § 9.2). `form` vide : le texte ne
 * contient ni lettre ni chiffre. Pour un alias seulement : `accepted_as`, la
 * nature EXACTE sous laquelle le film accepte déjà la forme (alias
 * redondant) ; `promoted_from`, la forme dérivée d'un titre qu'il rendrait
 * exacte, donc toujours acceptée. `ambiguity` : nul sur un film non publié.
 */
export type AdminTextPreview = {
    target: AdminTextTarget;
    text: string;
    form: string;
    accepted_as: AnswerKeyKind | null;
    promoted_from: { kind: AnswerKeyKind; is_ambiguous: boolean } | null;
    ambiguity: AdminAmbiguityLine[] | null;
};

/**
 * Pourquoi la voie manuelle ne regroupe pas avec le film désigné — miroir de
 * `SetMovieGroup::REFUSAL_*` et de `CatalogController::GROUP_REFUSAL_SAME_GROUP`.
 */
export type AdminGroupRefusal =
    | 'self'
    | 'missing'
    | 'withdrawn'
    | 'both_grouped'
    | 'same_group';

/**
 * La recherche de la voie manuelle du regroupement (spec 20 § 9.4), servie
 * au rechargement partiel qui ouvre la confirmation : le film trouvé, présenté
 * comme un candidat, ou le refus que le geste opposerait. `requested` est le
 * texte cherché.
 */
export type AdminGroupManualLookup = {
    requested: string;
    candidate: AdminGroupCandidate | null;
    refusal: AdminGroupRefusal | null;
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
    /** Les conditions de publication qui manquent (spec 20 § 8.1). */
    publication: AdminPublication;
};

/** Booléens d'affichage seulement : chaque écriture garde sa policy. */
export type AdminBankAbilities = {
    createFrame: boolean;
    publish: boolean;
};

/**
 * Une condition de publication qui manque — miroir des constantes de
 * `App\Actions\Curation\PublishMovie` (spec 20 § 8.1). Chacune a sa clé
 * `admin.movie.publish.{blocker}`, qui nomme la condition sous le bouton
 * inactif ET motive le refus du serveur.
 */
export type AdminPublicationBlocker =
    | 'content_not_clear'
    | 'coverage_missing'
    | 'not_guessable';

/**
 * Les conditions de publication d'un film, lues par `PublishMovie::conditions()`
 * — la même lecture que la garde, rejouée sous verrou. Elles ne servent qu'à
 * nommer la condition manquante : la publication garde sa policy et ses
 * gardes à l'écriture.
 */
export type AdminPublication = {
    /** La prochaine publication serait la première (`movie.published`), sinon une republication. */
    first: boolean;
    /** Vide : le film est publiable. */
    blockers: AdminPublicationBlocker[];
    /** Les niveaux exigés (1, 3, 5) sans image en jeu, croissants. */
    missing_levels: FrameLevel[];
};

/** Nature d'une clé de réponse — miroir de `App\Enums\AnswerKeyKind`. */
export type AnswerKeyKind =
    | 'title_original'
    | 'title_latin'
    | 'title'
    | 'alias'
    | 'prefix'
    | 'subtitle';

/** Un film publié qui porte aussi la forme, et sous quelle nature. */
export type AdminAmbiguityMovie = {
    id: number;
    title_original: string;
    release_year: number | null;
    kind: AnswerKeyKind;
};

/**
 * Une forme que la publication rendra ambiguë (spec 20 § 8.2) : la forme
 * normalisée, sa nature pour CE film, et les films publiés qui la portent.
 */
export type AdminAmbiguityLine = {
    form: string;
    kinds: AnswerKeyKind[];
    movies: AdminAmbiguityMovie[];
};

/**
 * L'aperçu d'ambiguïté, servi au rechargement partiel qui ouvre la
 * confirmation de publication — `AmbiguityReport::toArray()`. `digest`
 * repart avec la publication : un catalogue changé entre-temps la fait
 * refuser, et l'aperçu se réaffiche.
 */
export type AdminPublicationPreview = {
    lines: AdminAmbiguityLine[];
    digest: string;
};

/**
 * Clé d'un libellé ou d'une aide de la grille d'exclusion
 * (`admin.exclusion_grid.v{n}.{slug}.label` et `.help`, spec 20 § 7.1).
 * Le serveur les envoie ; un item sans ses deux feuilles fait échouer
 * `ExclusionGridTest`.
 */
export type ExclusionGridKey = Extract<
    TranslationKey,
    `admin.exclusion_grid.${string}`
>;

/** Un item de la grille applicable à l'image revue, et ses deux clés. */
export type AdminReviewItem = {
    slug: string;
    label_key: ExclusionGridKey;
    help_key: ExclusionGridKey;
};

/**
 * La source déclarée (spec 20 § 7.6) : le chemin du visuel TMDB, ou le
 * timecode d'une capture. Affichée en lecture seule, et confirmée par
 * l'envoi de la revue — jamais un support ni un outil (A7).
 */
export type AdminDeclaredSource = {
    kind: FrameSourceKind;
    reference: string;
};

/**
 * Une image de la file de revue — props de revue du contrat C14-bis § 3,
 * miroir de `AdminCatalogPresenter::reviewFrame()`.
 *
 * `published_hash` est l'empreinte des octets affichés, que l'envoi rend en
 * `reviewed_hash` (back-office seulement) ; `game_url` est l'aperçu du rendu
 * FINAL, jamais celui du recadrage, versionné par cette empreinte : son
 * adresse change avec les octets, et l'`<img>` avec elle.
 */
export type AdminReviewFrame = {
    id: number;
    movie_id: number;
    frame_level: FrameLevel;
    availability: ContentAvailability;
    published_hash: string;
    game_url: string;
    grid_version: number;
    items: AdminReviewItem[];
    declared_source: AdminDeclaredSource;
    /** Slugs en défaut de la revue rejetée qui la juge encore ; vide sinon. */
    failed_items: string[];
};

/** Le film d'un groupe de la file : titre original, année. */
export type AdminReviewMovie = {
    id: number;
    title_original: string;
    title_original_latin: string | null;
    release_year: number | null;
};

/** Les images d'un même film, dans l'ordre de la file (spec 20 § 7.3). */
export type AdminReviewGroup = {
    movie: AdminReviewMovie;
    frames: AdminReviewFrame[];
};

/** Les trois listes de la file — miroir de `App\Support\Curation\ReviewList`. */
export type AdminReviewList = 'to_review' | 'to_rereview' | 'rejected';

export type AdminReviewQueue = Record<AdminReviewList, AdminReviewGroup[]>;

/** Les gestes de la fiche film, pour l'affichage seulement. */
export type AdminMovieAbilities = {
    curate: boolean;
    publish: boolean;
    unpublish: boolean;
    verifyContent: boolean;
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
    /**
     * Vivier catalogue compté en ŒUVRES (contrat C2), une entrée par `N`, dans
     * l'ordre croissant, bornes de `RoomSettingsBounds`.
     */
    pool: { frames_per_round: number; works: number }[];
    coverage: {
        /** Toujours cinq entrées, un niveau par ligne. */
        levels: { level: number; variants: number; movies: number }[];
        variants_total: number;
        covers_publishable: number;
        without_frames: number;
        /** Des COUPLES (film, niveau), et non des films. */
        single_variant_levels: number;
    };
    curation: Record<AdminCurationStatus, number>;
    frames: Record<AdminReviewList | 'failed', number>;
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
    missing_title: string | null;
    curation_status: AdminCurationStatus | null;
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
    missing_title: string[];
    curation_status: AdminCurationStatus[];
    exception: string[];
    sort: string[];
    direction: string[];
};
