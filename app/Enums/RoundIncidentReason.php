<?php

namespace App\Enums;

/** Motif d'un incident de manche, agrégé par film pour la file de curation : cast de `round.cancel_reason` et `round_tier.substitution_reason` — un seul enum pour les deux colonnes. */
enum RoundIncidentReason: string
{
    case FrameUnavailable = 'frame_unavailable';

    case NoVariantAvailable = 'no_variant_available';

    case MovieWithdrawn = 'movie_withdrawn';
}
