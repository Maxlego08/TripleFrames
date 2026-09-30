<?php

namespace App\Actions\Room;

use App\Actions\Game\SeatInputClosed;
use App\Enums\GamePlayerStatus;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Game\CurrentGame;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ce que partagent l'expulsion ({@see KickSeat}) et le départ volontaire
 * ({@see LeaveRoom}) — spec 50 § 11.3 (étape 7), § 11.4 et § 8.2 ; 60 § 13.4,
 * contrat C7 § 2.5 et § 4.7. Un départ forcé a, sur les participants, l'effet
 * de tout départ (00 § Cycle de vie et cas limites). Le balayage de présence
 * de 60 (`SweepSeatPresence`, § 13.2) s'en sert aussi : un siège qui passe
 * `left` faute de battement marque sa participation comme un départ, et
 * toute sortie des participants — `disconnected` compris — réévalue la fin
 * anticipée avec l'instant de transition écrit.
 *
 * - {@see self::markParticipation()} — **dans la transaction du geste**, salon
 *   puis siège déjà verrouillés : la dernière partie du salon est relue en
 *   `lockForUpdate` APRÈS le verrou du siège (ordre global `room → player →
 *   game`, E10-51). Si son `ended_at` est NULL sous ce verrou, la
 *   participation du siège prend l'issue du geste (`kicked` ou `left`), par
 *   mise à jour ciblée, **points conservés** : aucune ligne `guess`, aucun
 *   agrégat n'est touché — les partis et les expulsés restent classés (80).
 *   Sinon, rien : l'issue figée par `FinalizeGame`, qui gèle sous le verrou
 *   de `game` (80 § 10), n'est jamais réécrite, et sans ce verrou un gel
 *   concurrent validé entre la lecture de `ended_at` et l'écriture serait
 *   réécrit après coup.
 * - {@see self::reevaluateAfterCommit()} — **après la validation** de la
 *   transaction du geste : la manche `running` de la partie en cours où le
 *   siège a une ligne `round_player`, s'il y en a une, est reprise
 *   `FOR UPDATE` dans une SECONDE transaction, puis
 *   `SeatInputClosed::handle($round, $roundPlayer, null, $leftAt)` — `$leftAt`
 *   étant le `left_at` écrit (pour le balayage de présence, l'instant de sa
 *   transition : `disconnected_at` ou `left_at`), jamais l'heure
 *   d'exécution. Le geste de 50 ne tient donc jamais le verrou de manche
 *   dans sa propre transaction, et le moteur réévalue la fin anticipée sous
 *   le sien. Aucun événement de domaine
 *   n'est ajouté ; avec `$guess` nul, aucun `player.locked` n'est émis.
 */
final readonly class SeatDeparture
{
    public function __construct(private SeatInputClosed $inputClosed) {}

    /**
     * L'issue du geste sur la participation du siège à la partie en cours du
     * salon — la dernière partie, relue sous son verrou.
     *
     * @throws LogicException Transaction absente : l'appelant tient le salon
     *                        et le siège sous verrou.
     */
    public function markParticipation(Room $lockedRoom, Player $lockedSeat, GamePlayerStatus $status): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SeatDeparture : une transaction doit être ouverte par l’appelant, salon et siège verrouillés.');
        }

        $game = Game::query()
            ->where('room_id', $lockedRoom->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($game === null || $game->ended_at !== null) {
            return;
        }

        GamePlayer::query()
            ->whereBelongsTo($game)
            ->where('player_id', $lockedSeat->id)
            ->update(['status' => $status->value]);
    }

    /**
     * La réévaluation de la fin anticipée, inscrite pour s'exécuter après la
     * validation de la transaction la plus externe — et après les diffusions
     * du geste, inscrites avant elle. Une transaction annulée ne réévalue
     * rien.
     */
    public function reevaluateAfterCommit(Player $seat, CarbonImmutable $leftAt): void
    {
        DB::afterCommit(fn () => $this->reevaluate($seat, $leftAt));
    }

    /**
     * La seconde transaction : la manche `running` de la partie en cours où
     * le siège a une participation, reprise `FOR UPDATE`, puis le crochet du
     * moteur. Rien sans partie en cours, sans manche `running`, ou sans ligne
     * `round_player` du siège (un siège entré pendant la manche n'en a pas).
     */
    private function reevaluate(Player $seat, CarbonImmutable $leftAt): void
    {
        $game = CurrentGame::of($seat);

        if ($game === null) {
            return;
        }

        $round = Round::query()
            ->where('game_id', $game->id)
            ->where('status', RoundStatus::Running->value)
            ->first();

        if ($round === null) {
            return;
        }

        $participation = RoundPlayer::query()
            ->where('round_id', $round->id)
            ->where('player_id', $seat->id)
            ->first();

        if ($participation === null) {
            return;
        }

        DB::transaction(function () use ($round, $participation, $leftAt): void {
            $lockedRound = Round::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();

            $this->inputClosed->handle($lockedRound, $participation, null, $leftAt);
        });
    }
}
