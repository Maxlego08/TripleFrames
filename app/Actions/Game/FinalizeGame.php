<?php

namespace App\Actions\Game;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Events\Game\GameFinalized;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Round;
use App\Support\Scoring\Ranking;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Le gel d'une partie — spec 80 § 10, contrat C13 § 2.3 et § 4.5 (noms et
 * signatures figés).
 *
 * **Seule écrivaine**, dans `app/`, de `game.ended_at`, du statut final de la
 * partie (`completed` ou `interrupted`), de la valeur finale de
 * `game.rounds_completed` et des cinq agrégats de `game_player` (E10-36,
 * E10-43, C17 ; prouvé par `ScoringWritersTest`). Les agrégats sont nullables
 * pour que le gel soit un événement VÉRIFIABLE plutôt qu'un état qu'on oublie
 * de déclencher, et `ended_at` pilote la fenêtre de 12 mois et la purge : un
 * second écrivain pourrait poser `ended_at` sans agrégats, ou le poser à
 * « maintenant » et prolonger une conservation annoncée publiquement.
 *
 * **Déroulé** (§ 10.2), dans UNE transaction :
 * 1. issue hors de `{completed, interrupted}` : `\InvalidArgumentException`,
 *    avant toute lecture ;
 * 2. la ligne `game` est relue en `lockForUpdate` ; déjà gelée
 *    (`ended_at` non nul), l'appel rend `false` sans rien écrire — il est
 *    **idempotent**, et le premier appelant gagne (une fin normale et une
 *    clôture après pause concurrentes ne gèlent qu'une fois) ;
 * 3. clôture d'office des manches non terminales (§ 10.3), sous le verrou
 *    `round`, pris APRÈS le verrou `game` ;
 * 4. totaux en portée `Settled` ({@see Scoreboard::tallies()}), puis
 *    {@see Ranking::rank()} ;
 * 5. les cinq agrégats de chaque ligne `game_player` (§ 10.4) ;
 * 6. `game.status`, `game.ended_at`, `game.rounds_completed` =
 *    `COUNT(round WHERE status = completed)`, recalculé et écrasé ;
 * 7. {@see GameFinalized}, livré après commit ; rendre `true`.
 *
 * **Filtre unique** (§ 10.4, A80-7) : les manches `completed` après la clôture
 * d'office, pour les quatre premiers agrégats comme pour le rang. Une manche
 * restée `running` au moment du gel est d'abord close, puis comptée partout ;
 * avec deux filtres, une bonne réponse pourrait compter sans sa manche, et le
 * taux de réussite dépasser 100 %. Une manche `cancelled` n'entre dans aucun
 * agrégat (invariant L1) ; ses lignes `guess` restent en base, intactes.
 * Après le gel, les quatre premiers agrégats sont non nuls, zéro compris ;
 * `final_rank` est nul en solo (§ 8.2 : `Ranking` ignore le mode, c'est le
 * gel qui le force) et pour un siège sans manche jouée.
 *
 * **`$endedAt` est fourni par l'appelant** (§ 10.5), jamais « maintenant » par
 * défaut : fin de la dernière révélation, instant de l'annulation, échéance
 * prévue de la pause, ou {@see self::lastKnownActivity()} pour une partie
 * bloquée ou close d'office (`stale_game`) — sans quoi une partie ancienne
 * repartirait pour douze mois de conservation.
 *
 * **Verrous** (§ 10.8) : `game` puis `round`, jamais l'inverse, dans l'ordre
 * global room → player → game → round → round_player. Un appelant qui tient
 * déjà un verrou `round` appelle le gel après son commit, ou a pris `game` en
 * premier ; appelé sous sa transaction, le gel s'y emboîte, et
 * {@see GameFinalized} attend le commit de l'appelant.
 *
 * Le gel n'écrit rien sur `room`, ni sur `paused_at` ou `total_paused_ms`, ni
 * sur `game_player.status` (données de 50 et de 60), ni sur aucune colonne
 * figée de `game` (C6) : l'écriture de la partie passe par un modèle relu sous
 * verrou, dont l'instantané des réglages n'est jamais lu, donc jamais réécrit.
 */
final readonly class FinalizeGame
{
    /**
     * Les deux issues d'un gel — les statuts terminaux d'une partie.
     *
     * @var list<GameStatus>
     */
    private const array OUTCOMES = [GameStatus::Completed, GameStatus::Interrupted];

    /**
     * Colonnes de `game` que le gel écrit, recopiées sur l'instance de
     * l'appelant au retour.
     *
     * @var list<string>
     */
    private const array FINALIZED_COLUMNS = ['status', 'ended_at', 'rounds_completed', 'updated_at'];

    /**
     * Gèle la partie.
     *
     * Au retour, l'instance reçue porte le statut, `ended_at` et
     * `rounds_completed` de la partie gelée — par cet appel ou par un appel
     * antérieur —, relus sous verrou.
     *
     * @return bool `true` si CET appel a gelé la partie, `false` si elle l'était
     *              déjà (aucune écriture, aucun événement).
     *
     * @throws InvalidArgumentException Issue qui n'est ni `completed` ni `interrupted`.
     * @throws LogicException Manche `running` dont la durée n'est pas écoulée à
     *                        `$endedAt` : l'horloge d'une manche ne se met
     *                        jamais en pause, une partie ne se gèle pas au
     *                        milieu d'une manche qui court encore.
     */
    public function handle(Game $game, GameStatus $outcome, CarbonImmutable $endedAt): bool
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException(sprintf(
                'FinalizeGame : l’issue %s n’est pas terminale ; seules completed et interrupted gèlent une partie.',
                $outcome->value,
            ));
        }

        return DB::transaction(static function () use ($game, $outcome, $endedAt): bool {
            $lockedGame = Game::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($lockedGame->ended_at !== null) {
                self::reflect($game, $lockedGame);

                return false;
            }

            self::closeOpenRounds($lockedGame, $endedAt);
            self::freezeSeats($lockedGame);

            $lockedGame->forceFill([
                'status' => $outcome,
                'ended_at' => $endedAt,
                'rounds_completed' => Round::query()
                    ->where('game_id', $lockedGame->id)
                    ->where('status', RoundStatus::Completed)
                    ->count(),
            ])->save();

            GameFinalized::dispatch($lockedGame->id, $outcome);

            self::reflect($game, $lockedGame);

            return true;
        });
    }

    /**
     * La dernière activité connue d'une partie (§ 10.5, E10-62) :
     * `min($now, max(game.started_at, game.paused_at, round.started_at +
     * round.duration_ms [status ≠ pending], round.reveal_ends_at,
     * round.cancelled_at))`.
     *
     * Instant de fin d'une partie bloquée reprise par 60 et de la clôture
     * forcée `stale_game` de 100 : une partie bloquée depuis treize mois que
     * l'on fermerait à « maintenant » repartirait pour douze mois de
     * conservation, et la durée publiée deviendrait fausse. Une manche
     * `pending` n'y entre pas par son origine, qui peut être une programmation
     * future jamais jouée ; `min($now, …)` borne une fin de révélation
     * programmée dans le futur.
     */
    public static function lastKnownActivity(Game $game, CarbonImmutable $now): CarbonImmutable
    {
        $latest = $game->started_at;
        $instants = [$game->paused_at];

        $rounds = Round::query()
            ->where('game_id', $game->id)
            ->get(['id', 'status', 'started_at', 'duration_ms', 'reveal_ends_at', 'cancelled_at']);

        foreach ($rounds as $round) {
            if ($round->status !== RoundStatus::Pending && $round->started_at !== null) {
                $instants[] = $round->started_at->addMilliseconds($round->duration_ms);
            }

            $instants[] = $round->reveal_ends_at;
            $instants[] = $round->cancelled_at;
        }

        foreach ($instants as $instant) {
            if ($instant !== null && $instant->greaterThan($latest)) {
                $latest = $instant;
            }
        }

        return $latest->lessThan($now) ? $latest : $now;
    }

    /**
     * Clôture d'office des manches non terminales (§ 10.3) :
     * - `revealing` → `completed` ;
     * - `running` → `completed` si `started_at + duration_ms ≤ $endedAt`, avec
     *   `round.ended_at` posé, s'il est nul, à `started_at + duration_ms` —
     *   l'instant théorique de clôture à `D` ; sinon `\LogicException` ;
     * - `pending` (réserve ou programmée) et `cancelled` : intactes.
     *
     * Toutes les gardes passent avant la première écriture.
     *
     * @throws LogicException
     */
    private static function closeOpenRounds(Game $lockedGame, CarbonImmutable $endedAt): void
    {
        $openRounds = Round::query()
            ->where('game_id', $lockedGame->id)
            ->whereIn('status', [RoundStatus::Running, RoundStatus::Revealing])
            ->orderBy('sequence_index')
            ->lockForUpdate()
            ->get();

        foreach ($openRounds as $round) {
            if ($round->status === RoundStatus::Running && self::durationEnd($round)->greaterThan($endedAt)) {
                throw new LogicException(sprintf(
                    'FinalizeGame : la manche %d court jusqu’à %s, après la fin de partie demandée (%s).',
                    $round->sequence_index,
                    self::durationEnd($round)->format('Y-m-d H:i:s.v'),
                    $endedAt->format('Y-m-d H:i:s.v'),
                ));
            }
        }

        foreach ($openRounds as $round) {
            if ($round->status === RoundStatus::Revealing) {
                $round->forceFill(['status' => RoundStatus::Completed])->save();

                continue;
            }

            $round->forceFill([
                'status' => RoundStatus::Completed,
                'ended_at' => $round->ended_at ?? self::durationEnd($round),
            ])->save();
        }
    }

    /**
     * Les cinq agrégats de chaque siège (§ 10.4), sous le filtre unique
     * `Settled`, classés par la chaîne de départage.
     */
    private static function freezeSeats(Game $lockedGame): void
    {
        $standings = Ranking::rank(
            Scoreboard::tallies($lockedGame, ScoreScope::Settled),
            $lockedGame->frames_per_round,
            $lockedGame->scoring_version,
        );

        $solo = $lockedGame->mode === GameMode::Solo;

        foreach ($standings as $standing) {
            $tally = $standing->tally;

            GamePlayer::query()->whereKey($tally->gamePlayerId)->update([
                'rounds_played' => $tally->roundsPlayed,
                'correct_answers' => $tally->correctAnswers,
                'final_score' => $tally->score,
                'total_answer_time_ms' => $tally->totalAnswerTimeMs,
                'final_rank' => $solo ? null : $standing->rank,
            ]);
        }
    }

    /**
     * Instant théorique de clôture d'une manche à `D`.
     *
     * @throws LogicException Manche sans origine de temps.
     */
    private static function durationEnd(Round $round): CarbonImmutable
    {
        if ($round->started_at === null) {
            throw new LogicException(sprintf(
                'FinalizeGame : la manche %d est %s sans origine de temps.',
                $round->sequence_index,
                $round->status->value,
            ));
        }

        return $round->started_at->addMilliseconds($round->duration_ms);
    }

    /**
     * Recopie sur l'instance de l'appelant ce que porte la partie gelée, sans
     * rien réécrire en base.
     */
    private static function reflect(Game $game, Game $lockedGame): void
    {
        $game->forceFill($lockedGame->only(self::FINALIZED_COLUMNS))
            ->syncOriginalAttributes(self::FINALIZED_COLUMNS);
    }
}
