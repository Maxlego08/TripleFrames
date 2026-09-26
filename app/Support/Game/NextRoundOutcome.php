<?php

namespace App\Support\Game;

use App\Actions\Game\AdvanceToNextRound;

/**
 * L'issue du geste « manche suivante » ({@see AdvanceToNextRound}) — spec 60
 * § 5.4 (enum interne, nom libre, hors schéma).
 *
 * La route de l'hôte (`room.round.next`, L60-13) et celle du solo
 * (`solo.next`, L60-16) la traduisent en réponse HTTP : 204 (ou paquet à
 * jour en solo) pour `Advanced` et `Unchanged`, 403 pour `Forbidden`, 409
 * `{ "code": "not_revealing" }` pour `NotRevealing`.
 */
enum NextRoundOutcome
{
    /** La révélation est raccourcie : `reveal_ends_at` et la manche suivante avancés. */
    case Advanced;

    /** La révélation finit déjà avant `now + preload_lead_ms + nextRoundMarginMs` : rien. */
    case Unchanged;

    /** Hors révélation (aucune manche en `revealing`, ou `now ≥ reveal_ends_at`). */
    case NotRevealing;

    /** L'autorité relue sous le verrou du salon refuse le geste (hôte transféré entre-temps). */
    case Forbidden;
}
