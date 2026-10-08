<?php

namespace App\Enums;

use App\Models\AdminAction;
use App\Support\Admin\AdminJournal;

/**
 * Liste FERMÉE des gestes consignés au journal d'administration : cast de
 * `admin_action.action` (spec 10 § 8.3, contrat C14).
 *
 * **Cinquante-six cas.** Les vingt-deux gestes engageants du jalon 1 — les
 * vingt et un du contrat C14, dont six entrés le 23/09 (`movie.published`,
 * `frame.unpublished`, `frame.grid_unpublished`, `frame.unsuspended`,
 * `site.closed`, `site.reopened`), plus `user.real_name_changed` (28/09,
 * EN20-3) — et dix-neuf cas entrés par **D41 du 30/09**, qui étend le journal
 * à TOUTE écriture du back-office et aux lectures sensibles :
 *
 * - quinze gestes de curation et d'import jusque-là tracés par une seule
 *   colonne d'auteur, ou pas du tout (titres, alias, groupes, ajout, recadrage,
 *   relance, niveau et revue d'une image, lancement et reprise d'un import) ;
 * - quatre LECTURES SENSIBLES ({@see self::isRead()}) — l'annuaire, la fiche
 *   d'un compte, l'écran des accès et sa recherche par adresse —, écrites par
 *   la seule porte {@see AdminJournal::recordRead()}, sans transaction.
 *
 * - un cas entré par **D42 du 30/09** : `movie.frames_reviewed`, la
 *   validation en lot des images d'un film — UNE ligne pour le lot, sujet le
 *   film, quand chaque image garde sa propre preuve `frame_review`.
 *
 * - cinq cas entrés par **D43 du 01/10** (spec 10 § 8.3, spec 20 § 2.7
 *   « Cas des thèmes ») : `theme.created`, `theme.updated`, `theme.published`
 *   et `theme.unpublished`, sujet {@see AdminActionSubject::Theme} — l'écran
 *   des thèmes du back-office, avancé au J1 —, et `movie.theme_set`, sujet le
 *   film, le geste du bloc « Thèmes » de la fiche film.
 *
 * - un cas entré par **D49 du 01/10** (spec 40 § 11.7) : `avatar.removed`,
 *   le retrait d'une image téléversée par l'administrateur, motif obligatoire.
 *
 * - un cas entré par **D63 du 07/10** (spec 10 § 8.3, spec 20 § 11.6) :
 *   `content_report.dismissed`, sujet {@see AdminActionSubject::ContentReport},
 *   la clôture sans suite des signalements ouverts d'une cible, motif
 *   facultatif, le nombre de signalements clos en `details`.
 *
 * - trois cas entrés par **D66 du 07/10** (spec 10 § 8.3, lots L20-24 et
 *   L20-28b) : `movie.resynced`, la resynchronisation TMDB d'un film depuis
 *   l'écran — une ligne par film dont une valeur de la liste close change ;
 *   `import.abandoned`, un balayage suspendu clos à la main, sujet
 *   {@see AdminActionSubject::ImportRun} ; `movie.difficulty_corrected`, la
 *   difficulté corrigée d'un film. Tous à motif facultatif, `details` requis.
 *
 * Tous sans migration de colonne : `action` reste un `string(40)`. `10`
 * possède la liste ; un cas nouveau s'y demande en exigence, jamais par un
 * ajout direct ici.
 *
 * Le jalon de chaque GESTE appartient aux specs qui l'écrivent (`20`, `40`,
 * `100`) : un cas présent ici n'est pas un geste livré. Tous passent par
 * l'écrivain unique {@see AdminJournal} ; un geste s'écrit dans la
 * transaction de l'état que la ligne justifie.
 */
enum AdminActionType: string
{
    case RoleChanged = 'role.changed';

    /**
     * La correction du nom réel d'un compte privilégié à l'écran de gestion
     * des accès (spec 20 § 2.8, EN20-3) : le nom qui signe les preuves ne
     * change jamais sans trace. Geste d'administrateur seulement — la
     * correction par la console n'en écrit aucune.
     */
    case UserRealNameChanged = 'user.real_name_changed';

    case MoviePublished = 'movie.published';

    case MovieUnpublished = 'movie.unpublished';

    case MovieRepublished = 'movie.republished';

    case MovieContentVerified = 'movie.content_verified';

    case MovieSuspended = 'movie.suspended';

    case MovieUnsuspended = 'movie.unsuspended';

    case MovieWithdrawn = 'movie.withdrawn';

    case FrameUnpublished = 'frame.unpublished';

    case FrameGridUnpublished = 'frame.grid_unpublished';

    case FrameSuspended = 'frame.suspended';

    case FrameUnsuspended = 'frame.unsuspended';

