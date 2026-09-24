<?php

namespace App\Support\Retention;

/**
 * Une ligne éligible telle que le moteur de purge la manipule : sa valeur de
 * colonne pilote et sa clé, rien d'autre.
 *
 * C'est aussi le **curseur** du lot suivant (tri `pilote, clé`) : le moteur
 * reprend strictement après la dernière ligne vue, qu'elle ait été effacée ou
 * qu'elle ait échoué. Aucune autre colonne n'est lue — la clé d'un jeton de
 * réinitialisation est une adresse électronique, qui ne quitte jamais la
 * mémoire du processus (ni journal, ni `purge_run`).
 */
final readonly class PurgeRow
{
    public function __construct(
        public int|string $pilot,
        public int|string $key,
    ) {}
}
