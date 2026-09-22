<?php

namespace App\Enums;

/** État de saisie d'un joueur dans une manche, jamais diffusé pour un autre joueur : cast de `round_player.input_state`. */
enum RoundPlayerInputState: string
{
    case Open = 'open';

    case Locked = 'locked';

    case QcmWrong = 'qcm_wrong';

    case AttemptsExhausted = 'attempts_exhausted';

    case Revealed = 'revealed';

    case Skipped = 'skipped';

    /** Numérateur du prédicat de fin anticipée : tous les participants fermés arrêtent la manche. */
    public function isClosed(): bool
    {
        return $this !== self::Open;
    }

    /** Inatteignable hors `game.mode = 'solo'`, et ne produit jamais de ligne `guess`. */
    public function isSoloOnly(): bool
    {
        return $this === self::Revealed || $this === self::Skipped;
    }
}
