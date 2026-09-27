<?php

namespace App\Actions\Room;

use App\Enums\GamePlayerStatus;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Un joueur quitte le salon — départ volontaire (spec 50 § 11.4). Chaque
 * joueur peut quitter, hôte compris (§ 11.1). Une transaction, sous le verrou
 * du salon puis celui du siège, `$now` pris après les verrous (ordre global
 * `room → player → game`, E10-51) :
 *
 * - `connection_state = left`, `left_at = $now` — le siège libère sa place
 *   (`holdingSeat()`) ;
 * - dernière partie du salon relue en `lockForUpdate` après le verrou du
 *   siège, comme à l'étape 7 de {@see KickSeat} : en cours, la participation
 *   passe `left`, points conservés ; figée, rien
 *   ({@see SeatDeparture::markParticipation()}) ;
 * - si c'était l'hôte, {@see TransferHost::automatic()} : le rôle passe au
 *   siège connecté le plus ancien, à défaut au déconnecté le plus ancien, à
 *   défaut personne ;
 * - `room.last_activity_at = $now` (source d'activité, § 16.1).
 *
 * **Idempotent** : un siège déjà parti (ou expulsé) n'est pas réécrit, son
 * `left_at` reste l'instant du premier départ. Un salon archivé n'est pas
 * touché.
 *
 * **Après la validation** : `host.changed` le cas échéant (émis par
 * {@see TransferHost}), puis `seat.updated`, dont la vue porte déjà le nouvel
 * hôte — le partant n'y est plus `isHost`, quel que soit l'ordre dans lequel
 * le client applique les deux messages ; puis la réévaluation de la fin
 * anticipée de la manche en cours, dans une seconde transaction
 * ({@see SeatDeparture::reevaluateAfterCommit()}).
 *
 * Le partant peut revenir par le lien : c'est une reprise (§ 7.3, S3), dont
 * l'effet en partie appartient à `60` (retour par le battement de présence).
 */
final readonly class LeaveRoom
{
    public function __construct(
        private SeatDeparture $departure,
        private TransferHost $transferHost,
    ) {}

    /**
     * @param  Player  $seat  Siège du demandeur, résolu par son `player_token`.
     *
     * @throws ModelNotFoundException Le siège n'appartient pas à ce salon.
     */
    public function handle(Room $room, Player $seat): void
    {
        DB::transaction(function () use ($room, $seat): void {
            // Verrou du salon, puis du siège, puis l'instant.
            $locked = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $lockedSeat = Player::query()
                ->whereKey($seat->id)
                ->whereBelongsTo($locked)
                ->lockForUpdate()
                ->firstOrFail();
            $now = Date::now()->toImmutable()->startOfMillisecond();

            if ($locked->status === RoomStatus::Archived
                || $lockedSeat->wasKicked()
                || $lockedSeat->connection_state === PlayerConnectionState::Left) {
                return;
            }

            $lockedSeat->forceFill([
                'connection_state' => PlayerConnectionState::Left,
                'left_at' => $now,
            ])->save();

            $this->departure->markParticipation($locked, $lockedSeat, GamePlayerStatus::Left);

            if ($locked->host_player_id === $lockedSeat->id) {
                $this->transferHost->automatic($locked, $now);
            }

            Room::query()->whereKey($locked->id)->update(['last_activity_at' => $now]);

            SeatUpdated::dispatch($locked, CurrentGame::of($lockedSeat), [
                'seat' => SeatViewPresenter::ofSeat($lockedSeat, CurrentGame::forState($lockedSeat), $locked->host_player_id),
            ]);

            $this->departure->reevaluateAfterCommit($lockedSeat, $now);
        });
    }
}
