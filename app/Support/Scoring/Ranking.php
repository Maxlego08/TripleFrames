<?php

namespace App\Support\Scoring;

use App\Settings\RoomSettingsBounds;
use App\ValueObjects\Scoring\PlayerTally;
use App\ValueObjects\Scoring\Standing;
use InvalidArgumentException;
use LogicException;

/**
 * La chaîne de départage, fonction pure (spec 80 § 8, contrat C13 § 4.4) :
 * aucune E/S, aucune horloge, aucun aléa.
 *
 * Ordre, lu dans {@see ScoringRules::TIE_BREAK_CHAIN}, source unique de la
 * chaîne (§ 1.3) :
 * 1. `score` décroissant ;
 * 2. `correctAnswers` décroissant — sinon deux films trouvés vite passeraient
 *    devant huit (§ 8.4) ;
 * 3. `totalAnswerTimeMs` croissant, somme BRUTE des `answered_at_ms` ;
 * 4. `findsByTier[1]`, puis `[2]`, …, jusqu'à `[N − 1]`, chacun décroissant —
 *    le palier `N` se déduit des autres, la somme valant `correctAnswers` ;
 * 5. place partagée, en classement de compétition : 1, 2, 2, 4.
 *
 * **Jamais la graine** (§ 8.3) : une égalité sur toute la chaîne est une place
 * partagée, jamais tirée au sort, et la signature ne reçoit rien qui permette
 * de la trancher autrement. Deux réceptions dans la même milliseconde restent
 * à égalité de temps : la chaîne ne lit pas `lock_rank`, ordre d'acquisition du
 * verrou qui peut s'inverser avec `answered_at_ms` (§ 8.4).
 *
 * **Rang nul** pour un siège qui n'a joué aucune manche (`roundsPlayed = 0`),
 * placé après tous les sièges classés (§ 8.2) : un rang 1 partagé pour une
 * partie que personne n'a jouée s'afficherait « 1ᵉʳ » dans l'historique.
 * `rank()` ignore le mode de la partie : en solo, c'est l'appelant
 * (`Scoreboard::leaderboard()`, le gel) qui force `rank = null` partout.
 *
 * **Ordre d'affichage** : rang croissant, puis `gamePlayerId` croissant —
 * interne, jamais exposé —, les sièges sans rang en dernier par
 * `gamePlayerId` croissant. Il ne dépend donc pas de l'ordre des totaux reçus.
 *
 * **Branches conservées** (§ 6.2) : la chaîne est sous `scoring_version`. La
 * branche de la version 1 lit {@see ScoringRules::TIE_BREAK_CHAIN}, qui EST la
 * chaîne de la version 1 ; une version qui changerait la chaîne figerait
 * d'abord celle-ci dans une constante de branche, pour qu'une partie ancienne
 * se départage toujours comme elle l'a été.
 */
final class Ranking
{
    /** Étiquette de la branche de la version 1 : jamais modifiée une fois publiée. */
    private const int V1 = 1;

    /** Premier rang de palier : la plus cryptique des images. */
    private const int FIRST_TIER_INDEX = 1;

    /**
     * Classe les sièges d'une partie.
     *
     * @param  list<PlayerTally>  $tallies  Les totaux de CHAQUE siège, sous une même portée.
     * @param  int  $framesPerRound  `N` de la partie (`game.frames_per_round`).
     * @param  int  $scoringVersion  Version de règle de la partie (`game.scoring_version`).
     * @return list<Standing> Ordre d'affichage normatif (§ 8.2).
     *
     * @throws UnsupportedScoringVersion Version de règle inconnue de ce code.
     * @throws InvalidArgumentException `N` hors bornes, siège en double, ou totaux
     *                                  dont les trouvailles ne couvrent pas les
     *                                  paliers `1..N` ou ne somment pas aux bonnes
     *                                  réponses.
     */
    public static function rank(array $tallies, int $framesPerRound, int $scoringVersion): array
    {
        ScoringRules::assertSupported($scoringVersion);
        self::assertTallies($tallies, $framesPerRound);

        return match ($scoringVersion) {
            self::V1 => self::rankV1($tallies, $framesPerRound),
            default => throw new LogicException(sprintf(
                'Ranking : la version de règle de score %d est prise en charge sans branche de départage.',
                $scoringVersion,
            )),
        };
    }

