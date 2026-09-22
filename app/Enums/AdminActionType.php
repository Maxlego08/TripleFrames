<?php

namespace App\Enums;

/** Liste fermée des gestes engageants consignés au journal d'administration : cast de `admin_action.action`. */
enum AdminActionType: string
{
    case RoleChanged = 'role.changed';

    case MovieSuspended = 'movie.suspended';

    case MovieUnsuspended = 'movie.unsuspended';

    case MovieUnpublished = 'movie.unpublished';

    case MovieRepublished = 'movie.republished';

    case MovieWithdrawn = 'movie.withdrawn';

    case MovieContentVerified = 'movie.content_verified';

    case FrameSuspended = 'frame.suspended';

    case FrameWithdrawn = 'frame.withdrawn';

    case AvatarHidden = 'avatar.hidden';

    case AvatarUnhidden = 'avatar.unhidden';

    case NicknameMasked = 'nickname.masked';

    case NicknameUnmasked = 'nickname.unmasked';

    case NicknameBanned = 'nickname.banned';

    case TakedownDecided = 'takedown.decided';

    /**
     * Classe de conservation, écrite à l'insertion depuis l'action elle-même.
     * Permanent : tout geste dont le sujet est un film, une image ou une demande de retrait,
     * plus `role.changed` et les cinq gestes de masquage. Une trace ne peut jamais être plus
     * courte que l'état qu'elle justifie.
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
            AdminActionSubject::TakedownRequest => AdminActionRetention::Permanent,
            AdminActionSubject::User,
            AdminActionSubject::Player => AdminActionRetention::Rolling12m,
        };
    }

    /** Sujet du geste, déductible du préfixe de l'action. */
    public function subject(): AdminActionSubject
    {
        return match ($this) {
            self::MovieSuspended,
            self::MovieUnsuspended,
            self::MovieUnpublished,
            self::MovieRepublished,
            self::MovieWithdrawn,
            self::MovieContentVerified => AdminActionSubject::Movie,
            self::FrameSuspended,
            self::FrameWithdrawn => AdminActionSubject::Frame,
            self::RoleChanged,
            self::AvatarHidden,
            self::AvatarUnhidden => AdminActionSubject::User,
            self::NicknameMasked,
            self::NicknameUnmasked,
            self::NicknameBanned => AdminActionSubject::Player,
            self::TakedownDecided => AdminActionSubject::TakedownRequest,
        };
    }

    /** Vrai pour les deux seuls gestes déclenchés par un seuil et non par une personne : `actor_id` NULL et `actor_name` = 'system'. */
    public function isAutomatic(): bool
    {
        return $this === self::AvatarHidden || $this === self::NicknameMasked;
    }
}
