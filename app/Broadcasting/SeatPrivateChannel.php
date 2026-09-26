<?php

namespace App\Broadcasting;

use App\Models\Player;
use App\Support\Realtime\SeatPrincipal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Autorisation du canal privé d'un siège, `private-seat.{publicId}` — spec 60
 * § 10.4, contrat C7 § 2.2.
 *
 * Exige `p.public_id = $publicId`, le même hash de jeton que la garde
 * `player`, `p.room_id` non nul, un salon non archivé et `p.kicked_at` nul.
 * Le `public_id` nomme le canal (E10-31), mais **n'autorise rien** : le
 * connaître — il est diffusé au salon — ne donne jamais accès au QCM ciblé
 * d'un autre siège, que seul le jeton qui tient ce siège peut écouter.
 */
final class SeatPrivateChannel
{
    public function join(SeatPrincipal $principal, string $publicId): bool
    {
        $seat = Player::query()
            ->where('public_id', $publicId)
            ->where('player_token_hash', $principal->tokenHash)
            ->whereNotNull('room_id')
            ->whereNull('kicked_at')
            ->whereHas('room', static fn (Builder $room) => $room->whereNull('archived_at'))
            ->first();

        if ($seat === null) {
            return false;
        }

        $principal->bindSeat($seat);

        return true;
    }
}
