<?php

namespace App\Enums;

/** État d'une partie, sans état `pending` puisqu'elle naît au lancement avec son tirage déjà figé : cast de `game.status`. */
enum GameStatus: string
{
    case Running = 'running';

    case Paused = 'paused';

    case Completed = 'completed';

    case Interrupted = 'interrupted';
}