    /**
     * Branche de la version 1.
     *
     * @param  list<PlayerTally>  $tallies
     * @return list<Standing>
     */
    private static function rankV1(array $tallies, int $framesPerRound): array
    {
        $ranked = [];
        $unranked = [];

        foreach ($tallies as $tally) {
            if ($tally->roundsPlayed > 0) {
                $ranked[] = $tally;
            } else {
                $unranked[] = $tally;
            }
        }

        usort($ranked, static fn (PlayerTally $a, PlayerTally $b): int => self::compareV1($a, $b, $framesPerRound)
            ?: $a->gamePlayerId <=> $b->gamePlayerId);
        usort($unranked, static fn (PlayerTally $a, PlayerTally $b): int => $a->gamePlayerId <=> $b->gamePlayerId);

        // Rang de compétition : un siège égal au précédent sur toute la chaîne
        // en garde le rang, le suivant saute d'autant.
        $ranks = [];
        $rank = 0;

        foreach ($ranked as $position => $tally) {
            if ($position === 0 || self::compareV1($ranked[$position - 1], $tally, $framesPerRound) !== 0) {
                $rank = $position + 1;
            }

            $ranks[$position] = $rank;
        }

        $holders = array_count_values($ranks);
        $standings = [];

        foreach ($ranked as $position => $tally) {
            $standings[] = new Standing($tally, $ranks[$position], $holders[$ranks[$position]] > 1);
        }

        foreach ($unranked as $tally) {
            $standings[] = new Standing($tally, null, false);
        }

        return $standings;
    }

    /**
     * Comparaison de deux sièges sur la chaîne de la version 1 : négatif si `$a`
     * passe devant, nul à égalité sur toute la chaîne.
     *
     * Le `match` couvre exactement les critères de la chaîne, sans bras par
     * défaut : un critère ajouté à {@see ScoringRules::TIE_BREAK_CHAIN} sans
     * comparaison est refusé par l'analyse statique (bras manquant), et lève
     * `\UnhandledMatchError` à l'exécution plutôt que d'être ignoré.
     */
    private static function compareV1(PlayerTally $a, PlayerTally $b, int $framesPerRound): int
    {
        foreach (ScoringRules::TIE_BREAK_CHAIN as $criterion) {
            $order = match ($criterion) {
                'score_desc' => $b->score <=> $a->score,
                'correct_answers_desc' => $b->correctAnswers <=> $a->correctAnswers,
                'total_answer_time_ms_asc' => $a->totalAnswerTimeMs <=> $b->totalAnswerTimeMs,
                'tier_finds_desc' => self::compareFinds($a, $b, $framesPerRound),
            };

            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * Trouvailles aux paliers `1..N − 1`, dans cet ordre, chacune décroissante.
     */
    private static function compareFinds(PlayerTally $a, PlayerTally $b, int $framesPerRound): int
    {
        for ($tierIndex = self::FIRST_TIER_INDEX; $tierIndex < $framesPerRound; $tierIndex++) {
            $order = $b->findsByTier[$tierIndex] <=> $a->findsByTier[$tierIndex];

            if ($order !== 0) {
                return $order;
            }
        }

        return 0;
    }

    /**
     * @param  list<PlayerTally>  $tallies
     *
     * @throws InvalidArgumentException
     */
    private static function assertTallies(array $tallies, int $framesPerRound): void
    {
        if ($framesPerRound < RoomSettingsBounds::MIN_FRAMES_PER_ROUND || $framesPerRound > RoomSettingsBounds::MAX_FRAMES_PER_ROUND) {
            throw new InvalidArgumentException(sprintf(
                'Ranking : %d images par manche, hors de [%d, %d].',
                $framesPerRound,
                RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            ));
        }

        $tiers = range(self::FIRST_TIER_INDEX, $framesPerRound);
        $gamePlayerIds = [];
        $publicIds = [];

        foreach ($tallies as $tally) {
            if (isset($gamePlayerIds[$tally->gamePlayerId]) || isset($publicIds[$tally->publicId])) {
                throw new InvalidArgumentException(sprintf(
                    'Ranking : le siège %s figure deux fois parmi les totaux.',
                    $tally->publicId,
                ));
            }

            $gamePlayerIds[$tally->gamePlayerId] = true;
            $publicIds[$tally->publicId] = true;

            $keys = array_keys($tally->findsByTier);
            sort($keys);

            if ($keys !== $tiers) {
                throw new InvalidArgumentException(sprintf(
                    'Ranking : les trouvailles du siège %s doivent couvrir exactement les paliers 1..%d.',
                    $tally->publicId,
                    $framesPerRound,
                ));
            }

            if (array_sum($tally->findsByTier) !== $tally->correctAnswers) {
                throw new InvalidArgumentException(sprintf(
                    'Ranking : les trouvailles du siège %s ne somment pas à ses %d bonnes réponses.',
                    $tally->publicId,
                    $tally->correctAnswers,
                ));
            }
        }
    }
}
