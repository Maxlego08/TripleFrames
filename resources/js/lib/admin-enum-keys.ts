import type {
    AdminAccountActionType,
    AdminActionRetentionValue,
    AdminActionSubjectValue,
    AdminActionTypeValue,
    AdminAccountState,
    AdminCurationEntry,
    AdminCurationStatus,
    AdminUserDirectorySort,
    CertificationCountry,
    ContentAvailability,
    ContentFlag,
    ContentOrigin,
    FrameProcessingState,
    ImportDecision,
    ImportRunKind,
    ImportSource,
    MovieDifficulty,
    ThemeMembershipState,
    TmdbTagKind,
} from '@/types/admin';
import type { UserRole } from '@/types/auth';
import type { TranslationKey } from '@/types/translations';

/**
 * Chaque valeur d'énumération, et la clé qui la nomme en français.
 *
 * Ces tables existent parce que `t()` est **typé** : une clé composée à
 * l'exécution (`` t(`admin.enum.availability.${value}`) ``) ne compile pas, et
 * c'est exactement la garantie recherchée — ajouter un cas à un enum PHP sans
 * écrire sa ligne ici casse `tsc`, au lieu d'afficher une clé brute au
 * curateur.
 *
 * Elles ne rendent rien : la forme visuelle appartient aux badges, la copie au
 * dictionnaire. Un re-skin ne touche ni l'un ni l'autre.
 */

export const AVAILABILITY_KEYS: Record<ContentAvailability, TranslationKey> = {
    draft: 'admin.enum.availability.draft',
    published: 'admin.enum.availability.published',
    unpublished: 'admin.enum.availability.unpublished',
    suspended: 'admin.enum.availability.suspended',
    withdrawn: 'admin.enum.availability.withdrawn',
};

export const CONTENT_FLAG_KEYS: Record<ContentFlag, TranslationKey> = {
    clear: 'admin.enum.content_flag.clear',
    blocked: 'admin.enum.content_flag.blocked',
    unrated_pending: 'admin.enum.content_flag.unrated_pending',
};

export const IMPORT_SOURCE_KEYS: Record<ImportSource, TranslationKey> = {
    discover: 'admin.enum.import_source.discover',
    paste: 'admin.enum.import_source.paste',
    demo: 'admin.enum.import_source.demo',
};

export const IMPORT_RUN_KIND_KEYS: Record<ImportRunKind, TranslationKey> = {
    discover: 'admin.enum.import_run_kind.discover',
    paste: 'admin.enum.import_run_kind.paste',
    resync: 'admin.enum.import_run_kind.resync',
};

/**
 * Quatre entrées pour trois valeurs de colonne : « en file » n'est PAS une
 * valeur d'enum — `import_run.status` n'en a que trois et le schéma est clos —
 * mais le couple `running` + `started_at = null`, que le serveur projette dans
 * `is_queued`.
 */
export const IMPORT_RUN_STATUS_KEYS: Record<
    'running' | 'queued' | 'completed' | 'failed',
    TranslationKey
> = {
    running: 'admin.enum.import_run_status.running',
    queued: 'admin.enum.import_run_status.queued',
    completed: 'admin.enum.import_run_status.completed',
    failed: 'admin.enum.import_run_status.failed',
};

export const MOVIE_DIFFICULTY_KEYS: Record<MovieDifficulty, TranslationKey> = {
    very_easy: 'admin.enum.movie_difficulty.very_easy',
    easy: 'admin.enum.movie_difficulty.easy',
    medium: 'admin.enum.movie_difficulty.medium',
    hard: 'admin.enum.movie_difficulty.hard',
    very_hard: 'admin.enum.movie_difficulty.very_hard',
};

export const CONTENT_ORIGIN_KEYS: Record<ContentOrigin, TranslationKey> = {
    tmdb: 'admin.enum.content_origin.tmdb',
    curator: 'admin.enum.content_origin.curator',
};

export const THEME_MEMBERSHIP_KEYS: Record<
    ThemeMembershipState,
    TranslationKey
> = {
    added: 'admin.enum.theme_membership.added',
    removed: 'admin.enum.theme_membership.removed',
};

export const TMDB_TAG_KIND_KEYS: Record<TmdbTagKind, TranslationKey> = {
    genre: 'admin.enum.tmdb_tag_kind.genre',
    company: 'admin.enum.tmdb_tag_kind.company',
};

export const CERTIFICATION_COUNTRY_KEYS: Record<
    CertificationCountry,
    TranslationKey
