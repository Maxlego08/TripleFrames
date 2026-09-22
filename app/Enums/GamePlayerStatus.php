<?php

namespace App\Enums;

/** Issue figée d'un siège dans une partie, la présence vive vivant sur `player.connection_state` : cast de `game_player.status`. */
enum GamePlayerStatus: string
{
    case Playing = 'playing';

    case Left = 'left';

    case Kicked = 'kicked';
}
