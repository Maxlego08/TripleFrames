<?php

namespace App\Enums;

/** État d'une demande de retrait, colonne de file filtrée et triée à chaque affichage : cast de `takedown_request.status`. */
enum TakedownStatus: string
{
    case Received = 'received';

    case Acknowledged = 'acknowledged';

    case Decided = 'decided';

    case Closed = 'closed';
}
