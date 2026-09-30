<?php

namespace App\Support\Scoring;

use DomainException;

/**
 * Une partie porte une version de règle de score que ce code ne sait pas
 * appliquer (spec 80 § 6.2, contrat C13).
 *
 * Jamais rattrapée pour « faire au mieux » : noter une partie sous une autre
 * règle que la sienne rendrait faux un journal conservé douze mois.
 */
final class UnsupportedScoringVersion extends DomainException {}