> = {
    FR: 'admin.enum.certification_country.FR',
    US: 'admin.enum.certification_country.US',
};

export const FRAME_PROCESSING_KEYS: Record<
    FrameProcessingState,
    TranslationKey
> = {
    pending: 'admin.enum.frame_processing.pending',
    ready: 'admin.enum.frame_processing.ready',
    failed: 'admin.enum.frame_processing.failed',
};

/**
 * Les locales **d'interface**, et elles seules. Un `movie_title.locale` est
 * une locale de CATALOGUE `string(12)` — `ja`, `zh-Hant` — que ce registre ne
 * connaît pas : la table est donc volontairement partielle, et l'écran retombe
 * sur le code brut, qui reste la vérité.
 */
export const LOCALE_KEYS: Partial<Record<string, TranslationKey>> = {
    fr: 'admin.enum.locale.fr',
    en: 'admin.enum.locale.en',
};

/**
 * Le libellé d'une locale, et le repli qui va avec.
 *
 * Compagnon de {@see LOCALE_KEYS}, et rangé ici plutôt que dans un écran
 * parce que deux écrans l'appellent : la fiche film pour ses tables de titres
 * et d'alias, le formulaire d'import pour ses cases à cocher de langue. Sans
 * cette mise en commun, le même code `fr` s'affichait « Français » d'un côté
 * et « FR » de l'autre.
 *
 * Le repli n'est pas une précaution de style : le registre ne connaît que les
 * langues d'INTERFACE, tandis qu'un `movie_title.locale` est une locale de
 * catalogue `string(12)` — `ja`, `zh-Hant`. Afficher le code brut est plus
 * honnête que de mentir dessus.
 */
export function localeLabel(
    locale: string,
    t: (key: TranslationKey) => string,
): string {
    const key = LOCALE_KEYS[locale];

    return key === undefined ? locale : t(key);
}

/** Les six tris offerts par `CatalogIndexRequest`, et leur libellé de colonne. */
export const CATALOG_SORT_KEYS: Record<string, TranslationKey> = {
    created_at: 'admin.catalog.sort.created_at',
    title_original: 'admin.catalog.sort.title_original',
    release_year: 'admin.catalog.sort.release_year',
    vote_count: 'admin.catalog.sort.vote_count',
    levels_count: 'admin.catalog.sort.levels_count',
    variants_total: 'admin.catalog.sort.variants_total',
};

/** Les quatre valeurs du filtre « entrés par exception » (décision 11). */
export const CATALOG_EXCEPTION_KEYS: Record<string, TranslationKey> = {
    any: 'admin.catalog.filters.exception.any',
    language: 'admin.catalog.filters.exception.language',
    vote_count: 'admin.catalog.filters.exception.vote_count',
    release_year: 'admin.catalog.filters.exception.release_year',
};

/**
 * Le sort d'un identifiant à l'aperçu à blanc (spec 20 § 3.3) — les huit cas
 * de `ImportDecision`, même ceux qu'un aperçu ne produit pas.
 */
export const IMPORT_DECISION_KEYS: Record<ImportDecision, TranslationKey> = {
    simulated: 'admin.import.preview.decision.simulated',
    imported: 'admin.import.preview.decision.imported',
    resynchronized: 'admin.import.preview.decision.resynchronized',
    duplicate: 'admin.import.preview.decision.duplicate',
    skipped_by_filter: 'admin.import.preview.decision.skipped_by_filter',
    refused_content: 'admin.import.preview.decision.refused_content',
    refused_withdrawn: 'admin.import.preview.decision.refused_withdrawn',
    not_found: 'admin.import.preview.decision.not_found',
};

/** Les trois motifs d'entrée par exception, tels que la liste les affiche. */
export const EXCEPTION_MOTIVE_KEYS: Record<
    'language' | 'vote_count' | 'release_year',
    TranslationKey
> = {
    language: 'admin.catalog.exception.motive.language',
    vote_count: 'admin.catalog.exception.motive.vote_count',
    release_year: 'admin.catalog.exception.motive.release_year',
};

/**
 * Les trois états de curation dérivés (`CurationStatus`, spec 20 § 8.6) : le
 * filtre du catalogue et les tuiles du tableau de bord parlent la même
 * langue, et chaque tuile mène à la liste qu'elle annonce.
 */
