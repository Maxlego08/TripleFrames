<?php

namespace App\Support\Game;

use App\Actions\Game\FinalizeGame;
use App\Actions\Game\StartSoloGame;
use App\Enums\GameMode;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Round;
use App\Models\RoundPlayer;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * La clôture d'une manche solo `running` sans révélation — spec 60 § 16.5
 * (« Passer la manche ») et § 16.6 (relance d'un solo en cours).
 *
 * Deux écritures, sur une partie et une manche **déjà prises `FOR UPDATE`**
 * par l'appelant (ordre global `game → round → round_player`, E10-51) :
 *
 * - {@see self::skip()} — manche `running` à `ended_at` nul, close « comme
 *   par Passer la manche » : `skipped` sur toute saisie encore ouverte
 *   (`open` ou `text_exhausted`, jamais une saisie déjà close, qui garde son
 *   état), `ended_at = reveal_ends_at = min(now, started_at + D)` — jamais
 *   au-delà de `D` —, `status = completed`, `rounds_completed` recalculé ;
 * - {@see self::completeClosed()} — manche `running` à `ended_at` non nul
 *   (phase `closed` : après une bonne réponse ou « Voir la réponse », job
 *   `Reveal` en attente) : `status = completed` et `reveal_ends_at =
 *   ended_at`, **sans toucher `input_state`** — un siège `locked` le reste,
 *   et l'invariant `locked` ⟺ `guess` de 70 § 3.4 tient.
 *
 * Sans elles, {@see FinalizeGame} lèverait sa `LogicException` sur une manche
 * `running` dont `started_at + D` n'est pas atteint (80 § 10.3). Aucune
 * révélation : le titre reste visible au récapitulatif du podium. Aucune
 * écriture de `guess` (10 § 7.10), aucune diffusion (le solo n'en a pas).
 *
 * Appelants : {@see StartSoloGame} (interruption d'un solo en cours, lot
 * L60-15) ; « Passer la manche » (`SkipSoloRound`, lot L60-16), qui y ajoute
 * le battement et l'enchaînement. **Barrière 1 de 10 § 7.10** : une partie
 * qui n'est pas solo lève.
 */
final class SoloRoundClosure
{
    /**
     * Clôt une manche `running` à `ended_at` nul, « comme par Passer la
     * manche ». Rend l'instant de clôture écrit.
     *
     * @throws LogicException Partie non solo, manche d'une autre partie, ou
     *                        manche hors de la phase attendue.
     */
    public static function skip(Game $lockedGame, Round $lockedRound, CarbonImmutable $now): CarbonImmutable
    {
        self::assertSoloRound($lockedGame, $lockedRound);

        $startedAt = $lockedRound->started_at;

        if ($lockedRound->status !== RoundStatus::Running || $lockedRound->ended_at !== null || $startedAt === null) {
            throw new LogicException(sprintf(
                'SoloRoundClosure::skip() : la manche %d n’est pas en cours de jeu (running, ended_at nul).',
                $lockedRound->sequence_index,
            ));
        }

        $durationEnd = $startedAt->addMilliseconds($lockedRound->duration_ms);
        $closedAt = $now->lessThan($durationEnd) ? $now : $durationEnd;

        $openInputs = RoundPlayer::query()
            ->where('round_id', $lockedRound->id)
            ->whereIn('input_state', RoundPlayerInputState::notClosedValues())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($openInputs as $roundPlayer) {
            $roundPlayer->forceFill([
                'input_state' => RoundPlayerInputState::Skipped,
                'input_closed_at' => $closedAt,
            ])->save();
        }

        $lockedRound->forceFill([
            'status' => RoundStatus::Completed,
            'ended_at' => $closedAt,
            'reveal_ends_at' => $closedAt,
        ])->save();

        self::recountCompleted($lockedGame);

        return $closedAt;
    }

    /**
     * Passe `completed` une manche `running` déjà close (phase `closed`),
     * `reveal_ends_at = ended_at`, saisies intactes.
     *
     * @throws LogicException Partie non solo, manche d'une autre partie, ou
     *                        manche hors de la phase `closed`.
     */
    public static function completeClosed(Game $lockedGame, Round $lockedRound): void
    {
        self::assertSoloRound($lockedGame, $lockedRound);

        $endedAt = $lockedRound->ended_at;

        if ($lockedRound->status !== RoundStatus::Running || $endedAt === null) {
            throw new LogicException(sprintf(
                'SoloRoundClosure::completeClosed() : la manche %d n’est pas en phase closed (running, ended_at posé).',
                $lockedRound->sequence_index,
            ));
        }

        $lockedRound->forceFill([
            'status' => RoundStatus::Completed,
            'reveal_ends_at' => $endedAt,
        ])->save();

        self::recountCompleted($lockedGame);
    }

    /**
     * `game.rounds_completed = COUNT(round WHERE status = completed)`,
     * maintenu par 60 à chaque passage en `completed` et écrasé au gel
     * (E10-36).
     */
    private static function recountCompleted(Game $lockedGame): void
    {
        $lockedGame->forceFill([
            'rounds_completed' => Round::query()
                ->where('game_id', $lockedGame->id)
                ->where('status', RoundStatus::Completed->value)
                ->count(),
        ])->save();
    }

    /**
     * Barrière 1 de 10 § 7.10 : `revealed` et `skipped` sont inatteignables
     * hors solo ; la manche appartient à la partie verrouillée.
     *
     * @throws LogicException
     */
    private static function assertSoloRound(Game $lockedGame, Round $lockedRound): void
    {
        if ($lockedGame->mode !== GameMode::Solo) {
            throw new LogicException('SoloRoundClosure : une manche ne se clôt sans révélation qu’en solo (10 § 7.10).');
        }

        if ($lockedRound->game_id !== $lockedGame->id) {
            throw new LogicException('SoloRoundClosure : la manche n’appartient pas à la partie verrouillée.');
        }
    }
}
