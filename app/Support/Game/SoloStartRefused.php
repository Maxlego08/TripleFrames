<?php

namespace App\Support\Game;

use App\Actions\Game\StartSoloGame;
use App\ValueObjects\Game\SoloStartOutcome;
use RuntimeException;

/**
 * Levée DANS la transaction de {@see StartSoloGame} pour l'annuler entière
 * sur un refus de règle — drainage, vivier, ou `LaunchOutcome::refused` rendu
 * par `OpenGame`, qui ne lève pas (spec 60 § 16.2) : un relancement refusé ne
 * tue jamais la partie solo en cours.
 *
 * Interne à l'action : elle l'intercepte hors de la transaction et rend
 * {@see self::$outcome}. Elle ne remonte jamais à un contrôleur, et n'est
 * jamais un échec technique.
 */
final class SoloStartRefused extends RuntimeException
{
    public function __construct(public readonly SoloStartOutcome $outcome)
    {
        parent::__construct(sprintf(
            'Démarrage solo refusé : %s.',
            $outcome->refusal()->value,
        ));
    }
}
