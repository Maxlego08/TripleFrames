<?php

namespace App\Enums;

/** Nature d'une partie, figée à la création et qu'aucun chemin ne mute : cast de `game.mode`. */
enum GameMode: string
{
    case Multiplayer = 'multiplayer';

    case Solo = 'solo';
}
