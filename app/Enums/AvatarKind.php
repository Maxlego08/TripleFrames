<?php

namespace App\Enums;

/** Nature effective d'un avatar, NULL en base valant repli sur les initiales : cast de `users.avatar_kind`, `player.avatar_kind` et `game_player.display_avatar_kind`. */
enum AvatarKind: string
{
    case Preset = 'preset';

    case Provider = 'provider';
}
