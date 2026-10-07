<?php

namespace App\Support\Game;

use App\Enums\GamePauseKind;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Settings\EngineConstants;
use Carbon\CarbonImmutable;

/**
 * L'échéance d'une partie en pause — spec 60 § 14.3, D64 du 07/10 (classe
 * interne, nom libre). **Seule définition** de l'instant où une pause se
 * clôt en `interrupted` : `PauseGame`, `ResumeGame`, `InterruptPausedGame`,
 * `RecordHeartbeat`, `CatchUpGame`, `GameStateBuilder` et `game:reschedule`
 * la lisent ici, jamais en recalculant.
 *
 * - pause `empty` (plus aucun siège présent) : `paused_at + pauseTimeoutMs`,
 *   comme avant D64 ;
 * - pause `manual` : `paused_at + (pauseTimeoutMs − manual_paused_ms −
 *   launchCountdownMs)` — le budget des pauses manuelles est **cumulé par
 *   partie**, décompte de reprise compris (la reprise impute `attente +
 *   launchCountdownMs` à `manual_paused_ms`), si bien qu'une partie ne passe
 *   jamais plus de `pauseTimeoutMs` en pause manuelle, décomptes de reprise
 *   compris (borne du drainage, 100 § 11). `manual_paused_ms` ne change qu'à
 *   la reprise : l'échéance est stable pendant toute la pause.
 */
final class PauseDeadline
{
    /** L'échéance de la pause en cours, NULL si la partie n'est pas en pause. */
    public static function of(Game $game): ?CarbonImmutable
    {
        if ($game->status !== GameStatus::Paused || $game->paused_at === null) {
            return null;
        }

        return $game->paused_at->addMilliseconds(
            $game->pause_kind === GamePauseKind::Manual ? self::manualWindowMs($game) : EngineConstants::pauseTimeoutMs(),
        );
    }

    /**
     * Le budget de pause manuelle qui reste à la partie, en millisecondes,
     * jamais négatif — consommation arrêtée à la dernière reprise.
     */
    public static function remainingManualBudgetMs(Game $game): int
    {
        return max(0, EngineConstants::pauseTimeoutMs() - $game->manual_paused_ms);
    }

    /**
     * L'attente que permet encore le budget : ce qui reste, moins le
     * décompte de la reprise qui suivra. Jamais négative.
     */
    public static function manualWindowMs(Game $game): int
    {
        return max(0, self::remainingManualBudgetMs($game) - EngineConstants::launchCountdownMs());
    }

    /**
     * Une demande de pause manuelle est-elle encore permise ? Il faut que le
     * budget laisse une attente d'au moins un décompte de lancement : en
     * deçà, la pause se clôturerait presque aussitôt (D64 du 07/10).
     */
    public static function manualBudgetAllowsPause(Game $game): bool
    {
        return self::manualWindowMs($game) >= EngineConstants::launchCountdownMs();
    }
}
