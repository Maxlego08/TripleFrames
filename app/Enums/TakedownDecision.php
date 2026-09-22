<?php

namespace App\Enums;

/** Décision motivée sur une demande de retrait — mêmes mots que `ContentAvailability` pour ses trois régimes de retrait, mais deux enums distincts : cast de `takedown_request.decision`. */
enum TakedownDecision: string
{
    case Suspended = 'suspended';

    case Unpublished = 'unpublished';

    case Withdrawn = 'withdrawn';

    case Rejected = 'rejected';

    case OutOfScope = 'out_of_scope';
}
