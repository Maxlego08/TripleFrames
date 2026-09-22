<?php

namespace App\Enums;

/** État d'une manche, `cancelled` étant celui que l'invariant L1 exclut de toute agrégation de `guess` : cast de `round.status`. */
enum RoundStatus: string
{
    case Pending = 'pending';

    case Running = 'running';

    case Revealing = 'revealing';

    case Completed = 'completed';

    case Cancelled = 'cancelled';
}
