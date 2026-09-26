<?php

namespace App\Enums;

/**
 * L'issue d'une soumission texte ou d'un clic, telle que le siège la lit dans
 * la réponse HTTP (spec 70 § 7.7, contrat C10 § 2) : champ `result` du corps.
 *
 * `Rejected` a **un seul** corps quelle qu'en soit la cause — préfixe ou
 * sous-titre ambigu, garde exacte, saisie lointaine ou presque juste : aucune
 * réponse ne suggère la proximité (décision 13). `Closed` n'est jamais
 * compté : saisie ou manche close, siège sans participation, difficulté
 * sans texte libre.
 */
enum SubmissionOutcome: string
{
    case Accepted = 'accepted';

    case Rejected = 'rejected';

    case Closed = 'closed';
}
