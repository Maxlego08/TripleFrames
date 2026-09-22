<?php

namespace App\Enums;

/** Présence vive d'un siège, le comptage de capacité étant `connection_state <> 'left'` : cast de `player.connection_state`. */
enum PlayerConnectionState: string
{
    case Connected = 'connected';

    case Disconnected = 'disconnected';

    case Left = 'left';
}
