<?php

namespace App\Enums;

/** Verdict d'un passage de revue d'image, en ajout seul : cast de `frame_review.decision`. */
enum ReviewDecision: string
{
    case Passed = 'passed';

    case Rejected = 'rejected';
}
