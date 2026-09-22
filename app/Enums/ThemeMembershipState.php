<?php

namespace App\Enums;

/** Exception manuelle d'appartenance à un thème, NULL valant « aucune exception » : cast de `movie_theme.manual_state`. */
enum ThemeMembershipState: string
{
    case Added = 'added';

    case Removed = 'removed';
}