    case FrameWithdrawn = 'frame.withdrawn';

    case AvatarHidden = 'avatar.hidden';

    case AvatarUnhidden = 'avatar.unhidden';

    /** Retrait d'une image téléversée par l'admin, motif obligatoire (D49 du 01/10, spec 40 § 11.7). */
    case AvatarRemoved = 'avatar.removed';

    case NicknameMasked = 'nickname.masked';

    case NicknameUnmasked = 'nickname.unmasked';

    case NicknameBanned = 'nickname.banned';

    case TakedownDecided = 'takedown.decided';

    case SiteClosed = 'site.closed';

    case SiteReopened = 'site.reopened';

    // --- D41 du 30/09 : gestes du back-office jusque-là sans ligne ---------

    /** Titre affichable créé ou corrigé dans une locale (`details` : avant, après). */
    case MovieTitleSaved = 'movie.title_saved';

    /** Titre `curator` retiré — suppression physique, `details` en garde le texte. */
    case MovieTitleRemoved = 'movie.title_removed';

    case MovieAliasAdded = 'movie.alias_added';

    /** Alias retiré — suppression physique, `details` en garde le texte. */
    case MovieAliasRemoved = 'movie.alias_removed';

    /** Le film entre dans un groupe « même œuvre », créé ou existant. */
    case MovieGrouped = 'movie.grouped';

    /** Le film sort de son groupe, ou le groupe dissous l'en fait sortir. */
    case MovieUngrouped = 'movie.ungrouped';

    /**
     * L'exception manuelle d'un film pour un thème change (D43 du 01/10) :
     * `details` porte la clé du thème, l'état avant et l'état après
     * (`added` / `removed` / NULL). Un collage avec thèmes n'en écrit pas
     * par film (exception assumée à D41, spec 20 § 2.7).
     */
    case MovieThemeSet = 'movie.theme_set';

    case FrameAdded = 'frame.added';

    /** Rectangle réécrit en place (`details` : avant, après). */
    case FrameRecropped = 'frame.recropped';

    case FrameProcessingRetried = 'frame.processing_retried';

    /** Niveau réécrit en place (`details` : de, vers). */
    case FrameLevelChanged = 'frame.level_changed';

    /**
     * Revue d'image passante ou rejetée. La preuve reste `frame_review`, en
     * ajout seul : la ligne n'en est que l'index dans le journal unifié
     * (`details.review_id`).
     */
    case FrameReviewed = 'frame.reviewed';

    /**
     * Validation en lot des images d'un film en attente de revue (D42 du
     * 30/09) : UNE ligne pour le lot, sujet le film. Chaque image garde sa
     * preuve `frame_review`, seule opposable ; le lot n'écrit AUCUNE ligne
     * `frame.reviewed` (`details` : les images validées, la version de la
     * grille).
     */
    case MovieFramesReviewed = 'movie.frames_reviewed';

    case ImportDiscoverStarted = 'import.discover_started';

    /** Collage manuel ; `details` porte les identifiants collés, stockés nulle part ailleurs. */
    case ImportPasteStarted = 'import.paste_started';

    /** Lot de la liste d'amorçage, que `run_kind = paste` confond avec un collage. */
    case ImportSeedListStarted = 'import.seed_list_started';

    case ImportResumed = 'import.resumed';

    // --- D43 du 01/10 : écran des thèmes ------------------------------------

    /** Thème créé, toute nature ou sans règle (`details` : clé, nature, règle, négation, libellés). */
    case ThemeCreated = 'theme.created';

    /** Règle, négation, libellés ou ordre changés (`details` : avant, après — les seuls champs changés). */
    case ThemeUpdated = 'theme.updated';

    /** Bascule effective vers publié (`details` : nombre d'œuvres relu dans la transaction). */
    case ThemePublished = 'theme.published';

    /** Bascule effective vers non publié (`details` : nombre d'œuvres). */
    case ThemeUnpublished = 'theme.unpublished';

    // --- D41 du 30/09 : lectures sensibles ----------------------------------

    /** L'annuaire des comptes, adresses de tous les comptes comprises. */
    case AccountsDirectoryViewed = 'accounts.directory_viewed';

    /** L'écran des accès, sans recherche ou sur une recherche sans résultat. */
    case AccountsAccessViewed = 'accounts.access_viewed';

    /** L'écran des accès dont la recherche par adresse exacte a trouvé ce compte. */
    case UserLookedUp = 'user.looked_up';

    /** La fiche d'un compte. */
    case UserViewed = 'user.viewed';

    // --- D46 du 01/10 : inspection des parties et des sièges ---------------

    /** La liste des parties, en cours ou terminées. */
    case GamesDirectoryViewed = 'games.directory_viewed';

    /** La fiche d'une partie : participants, réponses justes et fausses. */
    case GameViewed = 'game.viewed';

