<?php

namespace App\Events\Game;

use App\Enums\RoundPlayerInputState;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * La saisie d'un siège vient d'être close sans bonne réponse (spec 70 § 3.2,
 * contrat C10) : `qcm_wrong` (clic faux) ou `attempts_exhausted` (plafond de
 * texte atteint, ou cas terminal du QCM en Normal, § 10.7).
 *
 * **Événement de domaine, jamais diffusé** : ni `ShouldBroadcast`, ni charge
 * cliente — `qcm_wrong` révélerait une mauvaise réponse, que la règle interdit
 * de diffuser (10 § 7.6, 70 § 9.5), et les deux identifiants sont internes
 * (10 § 1.1). Ses seuls destinataires sont les écouteurs de 60, qui appellent
 * `SeatInputClosed::handle()` dans leur propre transaction pour réévaluer la
 * fin anticipée (R-21).
 *
 * `ShouldDispatchAfterCommit` : émis dans la transaction qui ferme la saisie,
 * il n'est délivré qu'après son commit, et jamais si elle est annulée.
 */
final class InputClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  int  $roundId  `round.id`, identifiant interne.
     * @param  int  $playerId  `player.id`, identifiant interne.
     * @param  RoundPlayerInputState  $state  `QcmWrong` ou `AttemptsExhausted`.
     *
     * @throws InvalidArgumentException Un état qui n'est pas une clôture sans bonne réponse.
     */
    public function __construct(
        public readonly int $roundId,
        public readonly int $playerId,
        public readonly RoundPlayerInputState $state,
    ) {
        if ($state !== RoundPlayerInputState::QcmWrong && $state !== RoundPlayerInputState::AttemptsExhausted) {
            throw new InvalidArgumentException(sprintf(
                'InputClosed : état [%s], attendu qcm_wrong ou attempts_exhausted.',
                $state->value,
            ));
        }
    }
}
