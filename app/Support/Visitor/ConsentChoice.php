<?php

namespace App\Support\Visitor;

/** Le choix d'un visiteur sur la bannière de consentement (D62 du 06/10). */
enum ConsentChoice: string
{
    case Accepted = 'accepted';

    case Refused = 'refused';
}
