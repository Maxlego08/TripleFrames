<?php

namespace App\Events\Game;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * Un siège vient de verrouiller une bonne réponse (spec 70 § 3.2 et § 9.4,
 * contrat C10) : la ligne `guess` existe, la saisie est `locked`.
 *
 * **Événement de domaine, jamais diffusé** : ni `ShouldBroadcast`, ni charge
 * cliente — les trois valeurs sont des identifiants internes (10 § 1.1). Ses
 * seuls destinataires sont les écouteurs de 60, qui appellent
 * `SeatInputClosed::handle()` dans leur propre transaction : c'est ce crochet
 * qui émet `player.locked` au salon, `{ sequenceIndex, publicId, lockRank }`
 * et rien d'autre, puis réévalue la fin anticipée (R-21).
 *
 * `ShouldDispatchAfterCommit` : émis dans la transaction de verrouillage
 * (`LockGuess`, étape 7), il n'est délivré qu'après son commit, et jamais si
 * elle est annulée — aucun « a trouvé » n'annonce une réponse que la base n'a
 * pas gardée.
 */
final class AnswerAccepted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  int  $roundId  `round.id`, identifiant interne.
     * @param  int  $playerId  `player.id`, identifiant interne.
     * @param  int  $lockRank  `guess.lock_rank`, ordre d'acquisition du verrou, à partir de 1.
     *
     * @throws InvalidArgumentException Un rang qui n'est pas un rang d'arrivée.
     */
    public function __construct(
        public readonly int $roundId,
        public readonly int $playerId,
        public readonly int $lockRank,
    ) {
        if ($lockRank < 1) {
            throw new InvalidArgumentException(sprintf(
                'AnswerAccepted : rang %d, un rang d’arrivée commence à 1.',
                $lockRank,
            ));
        }
    }
}
