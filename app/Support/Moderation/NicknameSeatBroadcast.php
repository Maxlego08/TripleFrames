<?php

namespace App\Support\Moderation;

use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\CurrentGame;
use App\Support\Game\SeatViewPresenter;

/**
 * `seat.updated` après un geste de modération du pseudo (spec 20 § 11.5) :
 * l'identité re-sérialisée — masquée ou rendue — part au salon **si le salon
 * vit encore** (ni solo, ni archivé). Appelée dans la transaction du geste,
 * l'événement part après la validation (`ShouldDispatchAfterCommit`).
 */
final class NicknameSeatBroadcast
{
    public function dispatch(Player $seat): void
    {
        if ($seat->room_id === null) {
            return;
        }

        $room = Room::query()->find($seat->room_id);

        if ($room === null || $room->status === RoomStatus::Archived) {
            return;
        }

        SeatUpdated::dispatch($room, CurrentGame::of($seat), [
            'seat' => SeatViewPresenter::ofSeat($seat, CurrentGame::forState($seat), $room->host_player_id),
        ]);
    }
}
