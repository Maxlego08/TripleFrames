<?php

namespace App\Support\Game;

use App\Actions\Game\ResumeGame;

/**
 * Qui reprend une partie en pause ({@see ResumeGame}) — spec 60 § 14.2,
 * D64 du 07/10 (enum interne, nom libre). Écrit au journal `game` sous sa
 * seule valeur, jamais un identifiant de siège (§ 4.7).
 */
enum ResumeAuthor: string
{
    /** Le battement d'un siège présent : ne reprend jamais une pause `manual`. */
    case Heartbeat = 'heartbeat';

    /** Le geste « Reprendre » de l'hôte. */
    case Host = 'host';

    /** Le geste « Reprendre » d'un siège présent, l'hôte n'étant pas un siège présent. */
    case Seat = 'seat';

    /** Le geste « Reprendre » du joueur solo. */
    case Solo = 'solo';
}
