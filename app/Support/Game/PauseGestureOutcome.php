<?php

namespace App\Support\Game;

use App\Actions\Game\CancelPauseRequest;
use App\Actions\Game\RequestGamePause;
use App\Actions\Game\ResumePausedGame;

/**
 * L'issue d'un geste de pause manuelle — {@see RequestGamePause},
 * {@see CancelPauseRequest}, {@see ResumePausedGame} (D64 du 07/10, spec 60
 * § 14 ; enum interne, nom libre, hors schéma).
 *
 * Les routes `room.game.pause|pause.cancel|resume` et `solo.pause|
 * pause.cancel|resume` la traduisent : 204 (ou le paquet à jour en solo)
 * pour une issue sans code ; 409 `{ "code": … }` sinon, rendu par
 * `game.pause.errors.{code}` ; 403 pour `Forbidden`.
 */
enum PauseGestureOutcome
{
    /** Pause manuelle immédiate : la partie était entre deux manches. */
    case Paused;

    /** Pause demandée : elle prendra effet à la fin de la révélation de la manche en cours. */
    case Requested;

    /** Demande de pause retirée avant sa prise d'effet. */
    case Cancelled;

    /** Partie reprise : la manche suivante est reprogrammée après le décompte. */
    case Resumed;

    /** Déjà fait (double clic, deux onglets, geste concurrent) : rien. */
    case Unchanged;

    /** Aucune partie en cours, ou pause close à son échéance. */
    case NotRunning;

    /** Aucune manche ne reste à jouer après celle en cours : une pause n'aurait rien à suspendre. */
    case NoRoundLeft;

    /** Le budget de pause manuelle de la partie est épuisé. */
    case BudgetExhausted;

    /** Drainage de déploiement en cours : aucune nouvelle pause. */
    case Draining;

    /** L'autorité relue sous le verrou du salon refuse le geste. */
    case Forbidden;

    /** Le code du 409, NULL pour une issue acceptée ou un 403. */
    public function conflictCode(): ?string
    {
        return match ($this) {
            self::NotRunning => 'not_running',
            self::NoRoundLeft => 'no_round_left',
            self::BudgetExhausted => 'budget_exhausted',
            self::Draining => 'draining',
            default => null,
        };
    }
}
