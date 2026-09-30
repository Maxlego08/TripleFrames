<?php

namespace App\Support\Scoring;

use App\Models\Guess;
use App\Models\Round;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierScore;

/**
 * Le rejeu d'une bonne réponse (spec 80 § 6.3, contrat C13 ; 10 § 7.5).
 *
 * Il rappelle la fonction pure du score EXACTEMENT comme la transaction de
 * verrouillage l'a appelée — {@see ScoreCalculator::forGuess()} sur
 * {@see TierSchedule::fromRound()} —, en ne lisant que des faits figés :
 * `guess.answered_at_ms` et `guess.source` ; les lignes `round_tier`
 * matérialisées au lancement ; sur `game`, `tier_grace_ms`,
 * `settings_snapshot->speedBonus`, `frames_per_round`, `input_difficulty` et
 * `scoring_version` (E10-48). Il doit redonner exactement
 * {@see TierScore::fromGuess()} : un score attribué n'est jamais recalculé, et
 * le journal conservé douze mois doit pouvoir le prouver.
 *
 * **Jamais la configuration courante.** Ni `PlatformLimits` — la grâce de
 * frontière est relue dans `game.tier_grace_ms`, figée au lancement —, ni
 * `ScoringRules::VERSION` — la partie se rejoue sous sa propre version, dont la
 * branche est conservée (§ 6.2) —, ni `room.settings`. Aucune écriture : le
 * rejeu constate, il ne corrige jamais une ligne `guess`.
 *
 * Indifférent au statut de la manche : une manche annulée se rejoue comme une
 * autre, le journal devant rester cohérent là même où ses points ne comptent
 * pas. Le rapport d'écarts d'une partie entière (`mismatches()`) est dû au
 * jalon 2 (L80-9).
 */
final class ScoreReplayer
{
    /**
     * Le palier et les points que la règle de la partie donne à cette bonne
     * réponse, recalculés depuis les seuls faits figés.
     *
     * La manche et sa partie sont relues en base : une relation déjà chargée,
     * peut-être périmée, n'y entre pas.
     *
     * @throws UnsupportedScoringVersion Version de règle de la partie inconnue de ce code.
     */
    public static function replay(Guess $guess): TierScore
    {
        $round = Round::query()->with('game')->findOrFail($guess->round_id);

        return ScoreCalculator::forGuess(
            $round->game,
            TierSchedule::fromRound($round),
            $guess->answered_at_ms,
            $guess->source,
        );
    }
}
