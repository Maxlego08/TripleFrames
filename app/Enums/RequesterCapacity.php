<?php

namespace App\Enums;

/** Qualité déclarée par l'auteur d'une demande de retrait : cast de `takedown_request.requester_capacity`. */
enum RequesterCapacity: string
{
    case RightsHolder = 'rights_holder';

    case Agent = 'agent';

    case Other = 'other';
}