export const CURATION_STATUS_KEYS: Record<AdminCurationStatus, TranslationKey> =
    {
        ready_to_publish:
            'admin.catalog.filters.curation_status.ready_to_publish',
        incomplete: 'admin.catalog.filters.curation_status.incomplete',
        set_aside: 'admin.catalog.filters.curation_status.set_aside',
    };

/**
 * Le filtre « titres manquants » (spec 20 § 9.3), une option par locale
 * **activée** ; une valeur que la table ignore s'affiche brute.
 */
export const MISSING_TITLE_KEYS: Partial<Record<string, TranslationKey>> = {
    fr: 'admin.catalog.filters.missing_title.fr',
    en: 'admin.catalog.filters.missing_title.en',
};

/** Les deux voies d'entrée de la file de curation (lot pilote, § 10.3). */
export const CURATION_ENTRY_KEYS: Record<AdminCurationEntry, TranslationKey> = {
    discover: 'admin.curation.filters.entry.discover',
    exception: 'admin.curation.filters.entry.exception',
};

/** Les trois rôles (`App\Enums\UserRole`), tels que la gestion des accès les nomme. */
export const USER_ROLE_KEYS: Record<UserRole, TranslationKey> = {
    player: 'admin.enum.user_role.player',
    curator: 'admin.enum.user_role.curator',
    admin: 'admin.enum.user_role.admin',
};

/** Les deux états d'un compte filtrables dans l'annuaire (spec 20 § 2.8). */
export const ACCOUNT_STATE_KEYS: Record<AdminAccountState, TranslationKey> = {
    active: 'admin.users.filters.state.active',
    anonymized: 'admin.users.filters.state.anonymized',
};

/** Les quatre tris de l'annuaire (`UserDirectoryRequest::SORTS`). */
export const USER_DIRECTORY_SORT_KEYS: Record<
    AdminUserDirectorySort,
    TranslationKey
> = {
    created_at: 'admin.users.sort.created_at',
    last_login_at: 'admin.users.sort.last_login_at',
    name: 'admin.users.sort.name',
    email: 'admin.users.sort.email',
};

/**
 * Les fournisseurs d'un compte lié (`App\Enums\OAuthProvider`) ; une valeur
 * que la table ignore s'affiche brute.
 */
export const OAUTH_PROVIDER_KEYS: Partial<Record<string, TranslationKey>> = {
    discord: 'admin.enum.oauth_provider.discord',
    google: 'admin.enum.oauth_provider.google',
};

/**
 * Les cas du journal qui visent un compte : les mêmes feuilles
 * `admin.enum.admin_action.*` que `AdminActionType::labelKey()`.
 */
export const ACCOUNT_ACTION_KEYS: Record<
    AdminAccountActionType,
    TranslationKey
> = {
    'role.changed': 'admin.enum.admin_action.role_changed',
    'user.real_name_changed': 'admin.enum.admin_action.user_real_name_changed',
    'avatar.hidden': 'admin.enum.admin_action.avatar_hidden',
    'avatar.unhidden': 'admin.enum.admin_action.avatar_unhidden',
};

/**
 * Tous les cas du journal d'administration (écran « Journal », D41 du
 * 30/09) : les mêmes feuilles `admin.enum.admin_action.*` que
 * `AdminActionType::labelKey()`, points remplacés par `_`.
 */
