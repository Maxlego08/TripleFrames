<?php

namespace App\Broadcasting;

use App\Models\Player;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\SeatPrincipal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Autorisation du canal de présence d'un salon, `presence-room.{roomKey}` —
 * spec 60 § 10.4, contrat C7 § 2.2.
 *
 * Accepte s'il existe un siège `p` tel que :
 * - `p.player_token_hash` = hash du jeton courant (garde `player`, C4 I4.9) ;
 * - `p.room_id` non nul (aucun canal en solo, 10 § 7.10) ;
 * - salon non archivé (`room.archived_at` nul) ;
 * - `p.kicked_at` nul (D15 du 23/09 : refusé jusqu'à l'archivage) ;
 * - `hash_equals(ChannelNames::roomKey(p.room), $roomKey)`.
 *
 * **Jamais par le `room_code`** : il est recyclé à l'archivage (10 § 6.2), la
 * clé HMAC ne l'est pas. Le hash n'a pas d'index sur la clé de canal, qui
 * n'est pas une colonne (E10-31) : les sièges non archivés tenus par ce jeton
 * — un par salon au plus, `player_room_token_uq` — sont relus, et la clé de
 * chacun comparée en temps constant.
 *
 * L'état de connexion est indifférent : la présence Reverb ne fait jamais foi
 * (C7 § 4.10), seuls les battements HTTP écrivent la présence.
 *
 * **Membre de présence** : `user_id` = `public_id` (par
 * {@see SeatPrincipal::getAuthIdentifierForBroadcasting()}, d'où
 * `bindSeat()`), `user_info` = `{ publicId }`, rien d'autre.
 */
final class RoomPresenceChannel
{
    /**
     * @return array{publicId: string}|false
     */
    public function join(SeatPrincipal $principal, string $roomKey): array|false
    {
        $seats = Player::query()
            ->where('player_token_hash', $principal->tokenHash)
            ->whereNotNull('room_id')
            ->whereNull('kicked_at')
            ->whereHas('room', static fn (Builder $room) => $room->whereNull('archived_at'))
            ->with('room:id')
            ->get();

        foreach ($seats as $seat) {
            if ($seat->room !== null && hash_equals(ChannelNames::roomKey($seat->room), $roomKey)) {
                $principal->bindSeat($seat);

                return ['publicId' => $seat->public_id];
            }
        }

        return false;
    }
}