    /** L'annuaire des sièges, invités compris. */
    case PlayersDirectoryViewed = 'players.directory_viewed';

    /** La fiche d'un siège : ses parties et ses réponses. */
    case PlayerViewed = 'player.viewed';

    // --- D63 du 07/10 : signalements de contenu par les joueurs ------------

    /** Les signalements ouverts d'une cible clos sans dépublication. */
    case ContentReportDismissed = 'content_report.dismissed';

    // --- D66 du 07/10 : resynchronisation, balayage clos, difficulté -------

    /**
     * Resynchronisation TMDB d'un film (spec 20 § 3.7, EL41-3) : une ligne
     * par film dont une valeur de la liste close change (`details` : le
     * balayage `resync`, les champs écrasés, la bascule vers `blocked`).
     */
    case MovieResynced = 'movie.resynced';

    /** Balayage suspendu clos à la main (spec 20 § 3.8, `details` : l'état d'avant). */
    case ImportAbandoned = 'import.abandoned';

    /** Difficulté corrigée d'un film (spec 20 § 9.6, `details` : la correction d'avant et d'après). */
    case MovieDifficultyCorrected = 'movie.difficulty_corrected';

    /** Préfixe des libellés du back-office, un par cas. */
    public const string LABEL_PREFIX = 'admin.enum.admin_action.';

    /**
     * Classe de conservation, écrite à l'insertion depuis l'action elle-même.
     * Permanent : tout geste dont le sujet est un film, une image, une demande
     * de retrait, le site, un balayage d'import, un thème ou l'ensemble des comptes, plus
     * `role.changed`, `user.real_name_changed`, les cinq gestes de masquage et
     * les deux lectures sensibles qui visent un compte (D41 du 30/09 : tout
     * cas nouveau est permanent). Une trace ne peut jamais être plus courte
     * que l'état qu'elle justifie — et aucun cas de la liste ne tombe en
     * `rolling_12m`.
     */
    public function retentionClass(): AdminActionRetention
    {
        $alwaysPermanent = in_array($this, [
            self::RoleChanged,
            self::UserRealNameChanged,
            self::AvatarHidden,
            self::AvatarUnhidden,
            self::AvatarRemoved,
            self::NicknameMasked,
            self::NicknameUnmasked,
            self::NicknameBanned,
            self::UserLookedUp,
            self::UserViewed,
            self::PlayerViewed,
        ], true);

        if ($alwaysPermanent) {
            return AdminActionRetention::Permanent;
        }

        return match ($this->subject()) {
            AdminActionSubject::Movie,
            AdminActionSubject::Frame,
            AdminActionSubject::TakedownRequest,
            AdminActionSubject::Site,
            AdminActionSubject::ImportRun,
            AdminActionSubject::Theme,
            AdminActionSubject::ContentReport,
            AdminActionSubject::Accounts,
            AdminActionSubject::Game,
            AdminActionSubject::Games,
            AdminActionSubject::Players => AdminActionRetention::Permanent,
            AdminActionSubject::User,
            AdminActionSubject::Player => AdminActionRetention::Rolling12m,
        };
    }

    /**
     * Sujet du geste, déductible du préfixe de l'action — et c'est la SEULE
     * source de `admin_action.subject_type` : la garde `creating` de
     * {@see AdminAction} l'écrit depuis ici, jamais depuis l'appelant.
     */
    public function subject(): AdminActionSubject
    {
        return match ($this) {
            self::MoviePublished,
            self::MovieUnpublished,
            self::MovieRepublished,
            self::MovieContentVerified,
            self::MovieSuspended,
            self::MovieUnsuspended,
            self::MovieWithdrawn,
            self::MovieTitleSaved,
            self::MovieTitleRemoved,
            self::MovieAliasAdded,
            self::MovieAliasRemoved,
            self::MovieGrouped,
            self::MovieUngrouped,
            self::MovieThemeSet,
            self::MovieFramesReviewed,
            self::MovieResynced,
            self::MovieDifficultyCorrected => AdminActionSubject::Movie,
            self::FrameUnpublished,
            self::FrameGridUnpublished,
            self::FrameSuspended,
            self::FrameUnsuspended,
            self::FrameWithdrawn,
            self::FrameAdded,
            self::FrameRecropped,
            self::FrameProcessingRetried,
            self::FrameLevelChanged,
            self::FrameReviewed => AdminActionSubject::Frame,
            self::RoleChanged,
            self::UserRealNameChanged,
            self::AvatarHidden,
            self::AvatarUnhidden,
            self::AvatarRemoved,
            self::UserLookedUp,
            self::UserViewed => AdminActionSubject::User,
            self::NicknameMasked,
            self::NicknameUnmasked,
            self::NicknameBanned,
            self::PlayerViewed => AdminActionSubject::Player,
            self::GamesDirectoryViewed => AdminActionSubject::Games,
            self::GameViewed => AdminActionSubject::Game,
            self::PlayersDirectoryViewed => AdminActionSubject::Players,
            self::TakedownDecided => AdminActionSubject::TakedownRequest,
            self::SiteClosed,
            self::SiteReopened => AdminActionSubject::Site,
            self::ImportDiscoverStarted,
            self::ImportPasteStarted,
            self::ImportSeedListStarted,
            self::ImportResumed,
            self::ImportAbandoned => AdminActionSubject::ImportRun,
            self::AccountsDirectoryViewed,
            self::AccountsAccessViewed => AdminActionSubject::Accounts,
            self::ThemeCreated,
            self::ThemeUpdated,
            self::ThemePublished,
            self::ThemeUnpublished => AdminActionSubject::Theme,
            self::ContentReportDismissed => AdminActionSubject::ContentReport,
        };
    }

