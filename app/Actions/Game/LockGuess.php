<?php

namespace App\Actions\Game;

use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\AnswerAccepted;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\AcceptanceWindow;
use App\Support\Game\ReceptionInstant;
use App\Support\Game\RoundClock;
use App\Support\Scoring\ScoreCalculator;
use App\ValueObjects\Answers\MatchResult;
use App\ValueObjects\Answers\SubmissionVerdict;
use App\ValueObjects\Scoring\TierSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La transaction de verrouillage d'une bonne réponse — spec 70 § 9, contrat
 * C10 § 2 et § 4 (nom et signature figés), 10 § 7.6 et A12.
 *
 * **Seule écrivaine de `guess`**, et seule écrivaine de ses colonnes de
 * points, qu'elle recopie TELLES QUELLES de {@see ScoreCalculator::forGuess()}
 * (spec 80 § 5, gardé par `ScoringWritersTest`). Dans un seul
 * `DB::transaction`, dans cet ordre :
 *
 * 1. `round FOR UPDATE`, puis revérification de la recevabilité
 *    ({@see AcceptanceWindow}) sur la ligne relue sous le verrou ;
 * 2. `round_player FOR UPDATE`, puis revérification de `acceptsText()` (texte)
 *    ou `acceptsChoice()` (clic) sur la ligne relue : un clic faux et une
 *    bonne réponse texte concurrents ne donnent jamais `locked` et
 *    `qcm_wrong` ensemble. Si une revérification échoue : 409 `closed` avec
 *    l'état relu, **sans aucune écriture** ;
 * 3. le palier et les points, par la fonction pure de 80, une seule fois ;
 * 4. `found_count = found_count + 1`, puis `lock_rank := found_count` lu
 *    sous le verrou de la manche ;
 * 5. `INSERT guess`, instantané complet de la règle (§ 9.3) ;
 * 6. `round_player` passe `locked`, `input_closed_at` = instant de réception ;
 * 7. {@see AnswerAccepted}, délivré **après le commit** aux écouteurs de 60.
 *
 * **Ordre de verrouillage** `round` puis `round_player`, dans l'ordre global
 * room → player → game → round → round_player (contrat C7 § 4.3) ; la
 * transaction **ne verrouille jamais `game`** (contrat C13 § 4.5) : la partie
 * n'y est lue que pour ses colonnes figées au lancement.
 *
 * **`lock_rank` est l'ordre d'acquisition du verrou**, jamais un
 * `SELECT MAX(lock_rank)` : deux bonnes réponses simultanées se sérialisent
 * sur la ligne `round`, et la seconde lit le compteur que la première a
 * validé — aucune erreur 1062, aucune bonne réponse perdue.
 * `guess_round_player_uq` et `guess_round_rank_uq` sont des filets, pas des
 * mécanismes. Le rang peut s'inverser avec `answered_at_ms` à quelques
 * millisecondes près, `receivedAt` étant capturé avant le verrou : le
 * départage de 80 lit `answered_at_ms`, jamais `lock_rank`.
 *
 * **Le verdict n'est pas réévalué** (§ 6.5) : l'appariement a été jugé une
 * fois, à la réception ; seules la recevabilité et l'état de saisie sont
 * revérifiés. Une publication validée entre-temps ne change rien, et un
 * `guess` écrit n'est jamais recalculé (décision 13, jamais rétroactif).
 *
 * `$receivedAt` est l'instant de réception capturé à l'entrée de la requête
 * ({@see ReceptionInstant}), `$answeredAtMs` son décalage dans la manche
 * ({@see RoundClock::offsetMs()}), calculé une fois par l'appelant : l'attente
 * du verrou ne fait jamais changer de palier (principe 1).
 */
