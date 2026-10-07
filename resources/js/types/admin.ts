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

import type { UserRole } from '@/types/auth';
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

/** Formulation récurrente proposée comme alias, sans donnée de joueur. */
export type AdminAliasSuggestion = {
    id: number;
    normalized_text: string;
    occurrences: number;
    distinct_rounds: number;
    best_distance: number;
    first_seen_on: string;
    last_seen_on: string;
    movie: {
        id: number;
        title_original: string;
        release_year: number | null;
    };
};

/** `App\Enums\ContentReportReason` (D63 du 07/10). */
export type ContentReportReason =
    | 'wrong_movie'
    | 'title_visible'
    | 'wrong_level'
    | 'poor_quality'
    | 'offensive'
    | 'other';

/** `App\Enums\ContentReportResolution`. */
export type ContentReportResolution =
    | 'movie_unpublished'
    | 'frame_unpublished'
    | 'dismissed'
    | 'already_handled';

/** Filtre de la file des signalements de contenu. */
export type ContentReportFilter = 'open' | 'closed';

/**
 * Une CIBLE de la file des signalements de contenu (spec 20 § 11.6, D63 du
 * 07/10) : l'image, sinon le film, avec ses signalements regroupés. Les
 * gestes l'adressent par `report_id`, son plus ancien signalement.
 */
export type AdminContentReportGroup = {
    report_id: number;
    scope: 'frame' | 'movie';
    reports_count: number;
    first_reported_at: string | null;
    last_reported_at: string | null;
    /** Nombre de signalements par motif, du plus fréquent au moins fréquent. */
    reasons: Partial<Record<ContentReportReason, number>>;
    comments: {
        reason: ContentReportReason;
        comment: string;
        reported_at: string | null;
    }[];
    /** Nuls pour une cible ouverte. */
    resolution: ContentReportResolution | null;
    resolved_at: string | null;
    movie: {
        id: number;
        title_original: string;
        release_year: number | null;
        availability: ContentAvailability;
    };
    frame: {
        id: number;
        frame_level: FrameLevel;
        availability: ContentAvailability;
        thumbnail_url: string | null;
        /** `CoverageLossPreview::forFrame()` ; nul : rien à annoncer. */
        coverage_warning: AdminUnpublishPreview | null;
    } | null;
    abilities: {
        unpublish_movie: boolean;
        unpublish_frame: boolean;
        dismiss: boolean;
    };
};

export type ThemeMembershipState = 'added' | 'removed';

/** Natures d'un thème — miroir de `App\Enums\ThemeKind`. */
export type ThemeKind =
    | 'genre'
    | 'decade'
    | 'studio'
    | 'saga'
    | 'language'
    | 'difficulty';

/** Les cinq natures qu'un curateur crée ; `difficulty` est livrée seule. */
export type CreatableThemeKind = Exclude<ThemeKind, 'difficulty'>;

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

/** Une ligne des mesures du débit : une voie, ou les deux réunies. */
export type AdminThroughputEntry = AdminCurationEntry | 'total';

/**
 * Les mesures d'une population de films terminés (`ThroughputReport`), en
 * secondes. `null` : aucune valeur à mesurer — aucun film publié, aucune
 * image recadrée.
 */
export type AdminThroughputMeasures = {
    films: number;
    published: number;
    set_aside: number;
    active_seconds_total: number;
    active_seconds_median: number | null;
    active_seconds_p90: number | null;
    crop_frames: number;
    crop_seconds_median: number | null;
    crop_seconds_p90: number | null;
};

/** Un film écarté et son motif libre, tel que le journal l'a gardé. */
export type AdminThroughputSetAside = {
    movie_id: number;
    title_original: string;
    entry: AdminCurationEntry;
    reason: string | null;
    terminated_at: string;
};

/**
 * Les cibles du jalon 1 et du volume à un p90, et leur projection
 * (`PilotVerdict::projection()`). `weeks` nul : heures hebdomadaires non
 * déclarées, ou pas de cible.
 */
export type AdminThroughputProjection = {
    p90_seconds: number | null;
    j1: {
        films: number | null;
        target_kept: boolean | null;
        seconds: number | null;
        weeks: number | null;
    };
    volume: {
        films: number | null;
        seconds: number | null;
        weeks: number | null;
    };
    declared_hours: number;
    weekly_hours_declared: boolean;
};

/** L'avancement d'une sous-fenêtre du pilote. */
export type AdminPilotProgress = {
    terminated: number;
    quota: number;
    first_rank: number;
    full: boolean;
};

