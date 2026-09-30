<?php

namespace App\Support\Scoring;

use App\Enums\GuessSource;
use App\Models\Game;
use App\Settings\PlatformLimits;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierScore;
use InvalidArgumentException;
use LogicException;

/**
 * La fonction pure unique du score : palier retenu ET points, ensemble (spec 80
 * § 4, contrat C13).
 *
 * **Une seule formule.** La saisie l'appelle une fois par bonne réponse dans la
 * transaction de verrouillage, le rejeu l'appelle à l'identique : un palier
 * choisi d'un côté et un bonus calculé de l'autre finiraient par lire deux
 * instants différents (A80-2).
 *
 * **Pure** : aucune E/S, aucune horloge, aucun aléa. Elle ne lit que des entiers
 * figés au lancement ou au verrouillage (§ 1.3) — les paliers de `round_tier`,
 * `game.tier_grace_ms`, l'interrupteur `speedBonus` de l'instantané, `B_max(N)`
 * et le plancher sous `game.scoring_version` — et aucune valeur mesurée ou
 * déclarée par le client (invariant L3).
 *
 * **Entiers seulement.** Le bonus est un plancher entier calculé par `intdiv`,
 * jamais par un flottant : `floor(100 × 0.5 × (1 − 1700/5000))` vaut 32 quand la
 * valeur exacte vaut 33, et un point d'écart entre le direct et le rejeu suffit
 * à perdre un litige de score (§ 3.2).
 */
final class ScoreCalculator
{
    /** Étiquette de la branche de la version 1 : jamais modifiée une fois publiée. */
    private const int V1 = 1;

    /** Plancher neutre : le premier palier. */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * Le palier retenu et les points d'une bonne réponse reçue à `answeredAtMs`
     * millisecondes de `round.started_at`.
     *
     * Version 1 (§ 4.2) :
     * 1. `corrected = max(0, answeredAtMs − tierGraceMs)` ;
     * 2. palier dont la fenêtre contient `corrected`, relevé au plancher du QCM
     *    s'il est en dessous ;
     * 3. `P`, `d` du palier retenu, `t = max(0, corrected − ouverture)` ;
     * 4. `pointsBonus = (speedBonus ∧ P > 0 ∧ pct > 0)
     *    ? intdiv(P × pct × (d − t), FULL_PERCENT × d) : 0` ;
     * 5. `pointsTotal = P + pointsBonus`.
     *
     * @param  int  $answeredAtMs  Instant serveur de réception, en ms depuis `round.started_at`.
     * @param  int  $tierGraceMs  Grâce de frontière figée sur la partie.
     * @param  bool  $speedBonus  Interrupteur de l'instantané de la partie.
     * @param  int  $speedBonusMaxPercent  `B_max(N)`, pourcentage entier.
     * @param  int  $scoringVersion  Version de la règle de la partie.
     * @param  int  $floorTierIndex  Plancher de palier (QCM), 1 = sans effet.
     *
     * @throws UnsupportedScoringVersion Version inconnue.
     * @throws InvalidArgumentException Instant hors de `[0, D + tierGraceMs)`, plancher hors de
     *                                  `[1, N]`, `B_max` hors de `[0, FULL_PERCENT]` ou grâce négative.
     */
    public static function score(
        int $answeredAtMs,
        TierSchedule $tiers,
        int $tierGraceMs,
        bool $speedBonus,
        int $speedBonusMaxPercent,
        int $scoringVersion,
        int $floorTierIndex = self::FIRST_TIER_INDEX,
    ): TierScore {
        ScoringRules::assertSupported($scoringVersion);

        if ($tierGraceMs < 0) {
            throw new InvalidArgumentException(sprintf(
                'ScoreCalculator : grâce de frontière négative (%d ms).',
                $tierGraceMs,
            ));
        }

        if ($speedBonusMaxPercent < 0 || $speedBonusMaxPercent > PlatformLimits::FULL_PERCENT) {
            throw new InvalidArgumentException(sprintf(
                'ScoreCalculator : B_max de %d %% hors de [0, %d].',
                $speedBonusMaxPercent,
                PlatformLimits::FULL_PERCENT,
            ));
        }

        if ($floorTierIndex < self::FIRST_TIER_INDEX || $floorTierIndex > $tiers->count()) {
            throw new InvalidArgumentException(sprintf(
                'ScoreCalculator : plancher de palier %d hors de [1, %d].',
                $floorTierIndex,
                $tiers->count(),
            ));
        }

        // Fenêtre d'acceptation de la saisie (C10) : `corrected` reste alors dans [0, D).
        if ($answeredAtMs < 0 || $answeredAtMs >= $tiers->durationMs() + $tierGraceMs) {
            throw new InvalidArgumentException(sprintf(
                'ScoreCalculator : réponse reçue à %d ms, hors de [0, %d).',
                $answeredAtMs,
                $tiers->durationMs() + $tierGraceMs,
            ));
        }

        return match ($scoringVersion) {
            self::V1 => self::scoreV1($answeredAtMs, $tiers, $tierGraceMs, $speedBonus, $speedBonusMaxPercent, $floorTierIndex),
            default => throw new LogicException(sprintf(
                'ScoreCalculator : la version %d est prise en charge par ScoringRules mais n’a pas de branche ici.',
                $scoringVersion,
            )),
        };
    }

