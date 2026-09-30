<?php

namespace App\Enums;

/** Classe de conservation d'une ligne de journal, écrite à l'insertion depuis `AdminActionType::retentionClass()` : cast de `admin_action.retention_class`. */
enum AdminActionRetention: string
{
    case Permanent = 'permanent';

    case Rolling12m = 'rolling_12m';
}