/** Le verdict du pilote (`PilotVerdict`), rendu une fois la fenêtre pleine. */
export type AdminPilotVerdict = AdminThroughputProjection & {
    filled_at: string;
    films: number;
    failures: number;
    total_active_seconds: number;
    disqualify_seconds: number;
    disqualified: boolean;
};

/** Le tableau du débit (`ThroughputReport::toArray()`). */
export type AdminThroughputReport = {
    thresholds: {
        composition: Record<AdminCurationEntry, number>;
        size: number;
        disqualify_seconds: number;
        j1_target_films: number;
        remaining_after_pilot: number;
        reserve_seconds: number;
        volume_cap: number;
        weekly_curation_hours: number;
        horizon_weeks: number;
    };
    all: {
        measures: Record<AdminThroughputEntry, AdminThroughputMeasures>;
        set_aside: AdminThroughputSetAside[];
        projection: AdminThroughputProjection;
    };
    pilot: {
        progress: Record<AdminCurationEntry, AdminPilotProgress>;
        full: boolean;
        measures: Record<AdminThroughputEntry, AdminThroughputMeasures>;
        set_aside: AdminThroughputSetAside[];
    };
    verdict: AdminPilotVerdict | null;
};

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
    /** Nom TMDB d'une société (`tmdb_company`) ; toujours nul pour un genre. */
    name: string | null;
};

/**
 * Une appartenance du film à un thème (spec 20 § 9.6, D43 du 01/10) : la
 * règle, l'exception et l'appartenance effective séparées.
 */
export type AdminMovieTheme = {
    theme_id: number;
    key: string;
    label: string;
    kind: ThemeKind;
    is_published: boolean;
    is_auto: boolean;
    manual_state: ThemeMembershipState | null;
    is_active: boolean;
};

/** Un thème du sélecteur « Ajouter un thème » : tous, publiés ou non. */
export type AdminAvailableTheme = {
    id: number;
    key: string;
    label: string;
    kind: ThemeKind;
    is_published: boolean;
};

/**
 * La collection TMDB du film, et la saga qui la désigne : sans elle, la
 * fiche offre « Créer la saga depuis cette collection ».
 */
export type AdminMovieCollection = {
    id: number;
    name: string;
    saga: {
        id: number;
        key: string;
        label: string;
        is_published: boolean;
    } | null;
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
 * et parmi eux ceux auxquels TMDB n'attache aucune langue (D39 du 28/09 : les
 * autres peuvent contenir du texte, ne sont pas proposés et se comptent dans
 * `AdminBackdropSet.excluded`), dans l'ordre de TMDB. `file_path` est la
 * référence que l'ajout poste ; `thumb_url` (`w300`) et `image_url` (`w1280`)
 * pointent le serveur d'images de TMDB, dans le navigateur du curateur
 * seulement.
 */
