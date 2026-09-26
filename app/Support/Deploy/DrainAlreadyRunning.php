<?php

namespace App\Support\Deploy;

use App\ValueObjects\Deploy\DrainState;
use RuntimeException;

/**
 * Levée par {@see DeployDrain::start()} quand un drapeau de drainage existe
 * déjà — spec 100 § 11.3, contrat C18-bis (nom figé).
 *
 * Porte le drapeau trouvé, que `deploy:drain` décrit au porteur (phase et
 * échéance) avant de sortir en code 2 **sans rien modifier**. Le message est
 * un diagnostic de journal, jamais montré à un joueur : il ne porte qu'un
 * code de phase et un instant.
 */
final class DrainAlreadyRunning extends RuntimeException
{
    public function __construct(public readonly DrainState $state)
    {
        parent::__construct(sprintf(
            'Drainage déjà en cours : phase %s, échéance %s.',
            $state->phase->value,
            $state->toCache()['expiresAt'],
        ));
    }
}