final readonly class LockGuess
{
    /**
     * @throws LogicException Verdict d'appariement qui n'est pas une
     *                        acceptation, nature incohérente avec la voie de
     *                        saisie, ou participation d'une autre manche.
     */
    public function handle(
        Round $round,
        RoundPlayer $roundPlayer,
        Game $game,
        MatchResult $match,
        GuessSource $source,
        CarbonImmutable $receivedAt,
        int $answeredAtMs,
    ): SubmissionVerdict {
        self::assertLockable($round, $roundPlayer, $match, $source);

        return DB::transaction(static function () use ($round, $roundPlayer, $game, $match, $source, $receivedAt, $answeredAtMs): SubmissionVerdict {
            // 1. La manche, sous verrou exclusif, et sa recevabilité relue.
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
            $admitted = AcceptanceWindow::admits($lockedRound, $game, $receivedAt);

            // 2. La participation, sous verrou exclusif, et son état relu :
            // jamais l'instance de l'appelant, lue avant le verrou.
            $lockedSeat = RoundPlayer::query()->whereKey($roundPlayer->id)->lockForUpdate()->firstOrFail();

            if (! $admitted || ! self::accepts($lockedSeat->input_state, $source)) {
                return SubmissionVerdict::closed($lockedSeat->input_state);
            }

            // 3. Le palier et les points : la fonction pure de 80, une fois,
            // sur les paliers matérialisés de la manche.
            $score = ScoreCalculator::forGuess($game, TierSchedule::fromRound($lockedRound), $answeredAtMs, $source);

            // 4. Le rang d'arrivée : le compteur vivant, incrémenté et lu sous
            // le verrou de la manche.
            $lockedRound->increment('found_count');
            $lockRank = $lockedRound->found_count;

            // 5. L'instantané complet de la règle (§ 9.3) : jamais de texte
            // brut, jamais d'IP, jamais d'horodatage client.
            $guess = new Guess;
            $guess->forceFill([
                'round_id' => $lockedRound->id,
                'player_id' => $lockedSeat->player_id,
                'received_at' => $receivedAt,
                'answered_at_ms' => $answeredAtMs,
                'tier_index' => $score->tierIndex,
                'lock_rank' => $lockRank,
                'source' => $source,
                'match_kind' => $match->matchKind,
                'answer_key_id' => $match->answerKeyId,
                'answer_key_normalized' => $match->answerKeyNormalized,
                'submitted_normalized' => $match->submittedNormalized,
                'edit_distance' => $match->editDistance,
                'prefix_was_ambiguous' => $match->prefixWasAmbiguous,
                'points_tier' => $score->pointsTier,
                'points_bonus' => $score->pointsBonus,
                'points_total' => $score->pointsTotal,
            ]);
            $guess->save();

            // 6. La saisie se ferme : `locked` si et seulement si la ligne
            // `guess` existe, dans la même transaction.
            $lockedSeat->forceFill([
                'input_state' => RoundPlayerInputState::Locked,
                'input_closed_at' => $receivedAt,
            ]);
            $lockedSeat->save();

            // 7. Délivré après le commit (`ShouldDispatchAfterCommit`).
            AnswerAccepted::dispatch($lockedRound->id, $lockedSeat->player_id, $lockRank);

            return SubmissionVerdict::accepted($lockRank, $score);
        });
    }

    /**
     * La voie de saisie est-elle encore ouverte pour cet état : le texte pour
     * `open` seulement, le clic pour `open` et `text_exhausted` (§ 3.3, seuls
     * prédicats employés par 70).
     */
    private static function accepts(RoundPlayerInputState $state, GuessSource $source): bool
    {
        return match ($source) {
            GuessSource::Text => $state->acceptsText(),
            GuessSource::Choice => $state->acceptsChoice(),
        };
    }

    /**
     * Les entrées d'un verrouillage, avant toute lecture : une acceptation,
     * dont la nature suit la voie de saisie (§ 9.3) — `choice` et aucune clé
     * pour un clic, une nature de clé pour un texte —, sur une participation
     * de cette manche.
     *
     * @throws LogicException
     */
    private static function assertLockable(Round $round, RoundPlayer $roundPlayer, MatchResult $match, GuessSource $source): void
    {
        if (! $match->accepted) {
            throw new LogicException('LockGuess : seul un appariement accepté se verrouille.');
        }

        $isChoice = $match->matchKind === GuessMatchKind::Choice;

        if ($source === GuessSource::Choice && (! $isChoice || $match->answerKeyId !== null)) {
            throw new LogicException('LockGuess : un clic se verrouille en nature choice, sans clé de réponse.');
        }

        if ($source === GuessSource::Text && ($isChoice || $match->answerKeyId === null)) {
            throw new LogicException('LockGuess : un texte se verrouille sur une clé de réponse, jamais en nature choice.');
        }

        if ($roundPlayer->round_id !== $round->id) {
            throw new LogicException(sprintf(
                'LockGuess : la participation n’appartient pas à la manche %d.',
                $round->sequence_index,
            ));
        }
    }
}
