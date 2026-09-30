<?php

namespace App\Support\Draw;

use RuntimeException;

/**
 * Garde défensive du tirage (spec 30 § 4.6, contrat C2) — **jamais un chemin
 * nominal**.
 *
 * Après un rapport non bloqué, seule une projection périmée peut la lever : la
 * garde et le tirage lisent le même instantané de la transaction de lancement
 * (§ 6.5). C'est une incohérence de données, que `catalog:reproject` répare,
 * pas un réglage : la transaction est annulée, et l'exception n'est **jamais**
 * traduite en refus `pool_insufficient`, dont le rapport affirmerait le
 * contraire. La réponse faite à l'hôte relève de la spec 50.
 *
 * Le message est un diagnostic de journal, jamais montré à un joueur : il ne
 * porte que les deux entiers.
 */
final class PoolTooSmallException extends RuntimeException
{
    /**
     * @param  int  $works  Œuvres effectivement tirables.
     * @param  int  $roundsCount  `M` demandé.
     */
    public function __construct(
        public readonly int $works,
        public readonly int $roundsCount,
    ) {
        parent::__construct(sprintf(
            'Tirage : %d œuvre(s) tirable(s) pour %d manches demandées — projection périmée ? (catalog:reproject).',
            $works,
            $roundsCount,
        ));
    }
}
