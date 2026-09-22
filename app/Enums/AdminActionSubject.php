<?php

namespace App\Enums;

/** Nature du sujet d'un geste consigné, en alias court et non en nom de classe — ce n'est pas un `morphTo`, `admin_action.subject_id` n'a aucune clé étrangère : cast de `admin_action.subject_type`. */
enum AdminActionSubject: string
{
    case Movie = 'movie';

    case Frame = 'frame';

    case User = 'user';

    case Player = 'player';

    case TakedownRequest = 'takedown_request';
}
