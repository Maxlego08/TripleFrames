<?php

namespace App\Enums;

/** Motif d'un incident de manche, agrégé par film pour la file de curation : cast de `round.cancel_reason` et `round_tier.substitution_reason` — un seul enum pour les deux colonnes. */
enum RoundIncidentReason: string
{
    case FrameUnavailable = 'frame_unavailable';

    case NoVariantAvailable = 'no_variant_available';

    case MovieWithdrawn = 'movie_withdrawn';

    /**
     * Aucun QCM composable pour la manche (E10-07, spec 70 § 10.7) : moins de
     * trois leurres même en mode dégradé, ou échec technique du calcul de la
     * composition. **Motif d'annulation seulement, jamais de substitution** :
     * seule une manche Facile, dont le QCM est l'unique mode de saisie, est
     * annulée pour lui (par 60). En Normal, ce cas terminal ne s'écrit dans
     * aucune colonne : il se lit `round_tier(N).served_at IS NOT NULL` et
     * `round.decoy_movie_id_1 IS NULL`. 19 caractères sous `string(30)` :
     * aucune migration.
     */
    case ChoicesUnavailable = 'choices_unavailable';
}
