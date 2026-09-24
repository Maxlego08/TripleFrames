<?php

namespace App\Enums;

use App\Models\AdminAction;
use App\Support\Admin\AdminJournal;

/**
 * Liste FERMÉE des gestes engageants consignés au journal d'administration :
 * cast de `admin_action.action` (spec 10 § 8.3, contrat C14).
 *
 * **Vingt et un cas au jalon 1**, dont six entrés le 23/09 (`movie.published`,
 * `frame.unpublished`, `frame.grid_unpublished`, `frame.unsuspended`,
 * `site.closed`, `site.reopened`) sans migration : `action` reste un
 * `string(40)`. `10` possède la liste ; un cas nouveau s'y demande en exigence,
 * jamais par un ajout direct ici.
 *
 * Le jalon de chaque GESTE appartient aux specs qui l'écrivent (`20`, `40`,
 * `100`) : un cas présent ici n'est pas un geste livré. Tous passent par
 * l'écrivain unique {@see AdminJournal}, dans la transaction de l'état que la
 * ligne justifie.
 */
enum AdminActionType: string
{
    case RoleChanged = 'role.changed';

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

    case NicknameMasked = 'nickname.masked';

    case NicknameUnmasked = 'nickname.unmasked';

    case NicknameBanned = 'nickname.banned';

    case TakedownDecided = 'takedown.decided';

    case SiteClosed = 'site.closed';

    case SiteReopened = 'site.reopened';

    /** Préfixe des libellés du back-office, un par cas. */
    public const string LABEL_PREFIX = 'admin.enum.admin_action.';

    /**
     * Classe de conservation, écrite à l'insertion depuis l'action elle-même.
     * Permanent : tout geste dont le sujet est un film, une image, une demande
     * de retrait ou le site, plus `role.changed` et les cinq gestes de masquage.
     * Une trace ne peut jamais être plus courte que l'état qu'elle justifie —
     * et aucun cas de la liste du jalon 1 ne tombe en `rolling_12m`.
     */
    public function retentionClass(): AdminActionRetention
    {
        $alwaysPermanent = in_array($this, [
            self::RoleChanged,
            self::AvatarHidden,
            self::AvatarUnhidden,
            self::NicknameMasked,
            self::NicknameUnmasked,
            self::NicknameBanned,
        ], true);

        if ($alwaysPermanent) {
            return AdminActionRetention::Permanent;
        }

        return match ($this->subject()) {
            AdminActionSubject::Movie,
            AdminActionSubject::Frame,
            AdminActionSubject::TakedownRequest,
            AdminActionSubject::Site => AdminActionRetention::Permanent,
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
            self::MovieWithdrawn => AdminActionSubject::Movie,
            self::FrameUnpublished,
            self::FrameGridUnpublished,
            self::FrameSuspended,
            self::FrameUnsuspended,
            self::FrameWithdrawn => AdminActionSubject::Frame,
            self::RoleChanged,
            self::AvatarHidden,
            self::AvatarUnhidden => AdminActionSubject::User,
            self::NicknameMasked,
            self::NicknameUnmasked,
            self::NicknameBanned => AdminActionSubject::Player,
            self::TakedownDecided => AdminActionSubject::TakedownRequest,
            self::SiteClosed,
            self::SiteReopened => AdminActionSubject::Site,
        };
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
