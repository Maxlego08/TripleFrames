<?php

namespace App\Enums;

/** Cycle de vie du salon seulement, le statut d'une partie vivant sur `game.status` : cast de `room.status`. */
enum RoomStatus: string
{
    case Lobby = 'lobby';

    case Playing = 'playing';

    case Archived = 'archived';
}
