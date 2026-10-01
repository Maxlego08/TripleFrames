<?php

namespace App\Enums;

/**
 * Cause d'un refus de normalisation d'un avatar téléversé (spec 40 § 11.2) :
 * chaque cas porte la clé du message traduit, rendue sous le champ `avatar`.
 */
enum AvatarImageFailure: string
{
    case Format = 'format';

    case Animated = 'animated';

    case Unreadable = 'unreadable';

    case Dimensions = 'dimensions';

    case TooHeavy = 'too_heavy';

    public function messageKey(): string
    {
        return 'account.avatar.errors.'.$this->value;
    }
}