    /**
     * Seul point d'entrée des appelants (saisie, rejeu) : toutes les entrées sont
     * résolues depuis la partie FIGÉE, jamais depuis la configuration courante.
     *
     * `tierGraceMs = game.tier_grace_ms` ; `speedBonus = game.settings_snapshot->speedBonus` ;
     * `pct = ScoringRules::speedBonusMaxPercent(game.frames_per_round, game.scoring_version)` ;
     * `floor = ScoringRules::floorTierIndex(source, game.input_difficulty,
     * game.frames_per_round, game.scoring_version)`.
     *
     * `$tiers` vient de {@see TierSchedule::fromRound()} en production comme au
     * rejeu.
     *
     * @throws LogicException Calendrier dont le nombre de paliers diffère de `game.frames_per_round`,
     *                        ou clic QCM dans une partie Expert.
     * @throws UnsupportedScoringVersion
     * @throws InvalidArgumentException Voir {@see self::score()}.
     */
    public static function forGuess(Game $game, TierSchedule $tiers, int $answeredAtMs, GuessSource $source): TierScore
    {
        if ($tiers->count() !== $game->frames_per_round) {
            throw new LogicException(sprintf(
                'ScoreCalculator : %d paliers pour une partie à frames_per_round = %d.',
                $tiers->count(),
                $game->frames_per_round,
            ));
        }

        $version = $game->scoring_version;

        return self::score(
            answeredAtMs: $answeredAtMs,
            tiers: $tiers,
            tierGraceMs: $game->tier_grace_ms,
            speedBonus: $game->settings_snapshot->speedBonus,
            speedBonusMaxPercent: ScoringRules::speedBonusMaxPercent($game->frames_per_round, $version),
            scoringVersion: $version,
            floorTierIndex: ScoringRules::floorTierIndex($source, $game->input_difficulty, $game->frames_per_round, $version),
        );
    }

    /**
     * Branche de la version 1 (§ 4.2 et § 3.2). Préconditions déjà vérifiées.
     */
    private static function scoreV1(
        int $answeredAtMs,
        TierSchedule $tiers,
        int $tierGraceMs,
        bool $speedBonus,
        int $speedBonusMaxPercent,
        int $floorTierIndex,
    ): TierScore {
        $corrected = max(0, $answeredAtMs - $tierGraceMs);
        $selected = $tiers->containing($corrected);

        if ($selected->tierIndex < $floorTierIndex) {
            $selected = $tiers->tier($floorTierIndex);
        }

        $points = $selected->points;
        $durationMs = $selected->durationMs;
        $elapsedMs = max(0, $corrected - $selected->startsAtOffsetMs);

        $pointsBonus = $speedBonus && $points > 0 && $speedBonusMaxPercent > 0
            ? intdiv($points * $speedBonusMaxPercent * ($durationMs - $elapsedMs), PlatformLimits::FULL_PERCENT * $durationMs)
            : 0;

        return new TierScore(
            tierIndex: $selected->tierIndex,
            pointsTier: $points,
            pointsBonus: $pointsBonus,
            pointsTotal: $points + $pointsBonus,
        );
    }
}