export const ADMIN_ACTION_KEYS: Record<AdminActionTypeValue, TranslationKey> = {
    'role.changed': 'admin.enum.admin_action.role_changed',
    'user.real_name_changed': 'admin.enum.admin_action.user_real_name_changed',
    'movie.published': 'admin.enum.admin_action.movie_published',
    'movie.unpublished': 'admin.enum.admin_action.movie_unpublished',
    'movie.republished': 'admin.enum.admin_action.movie_republished',
    'movie.content_verified': 'admin.enum.admin_action.movie_content_verified',
    'movie.suspended': 'admin.enum.admin_action.movie_suspended',
    'movie.unsuspended': 'admin.enum.admin_action.movie_unsuspended',
    'movie.withdrawn': 'admin.enum.admin_action.movie_withdrawn',
    'frame.unpublished': 'admin.enum.admin_action.frame_unpublished',
    'frame.grid_unpublished': 'admin.enum.admin_action.frame_grid_unpublished',
    'frame.suspended': 'admin.enum.admin_action.frame_suspended',
    'frame.unsuspended': 'admin.enum.admin_action.frame_unsuspended',
    'frame.withdrawn': 'admin.enum.admin_action.frame_withdrawn',
    'avatar.hidden': 'admin.enum.admin_action.avatar_hidden',
    'avatar.unhidden': 'admin.enum.admin_action.avatar_unhidden',
    'nickname.masked': 'admin.enum.admin_action.nickname_masked',
    'nickname.unmasked': 'admin.enum.admin_action.nickname_unmasked',
    'nickname.banned': 'admin.enum.admin_action.nickname_banned',
    'takedown.decided': 'admin.enum.admin_action.takedown_decided',
    'site.closed': 'admin.enum.admin_action.site_closed',
    'site.reopened': 'admin.enum.admin_action.site_reopened',
    'movie.title_saved': 'admin.enum.admin_action.movie_title_saved',
    'movie.title_removed': 'admin.enum.admin_action.movie_title_removed',
    'movie.alias_added': 'admin.enum.admin_action.movie_alias_added',
    'movie.alias_removed': 'admin.enum.admin_action.movie_alias_removed',
    'movie.grouped': 'admin.enum.admin_action.movie_grouped',
    'movie.ungrouped': 'admin.enum.admin_action.movie_ungrouped',
    'frame.added': 'admin.enum.admin_action.frame_added',
    'frame.recropped': 'admin.enum.admin_action.frame_recropped',
    'frame.processing_retried':
        'admin.enum.admin_action.frame_processing_retried',
    'frame.level_changed': 'admin.enum.admin_action.frame_level_changed',
    'frame.reviewed': 'admin.enum.admin_action.frame_reviewed',
    'import.discover_started':
        'admin.enum.admin_action.import_discover_started',
    'import.paste_started': 'admin.enum.admin_action.import_paste_started',
    'import.seed_list_started':
        'admin.enum.admin_action.import_seed_list_started',
    'import.resumed': 'admin.enum.admin_action.import_resumed',
    'accounts.directory_viewed':
        'admin.enum.admin_action.accounts_directory_viewed',
    'accounts.access_viewed': 'admin.enum.admin_action.accounts_access_viewed',
    'user.looked_up': 'admin.enum.admin_action.user_looked_up',
    'user.viewed': 'admin.enum.admin_action.user_viewed',
};

/** Les sujets du journal — `AdminActionSubject::labelKey()`. */
export const ADMIN_ACTION_SUBJECT_KEYS: Record<
    AdminActionSubjectValue,
    TranslationKey
> = {
    movie: 'admin.enum.admin_action_subject.movie',
    frame: 'admin.enum.admin_action_subject.frame',
    user: 'admin.enum.admin_action_subject.user',
    player: 'admin.enum.admin_action_subject.player',
    takedown_request: 'admin.enum.admin_action_subject.takedown_request',
    site: 'admin.enum.admin_action_subject.site',
    import_run: 'admin.enum.admin_action_subject.import_run',
    accounts: 'admin.enum.admin_action_subject.accounts',
};

/** Les deux classes de conservation d'une ligne du journal. */
export const ADMIN_ACTION_RETENTION_KEYS: Record<
    AdminActionRetentionValue,
    TranslationKey
> = {
    permanent: 'admin.journal.retention.permanent',
    rolling_12m: 'admin.journal.retention.rolling_12m',
};

/**
 * Les champs du complément `admin_action.details` ; une clé que la table
 * ignore s'affiche brute.
 */
export const JOURNAL_DETAIL_KEYS: Partial<Record<string, TranslationKey>> = {
    locale: 'admin.journal.details.locale',
    before: 'admin.journal.details.before',
    before_origin: 'admin.journal.details.before_origin',
    after: 'admin.journal.details.after',
    title: 'admin.journal.details.title',
    alias_id: 'admin.journal.details.alias_id',
    alias: 'admin.journal.details.alias',
    origin: 'admin.journal.details.origin',
    outcome: 'admin.journal.details.outcome',
    group_id: 'admin.journal.details.group_id',
    label: 'admin.journal.details.label',
    group_label: 'admin.journal.details.group_label',
    with_movie_id: 'admin.journal.details.with_movie_id',
    dissolved: 'admin.journal.details.dissolved',
    source_kind: 'admin.journal.details.source_kind',
    frame_level: 'admin.journal.details.frame_level',
    failure: 'admin.journal.details.failure',
    from: 'admin.journal.details.from',
    to: 'admin.journal.details.to',
    review_id: 'admin.journal.details.review_id',
    decision: 'admin.journal.details.decision',
    grid_version: 'admin.journal.details.grid_version',
    pages: 'admin.journal.details.pages',
    tmdb_ids: 'admin.journal.details.tmdb_ids',
};
