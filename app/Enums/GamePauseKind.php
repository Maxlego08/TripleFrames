<?php

namespace App\Enums;

/**
 * Nature d'une pause de partie (D64 du 07/10, spec 60 § 14) : cast de
 * `game.pause_kind`, NULL hors pause.
 *
 * - `Empty` : plus aucun siège présent à la fin d'une révélation ; le retour
 *   d'un siège (battement) la reprend ;
 * - `Manual` : geste de l'hôte (multijoueur) ou du joueur solo ; seule une
 *   reprise explicite la reprend, jamais un battement.
 */
enum GamePauseKind: string
{
    case Empty = 'empty';

    case Manual = 'manual';
}