export type AdminBackdrop = {
    file_path: string;
    width: number;
    height: number;
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
 *
 * `excluded` compte les backdrops que TMDB attache à une langue, écartés de
 * `items` (D39 du 28/09) — 0 sur une panne, où rien n'a été compté. `empty`
 * avec `excluded > 0` : tous les visuels du film sont écartés.
 */
export type AdminBackdropSet = {
    status: 'ready' | 'empty' | 'failed' | 'rate_limited' | 'not_configured';
    excluded: number;
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
 * Un film du lot « Publier les films prêts » (spec 20 § 8.1 bis, D59 du
 * 06/10) : son identité et les formes que sa publication rendra ambiguës, le
 * lot entier compté comme publié — miroir de `ReadyBatch` (`ReadyEntry`).
 */
export type AdminReadyMovie = {
    id: number;
    title_original: string;
    release_year: number | null;
    lines: AdminAmbiguityLine[];
};

/** Un film prêt mis de côté, avec ses conditions manquantes (`SkippedEntry`). */
export type AdminSkippedReadyMovie = {
    id: number;
    title_original: string;
    release_year: number | null;
    blockers: AdminPublicationBlocker[];
};

/** La prop `batch` de l'écran — `ReadyBatch::preview()`. */
export type AdminReadyBatch = {
    movies: AdminReadyMovie[];
    skipped: AdminSkippedReadyMovie[];
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

/**
 * Le lot d'un film à valider en une fois (D42 du 30/09, spec 20 § 7.9) —
 * miroir de `AdminCatalogPresenter::reviewBatch()`. Chaque image
 * `{ id, hash }` repart telle quelle : le serveur refuse tout le lot si la
 * liste ou une empreinte a changé depuis l'affichage.
 */
export type AdminReviewBatch = {
    grid_version: number;
    frames: { id: number; hash: string }[];
};

/** Un lot de la file de revue, pour le groupe de son film. */
export type AdminQueueReviewBatch = AdminReviewBatch & { movie_id: number };

/** Les gestes de la fiche film, pour l'affichage seulement. */
export type AdminMovieAbilities = {
    curate: boolean;
    publish: boolean;
    unpublish: boolean;
    verifyContent: boolean;
    /** Le lien « Historique » vers le journal : administrateur seul. */
    viewJournal: boolean;
    /** « Créer la saga depuis cette collection » (`ThemePolicy::create`). */
    editThemes: boolean;
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
    /** Thèmes choisis au collage (D43 du 01/10) ; `[]` sans sélection. */
    added_themes: AdminImportTheme[];
    /** Couples (film, thème) qui ont reçu l'ajout. */
    total_themes_applied: number;
    /** Couples laissés hors du thème par un retrait de curateur. */
    total_themes_kept_removed: number;
};

/**
 * Un thème proposé au collage, ou appliqué par lui — même forme que le
 * sélecteur de la fiche film.
 */
export type AdminImportTheme = AdminAvailableTheme;

export type AdminImportDefaults = {
    min_vote_count: number;
    languages: string[];
    /** SUGGESTIONS d'interface, jamais une liste blanche de validation. */
    language_choices: string[];
    min_release_year: number;
    pages_min: number;
    pages_max: number;
    pages_default: number;
    /** Tailles de balayage proposées (`catalog.import.pages_choices`, D60 du 06/10). */
    pages_choices: number[];
    paste_max_ids: number;
    /** Plafond de thèmes cochés par collage (`catalog.import.paste_max_themes`). */
    paste_max_themes: number;
    /** Bornes de la recherche TMDB, relues d'`ImportSearchRequest`. */
    search_min_length: number;
    search_max_length: number;
};

/**
 * Le sort d'un identifiant — miroir de `App\Support\Catalog\ImportDecision`,
 * les huit cas, même ceux qu'un aperçu à blanc ne produit jamais.
 */
export type ImportDecision =
    | 'imported'
    | 'resynchronized'
    | 'duplicate'
    | 'skipped_by_filter'
    | 'refused_content'
    | 'refused_withdrawn'
    | 'not_found'
    | 'simulated';

/** Le motif d'un sort, porté par `ImportOutcome::$reasonKey`. */
export type ImportReasonKey = Extract<
    TranslationKey,
    `admin.catalog.import.${'refused' | 'skipped'}.${string}`
>;

/** Les états de l'aperçu à blanc (`PastePreview`). */
export type AdminPastePreviewStatus =
    | 'pending'
    | 'running'
    | 'completed'
    | 'failed';

/** Une ligne de l'aperçu : un identifiant et son sort, en données (règle 4). */
export type AdminPastePreviewRow = {
    tmdb_id: number;
    decision: ImportDecision;
    title_original: string | null;
    release_year: number | null;
    motives: AdminExceptionMotive[];
    is_import_exception: boolean;
    reason_key: ImportReasonKey | null;
    reason_replacements: Record<string, string | number>;
    movie_id: number | null;
    availability: ContentAvailability | null;
};

/**
 * Le dernier aperçu à blanc de l'auteur — miroir de `PastePreview::toProps()`
 * (spec 20 § 3.3), sondé jusqu'à complétude.
 */
export type AdminPastePreview = {
    token: string;
    status: AdminPastePreviewStatus;
    identifiers: number[];
    total: number;
    processed: number;
    rows: AdminPastePreviewRow[];
    error_key:
        | 'admin.import.preview.failed'
        | 'admin.tmdb.error.rate_limited_interactive'
        | 'admin.tmdb.error.not_configured'
        | null;
    expires_at: string;
};

/** L'état d'un lot d'images (`FrameBatchImport`, spec 20 § 5.10, D57 du 05/10). */
export type AdminFrameBatchStatus =
    | 'previewed'
    | 'pending'
    | 'running'
    | 'completed'
    | 'failed';

/** Le sort d'un film du lot, calculé à l'aperçu sur la base seule. */
export type AdminFrameBatchRowStatus = 'ready' | 'missing' | 'locked';

export type AdminFrameBatchRow = {
    tmdb_id: number;
    title: string | null;
    movie_id: number | null;
    status: AdminFrameBatchRowStatus;
    frames: number;
    known: number;
    added: number;
    skipped: number;
    /** Les motifs traduits des images refusées. */
    refused: string[];
    done: boolean;
};

/** Le dernier lot de l'auteur — miroir de `FrameBatchImport::toProps()`. */
export type AdminFrameBatch = {
    token: string;
    status: AdminFrameBatchStatus;
    rows: AdminFrameBatchRow[];
    error_key:
        | 'admin.frame_batch.failed'
        | 'admin.frame_batch.snapshot_failed'
        | null;
    expires_at: string;
};

/** L'état du bouton de la liste d'amorçage (`SeedList::summary()`). */
export type AdminSeedListState = 'empty' | 'busy' | 'done' | 'ready';

export type AdminSeedList = {
    state: AdminSeedListState;
    total: number;
    remaining: number;
    /** Ce que « Prévisualiser le lot suivant » envoie, tel quel. */
    next_batch: number[];
    /** Le collage qui tient le verrou, lié sous le bouton inactif. */
    busy_run_id: number | null;
};

/** Les états de la recherche TMDB (`ImportSearchController`). */
export type AdminTmdbSearchStatus =
    | 'ready'
    | 'empty'
    | 'rate_limited'
    | 'failed'
    | 'not_configured';

/** Un résultat de recherche, marqué contre le catalogue local (§ 3.4). */
export type AdminTmdbSearchItem = {
    tmdb_id: number;
    title: string;
    title_original: string;
    release_year: number | null;
    original_language: string;
    vote_count: number;
    catalog: {
        movie_id: number;
        availability: ContentAvailability;
    } | null;
};

export type AdminTmdbSearchResults = {
    query: string;
    status: AdminTmdbSearchStatus;
    error_key:
        | 'admin.error.tmdb_disabled'
        | 'admin.tmdb.error.rate_limited_interactive'
        | 'admin.import.search.failed'
        | null;
    total_results: number;
    items: AdminTmdbSearchItem[];
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
    /** Les films actifs dans ce thème (spec 20 § 9.6). */
    theme_id: number | null;
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

/**
 * Clé d'un libellé ou d'un guide de l'échelle 1-5 (`admin.level.{n}.label`
 * et `.guide`, spec 20 § 6.5), envoyée par le serveur à la page « premiers
 * pas ».
 */
export type AdminLevelKey = Extract<
    TranslationKey,
    `admin.level.${number}.${'label' | 'guide'}`
>;

/** Clé d'un raccourci de débit décrit par la page « premiers pas » (§ 6.4). */
export type AdminShortcutKey = Extract<
    TranslationKey,
    `admin.shortcuts.${string}`
>;

/** Un niveau de l'échelle et ses deux clés — `GuideController::scale()`. */
export type AdminGuideLevel = {
    level: FrameLevel;
    label_key: AdminLevelKey;
    guide_key: AdminLevelKey;
};

/**
 * Un item de la grille d'exclusion COURANTE, avec les niveaux auxquels il
 * s'applique — `GuideController::grid()`.
 */
export type AdminGuideGridItem = {
    slug: string;
    levels: FrameLevel[];
    label_key: ExclusionGridKey;
    help_key: ExclusionGridKey;
};

export type AdminGuideGrid = {
    version: number;
    items: AdminGuideGridItem[];
};

/**
 * Le plancher de recadrage réellement appliqué (spec 20 § 5.2), en nombres :
 * la page les met en forme — `GuideController::floor()`.
 */
export type AdminGuideFloor = {
    /** Part maximale de la largeur et de la hauteur du visuel, en %. */
    max_width_percent: number;
    /** Part maximale de sa surface qui en découle (`pct² ÷ 100`), en %. */
    max_surface_percent: number;
    /** Largeur minimale du cadre, en pixels du master. */
    min_width_px: number;
};

/*
 * Annuaire des comptes et gestion des accès (spec 20 § 2.8) — miroir exact de
 * `App\Support\Admin\AdminAccountPresenter`. Aucun secret n'y figure, et
 * aucune configuration sauvegardée : elles sont privées, y compris d'un
 * administrateur.
 */

/** Une ligne de compte : l'annuaire, les comptes privilégiés, la recherche. */
export type AdminAccountRow = {
    id: number;
    /** Pseudo du compte (`users.name`), jamais le nom réel. */
    name: string;
    email: string | null;
    /** Adresse présente ET vérifiée. */
    email_verified: boolean;
    /** Nom réel d'un compte privilégié (D12 du 23/09) ; conservé à la rétrogradation. */
    real_name: string | null;
    role: UserRole;
    two_factor_confirmed: boolean;
    last_login_at: string | null;
    created_at: string | null;
    /** Pierre tombale d'une anonymisation (spec 10 § 5.5). */
    anonymized: boolean;
};

/** Ce que l'écran peut proposer sur un compte ; masque un bouton, n'autorise rien. */
export type AdminAccountAbilities = {
    updateRole: boolean;
    updateRealName: boolean;
};

/** Une ligne de compte assortie de ses gestes (écran des accès, recherche). */
export type AdminActionableAccount = AdminAccountRow & {
    abilities: AdminAccountAbilities;
    is_self: boolean;
};

/** La fiche d'un compte — la ligne, plus ce que la fiche affiche. */
export type AdminAccountDetail = AdminAccountRow & {
    email_verified_at: string | null;
    two_factor_confirmed_at: string | null;
    locale: string;
    terms_version: string | null;
    terms_accepted_at: string | null;
    age_confirmed_at: string | null;
    anonymized_at: string | null;
};

/** Les cas du journal qui visent un compte. */
export type AdminAccountActionType =
    | 'role.changed'
    | 'user.real_name_changed'
    | 'avatar.hidden'
    | 'avatar.unhidden'
    | 'avatar.removed';

/** Une ligne du journal visant un compte : l'auteur par son instantané signé. */
export type AdminAccountHistoryLine = {
    id: number;
    action: AdminAccountActionType;
    actor_name: string;
    /** Le compte visé, sur l'écran des accès ; `null` sur la fiche du compte. */
    subject: { id: number; name: string; real_name: string | null } | null;
    role_before: UserRole | null;
    role_after: UserRole | null;
    reason: string | null;
    created_at: string | null;
};

/** Les preuves que l'anonymisation conserve (spec 10 § 5.5). */
export type AdminAccountTraces = {
    frame_reviews: number;
    admin_actions: number;
    import_runs: number;
};

export type AdminAccountState = 'active' | 'anonymized';

export type AdminUserDirectorySort =
    | 'created_at'
    | 'last_login_at'
    | 'name'
    | 'email';

/** Les filtres de l'annuaire, miroir de la query string retenue. */
export type AdminUserDirectoryFilters = {
    q: string | null;
    role: UserRole | null;
    state: AdminAccountState | null;
    sort: AdminUserDirectorySort;
    direction: AdminCatalogSortDirection;
};

/** Listes blanches relues de `UserDirectoryRequest`. */
export type AdminUserDirectoryOptions = {
    role: UserRole[];
    state: AdminAccountState[];
    sort: AdminUserDirectorySort[];
    direction: AdminCatalogSortDirection[];
};

/** Les comptes non anonymisés, par rôle. */
export type AdminUserDirectoryCounts = {
    total: number;
    players: number;
    curators: number;
    admins: number;
};

/** La recherche par adresse exacte de l'écran des accès. */
export type AdminAccessCandidate = {
    email: string;
    account: AdminActionableAccount | null;
};

/**
 * Les cas du journal d'administration — miroir de la liste FERMÉE
 * `App\Enums\AdminActionType` (cinquante et un cas : D41 du 30/09, D42 du
 * 30/09, D43 du 01/10, D46 du 01/10).
 */
export type AdminActionTypeValue =
    | 'role.changed'
    | 'user.real_name_changed'
    | 'movie.published'
    | 'movie.unpublished'
    | 'movie.republished'
    | 'movie.content_verified'
    | 'movie.suspended'
    | 'movie.unsuspended'
    | 'movie.withdrawn'
    | 'frame.unpublished'
    | 'frame.grid_unpublished'
    | 'frame.suspended'
    | 'frame.unsuspended'
    | 'frame.withdrawn'
    | 'avatar.hidden'
    | 'avatar.unhidden'
    | 'avatar.removed'
    | 'nickname.masked'
    | 'nickname.unmasked'
    | 'nickname.banned'
    | 'takedown.decided'
    | 'site.closed'
    | 'site.reopened'
    | 'movie.title_saved'
    | 'movie.title_removed'
    | 'movie.alias_added'
    | 'movie.alias_removed'
    | 'movie.grouped'
    | 'movie.ungrouped'
    | 'movie.theme_set'
    | 'frame.added'
    | 'frame.recropped'
    | 'frame.processing_retried'
    | 'frame.level_changed'
    | 'frame.reviewed'
    | 'movie.frames_reviewed'
    | 'import.discover_started'
    | 'import.paste_started'
    | 'import.seed_list_started'
    | 'import.resumed'
    | 'accounts.directory_viewed'
    | 'accounts.access_viewed'
    | 'user.looked_up'
    | 'user.viewed'
    | 'games.directory_viewed'
    | 'game.viewed'
    | 'players.directory_viewed'
    | 'player.viewed'
    | 'theme.created'
    | 'theme.updated'
    | 'theme.published'
    | 'theme.unpublished'
    | 'content_report.dismissed';

/** Les sujets du journal — miroir de `App\Enums\AdminActionSubject`. */
export type AdminActionSubjectValue =
    | 'movie'
    | 'frame'
    | 'user'
    | 'player'
    | 'takedown_request'
    | 'site'
    | 'import_run'
    | 'accounts'
    | 'theme'
    | 'game'
    | 'games'
    | 'players'
    | 'content_report';

/** Les deux classes de conservation — `App\Enums\AdminActionRetention`. */
export type AdminActionRetentionValue = 'permanent' | 'rolling_12m';

/**
 * Le sujet d'une ligne du journal, ou celui du filtre courant. `label` est le
 * libellé COURANT (titre original d'un film, pseudo d'un compte) ; `movie_id`
 * mène à la fiche du film d'un film ou d'une image.
 */
export type AdminJournalSubject = {
    type: AdminActionSubjectValue;
    id: number | null;
    label: string | null;
    movie_id: number | null;
    /** Faux si un sujet identifié n'existe pas en base. */
    exists: boolean;
};

/** Une ligne de l'écran « Journal » (`AdminJournalPresenter::line()`). */
export type AdminJournalLine = {
    id: number;
    action: AdminActionTypeValue;
    /** Le compte de l'auteur, pour ouvrir sa fiche ; `null` pour `system` et `console`. */
    actor_id: number | null;
    /** Le nom réel SIGNÉ à l'instant du geste. */
    actor_name: string;
    subject: AdminJournalSubject;
    reason: string | null;
    role_before: UserRole | null;
    role_after: UserRole | null;
    reports_count: number | null;
    /** Le complément `admin_action.details`, affiché brut, clé par clé. */
    details: Record<string, unknown> | null;
    retention_class: AdminActionRetentionValue;
    created_at: string | null;
};

/** Les filtres de l'écran, miroir de `AdminJournalRequest::filters()`. */
export type AdminJournalFilters = {
    actor: string | null;
    action: AdminActionTypeValue | null;
    from: string | null;
    to: string | null;
    subject_type: AdminActionSubjectValue | null;
    subject_id: number | null;
    /** L'historique complet d'un film : ses lignes et celles de ses images. */
    movie: number | null;
};

/** Les listes blanches de l'écran, relues du serveur. */
export type AdminJournalOptions = {
    /** Les auteurs identifiés, sous le dernier nom réel signé. */
    actors: { value: string; label: string }[];
    reserved_actors: ('system' | 'console')[];
    actions: AdminActionTypeValue[];
    subject_types: AdminActionSubjectValue[];
    subject_types_with_id: AdminActionSubjectValue[];
};

/** Une valeur de la règle d'un thème, avec son nom résolu s'il existe. */
export type AdminThemeRuleItem = {
    value: string;
    /** Nom de la société (`tmdb_company`) ou de la collection ; nul sinon. */
    name: string | null;
};

/**
 * Un thème de l'écran des thèmes (`AdminThemePresenter::theme()`, spec 20
 * § 9.6). La règle arrive EN DONNÉES (`rule_items`), jamais en phrase
 * composée côté serveur : l'écran compose « Marvel Studios (420) ».
 */
export type AdminTheme = {
    id: number;
    key: string;
    kind: ThemeKind;
    rule_value: string | null;
    /** Identifiants de société d'un thème studio, dans l'ordre de la règle. */
    company_ids: number[];
    /** La collection désignée par un thème de saga. */
    collection_id: number | null;
    rule_items: AdminThemeRuleItem[];
    negated: boolean;
    /** Thème sans règle : il ne contient que ses ajouts manuels. */
    manual: boolean;
    is_published: boolean;
    sort_order: number;
    labels: Partial<Record<string, string | null>>;
    /** Œuvres du thème seul, publié ou non, au `N` par défaut. */
    works: number;
    /** Films actifs dans le thème, tous états de film confondus. */
    active_films: number;
    missing_locales: string[];
    /** Avertissement propre à la publication (`language.anime`, C17). */
    publish_notice: 'live_action_japanese' | null;
    abilities: { update: boolean; publish: boolean };
};

/** Une option de règle : une valeur présente au catalogue. */
export type AdminThemeRuleOption = {
    value: string;
    /** Nom résolu (société, collection) ; nul quand le schéma n'en stocke aucun. */
    name: string | null;
    films: number;
    /** La clé du thème de même nature qui désigne déjà cette valeur. */
    taken_by: string | null;
};

/** Les options de règle d'une nature, servies au rechargement partiel. */
export type AdminThemeRuleOptions = {
    kind: ThemeKind;
    options: AdminThemeRuleOption[];
};

/** Le préremplissage « Créer la saga depuis cette collection ». */
export type AdminThemePrefill = {
    kind: 'saga';
    collection_id: number;
    collection_name: string;
};

export type AdminThemeAbilities = {
    create: boolean;
};

/** Le seuil de publication d'un thème, lu de `RoomSettingsBounds`. */
export type AdminThemePublication = {
    min_works: number;
    frames_per_round: number;
};

/*
 * --- Inspection des parties et des sièges (spec 20 § 12.2, D46 du 01/10) ---
 *
 * Miroir EXACT de `App\Support\Admin\GameInspectionPresenter`. Une manche
 * non divulgable (`disclosed: false`) arrive sans film, sans paliers et sans
 * participants : le serveur ne les a jamais chargés (règle 3).
 */

export type InspectionGameStatus =
    | 'running'
    | 'paused'
    | 'completed'
    | 'interrupted';

export type InspectionGameMode = 'multiplayer' | 'solo';

export type InspectionInputDifficulty = 'easy' | 'normal' | 'expert';

export type InspectionRoundStatus =
    | 'pending'
    | 'running'
    | 'revealing'
    | 'completed'
    | 'cancelled';

export type InspectionInputState =
    | 'open'
    | 'text_exhausted'
    | 'locked'
    | 'qcm_wrong'
    | 'attempts_exhausted'
    | 'revealed'
    | 'skipped';

export type InspectionSeatStatus = 'playing' | 'left' | 'kicked';

export type InspectionConnectionState = 'connected' | 'disconnected' | 'left';

export type InspectionAnswerSource = 'text' | 'choice';

export type InspectionMatchKind =
    | 'title'
    | 'alias'
    | 'prefix'
    | 'subtitle'
    | 'choice';

export type InspectionIncidentReason =
    | 'frame_unavailable'
    | 'no_variant_available'
    | 'movie_withdrawn'
    | 'choices_unavailable';

/** L'identité d'un siège ; `nickname` nul = pseudo effacé à l'archivage. */
export type InspectionPlayer = {
    public_id: string;
    nickname: string | null;
    erased: boolean;
    masked: boolean;
    solo: boolean;
    room_code: string | null;
    user: { id: number; name: string } | null;
    joined_at: string | null;
    last_seen_at: string | null;
};

export type InspectionPlayerRow = InspectionPlayer & { games_count: number };

/** L'appareil grossier d'un siège de visiteur consentant (D62 du 06/10). */
export type InspectionDevice = {
    class: string;
    browser: string | null;
    os: string | null;
};

/** Le visiteur consentant d'un siège et ses autres sièges (D62 du 06/10). */
export type InspectionVisitor = {
    consented_at: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    seats: (InspectionPlayer & { device: InspectionDevice | null })[];
};

export type InspectionGameRow = {
    id: number;
    mode: InspectionGameMode;
    status: InspectionGameStatus;
    input_difficulty: InspectionInputDifficulty;
    frames_per_round: number;
    rounds_count: number;
    rounds_completed: number;
    room_code: string | null;
    participants_count: number;
    started_at: string | null;
    finished_at: string | null;
};

export type InspectionGameDetail = InspectionGameRow & {
    paused_at: string | null;
    terminal: boolean;
    settings: {
        round_duration: number;
        tier_durations: number[];
        tier_points: number[];
        reveal_duration: number;
        speed_bonus: boolean;
        attempts_per_round: number;
        max_answer_length: number;
        capacity: number;
        allow_late_join: boolean;
    };
    versions: { settings: number; scoring: number; validation: number };
};

export type InspectionLeaderboardLine = {
    player: InspectionPlayer;
    status: InspectionSeatStatus;
    first_round_number: number | null;
    played_rounds: number | null;
    correct_count: number | null;
    score: number | null;
    rank: number | null;
};

export type InspectionGuess = {
    received_at: string | null;
    answered_at_ms: number;
    tier_index: number;
    lock_rank: number;
    source: InspectionAnswerSource;
    match_kind: InspectionMatchKind;
    answer_key_normalized: string;
    submitted_normalized: string;
    edit_distance: number;
    prefix_was_ambiguous: boolean;
    tier_points: number;
    bonus_points: number;
    total_points: number;
};

export type InspectionWrongAnswer = {
    source: InspectionAnswerSource;
    submitted_text: string;
    submitted_normalized: string;
    attempt_number: number | null;
    received_at: string | null;
    answered_at_ms: number;
    tier_index: number | null;
};

export type InspectionParticipant = {
    player_id: string | null;
    nickname: string | null;
    input_state: InspectionInputState;
    wrong_attempts: number;
    input_closed_at: string | null;
    guess: InspectionGuess | null;
    wrong_answers: InspectionWrongAnswer[];
};

export type InspectionTier = {
    tier_index: number;
    frame_level: number;
    substitution: InspectionIncidentReason | null;
    starts_at_offset_ms: number;
    duration_ms: number;
    points: number;
    opened_at: string | null;
};

type InspectionRoundBase = {
    sequence_index: number;
    round_number: number | null;
    status: InspectionRoundStatus;
    started_at: string | null;
    finished_at: string | null;
    cancel_reason: InspectionIncidentReason | null;
    disclosed: boolean;
    movie: {
        id: number;
        title_original: string | null;
        titles: Record<string, string>;
    } | null;
    found_count: number | null;
    tiers: InspectionTier[];
};

export type InspectionRound = InspectionRoundBase & {
    participants: InspectionParticipant[];
};

export type InspectionPlayerRound = InspectionRoundBase & {
    participation: InspectionParticipant | null;
};

export type InspectionPlayerGame = {
    game: InspectionGameRow;
    status: InspectionSeatStatus;
    score: number | null;
    rank: number | null;
    correct_count: number | null;
    rounds: InspectionPlayerRound[];
};

export type InspectionGameFilters = {
    state: 'running' | 'ended';
    mode: InspectionGameMode | null;
    room: string | null;
};

export type InspectionPlayerFilters = {
    q: string | null;
    mode: 'room' | 'solo' | null;
};

/*
 * --- Performances et chronologie technique (spec 20 § 12.3, D47 du 01/10) ---
 *
 * Miroir de `App\Support\Perf\PerformanceReport` et de la chronologie de
 * `GameInspectionPresenter`. Durées et retards en millisecondes entières.
 */

export type PerfGroupRow = {
    name: string;
    count: number;
    p50_ms: number | null;
    p95_ms: number | null;
    max_ms: number;
    avg_queries: number;
    avg_query_ms: number;
    max_memory_kb: number;
    errors: number;
    p95_wait_ms: number | null;
};

export type PerfEngineRow = {
    event: string;
    count: number;
    p50_delay_ms: number | null;
    p95_delay_ms: number | null;
    max_delay_ms: number | null;
    p50_duration_ms: number | null;
    p95_duration_ms: number | null;
    avg_queries: number;
};

export type PerfSlowQueryRow = {
    sql: string;
    count: number;
    max_ms: number;
    avg_ms: number;
    last_at: string;
    context: string | null;
};

export type PerfReport = {
    since: string;
    requests: PerfGroupRow[];
    jobs: PerfGroupRow[];
    slow_queries: PerfSlowQueryRow[];
    engine: PerfEngineRow[];
    totals: {
        requests: number;
        request_errors: number;
        jobs: number;
        job_failures: number;
        traced_games: number;
    };
};

export type PerfWindow = '1h' | '24h' | '7d';

export type InspectionTraceLine = {
    event: string;
    sequence_index: number | null;
    tier_index: number | null;
    player_id: string | null;
    theoretical_at: string | null;
    recorded_at: string | null;
    delay_ms: number | null;
    duration_ms: number | null;
    query_count: number | null;
    details: Record<string, string | number | boolean | null>;
};

/*
 * --- Audience (spec 20 § 12.4, D48 du 01/10) ---
 *
 * Miroir de `App\Support\Audience\AudienceReport` : des compteurs, jamais
 * une visite ni un visiteur.
 */

export type AudienceRanked = { name: string; total: number };

export type AudienceDay = {
    day: string;
    visitors: number;
    visits: number;
    pageviews: number;
};

export type AudienceReport = {
    since: string;
    totals: { visitors: number; visits: number; pageviews: number };
    daily: AudienceDay[];
    pages: AudienceRanked[];
    entries: AudienceRanked[];
    exits: AudienceRanked[];
    referrers: AudienceRanked[];
    locales: AudienceRanked[];
    devices: AudienceRanked[];
    live: {
        visitors: number;
        pages: AudienceRanked[];
        open_rooms: number;
        running_games: number;
        running_solo: number;
    };
    funnel: {
        rooms_created: number;
        games_multiplayer: number;
        games_solo: number;
        games_completed: number;
        players_per_game: number | null;
    };
};

export type AudienceWindow = '7d' | '30d' | '90d';

/**
 * Une ligne de l'écran « Avatars » (ligne 45, spec 20 § 12.5, D49 du 01/10),
 * miroir de `AvatarModerationController::row()`. Aucune adresse e-mail : la
 * fiche du compte en est le seul accès.
 */
export type AdminAvatarRow = {
    id: number;
    name: string;
    state: 'visible' | 'hidden' | 'removed';
    /** `admin.avatars.image`, qui sert l'image même masquée ; `null` si retirée. */
    image_url: string | null;
    /** Sièges distincts dans la fenêtre courante de signalements. */
    reports: number;
    last_reported_at: string | null;
};

export type AdminAvatarFilter = 'hidden' | 'reported';
