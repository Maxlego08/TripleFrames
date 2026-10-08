<?php

namespace App\Support\Scoring;

use App\Models\Game;
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
 * pas. Le rapport d'écarts d'une partie entière ({@see self::mismatches()},
 * L80-9) le consomme : l'écran « inspecter une partie » (spec 20 § 12.2).
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

    /**
     * Le rapport d'écarts d'une partie (spec 80 § 6.3, L80-9) : chaque bonne
     * réponse rejouée, **manches annulées comprises** — le journal doit être
     * cohérent là même où les points ne comptent pas —, et seules celles dont
     * le rejeu diffère de ce qui a été écrit, triées par `sequence_index`
     * puis `lock_rank`. Une liste vide dit « journal cohérent ».
     *
     * Même fonction pure et mêmes faits figés que {@see self::replay()} ; la
     * partie est relue en base, et chaque manche ne charge son calendrier de
     * paliers qu'une fois. `publicId` est l'identifiant public du siège,
     * jamais une clé interne. Aucune écriture.
     *
     * @return list<array{sequenceIndex: int, publicId: string, stored: TierScore, replayed: TierScore}>
     *
     * @throws UnsupportedScoringVersion Version de règle de la partie inconnue de ce code.
     */
    public static function mismatches(Game $game): array
    {
        $game = Game::query()->findOrFail($game->id);

        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->orderBy('sequence_index')
            ->get();

        $mismatches = [];

        foreach ($rounds as $round) {
            $guesses = Guess::query()
                ->where('round_id', $round->id)
                ->with('player:id,public_id')
                ->orderBy('lock_rank')
                ->get();

            if ($guesses->isEmpty()) {
                continue;
            }

            $schedule = TierSchedule::fromRound($round);

            foreach ($guesses as $guess) {
                $stored = TierScore::fromGuess($guess);
                $replayed = ScoreCalculator::forGuess($game, $schedule, $guess->answered_at_ms, $guess->source);

                if ($stored->equals($replayed)) {
                    continue;
                }

                $mismatches[] = [
                    'sequenceIndex' => $round->sequence_index,
                    'publicId' => $guess->player->public_id,
                    'stored' => $stored,
                    'replayed' => $replayed,
                ];
            }
        }

        return $mismatches;
    }
}