    /**
     * Vrai pour les quatre LECTURES SENSIBLES (D41 du 30/09) : une consultation
     * d'écran qui montre des données personnelles, et non un geste. Elles
     * n'ont pas de transaction et s'écrivent par la seule porte
     * {@see AdminJournal::recordRead()}, qui refuse tout autre cas — et
     * {@see AdminJournal::record()} les refuse : une lecture ne se fait jamais
     * passer pour un geste, ni l'inverse.
     */
    public function isRead(): bool
    {
        return in_array($this, [
            self::AccountsDirectoryViewed,
            self::AccountsAccessViewed,
            self::UserLookedUp,
            self::UserViewed,
            self::GamesDirectoryViewed,
            self::GameViewed,
            self::PlayersDirectoryViewed,
            self::PlayerViewed,
        ], true);
    }

    /**
     * Vrai pour les cas qui portent `admin_action.details` (D41 du 30/09) —
     * obligatoire pour eux, NULL pour tous les autres. Ce sont les gestes qui
     * détruisent ou écrasent en place l'information qu'ils changent, ou dont
     * l'entrée n'est stockée nulle part ailleurs.
     */
    public function hasDetails(): bool
    {
        return in_array($this, [
            self::MovieTitleSaved,
            self::MovieTitleRemoved,
            self::MovieAliasAdded,
            self::MovieAliasRemoved,
            self::MovieGrouped,
            self::MovieUngrouped,
            self::MovieThemeSet,
            self::FrameAdded,
            self::FrameRecropped,
            self::FrameProcessingRetried,
            self::FrameLevelChanged,
            self::FrameReviewed,
            self::MovieFramesReviewed,
            self::ImportDiscoverStarted,
            self::ImportPasteStarted,
            self::ImportSeedListStarted,
            self::ImportResumed,
            self::ThemeCreated,
            self::ThemeUpdated,
            self::ThemePublished,
            self::ThemeUnpublished,
            self::ContentReportDismissed,
            self::MovieResynced,
            self::ImportAbandoned,
            self::MovieDifficultyCorrected,
        ], true);
    }

    /** Vrai pour les deux seuls gestes déclenchés par un seuil et non par une personne : `actor_id` NULL et `actor_name` = 'system'. */
    public function isAutomatic(): bool
    {
        return $this === self::AvatarHidden || $this === self::NicknameMasked;
    }

    /**
     * Motif obligatoire — rogné non vide —, colonne « Motif » du contrat C14 :
     * les gestes qui engagent le projet vis-à-vis d'un tiers ou qui retirent
     * du jeu ce qu'un curateur avait publié. Facultatif partout ailleurs.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [
            self::MovieUnpublished,
            self::MovieContentVerified,
            self::MovieWithdrawn,
            self::FrameGridUnpublished,
            self::FrameWithdrawn,
            self::AvatarRemoved,
            self::NicknameBanned,
            self::TakedownDecided,
            self::SiteClosed,
        ], true);
    }

    /**
     * Gestes admis depuis la ligne de commande, sous l'acteur réservé
     * {@see AdminAction::CONSOLE_ACTOR} et un `actor_id` NULL : la nomination du
     * premier administrateur, puis la fermeture et la réouverture du site
     * (`100`). Aucun autre geste ne s'écrit sans personne identifiée.
     */
    public function allowsConsoleActor(): bool
    {
        return $this === self::RoleChanged
            || $this === self::SiteClosed
            || $this === self::SiteReopened;
    }

    /**
     * Clé du libellé au back-office — les points remplacés par `_`, parce
     * qu'un point est un séparateur de chemin dans un dictionnaire de langue.
     */
    public function labelKey(): string
    {
        return self::LABEL_PREFIX.str_replace('.', '_', $this->value);
    }
}
