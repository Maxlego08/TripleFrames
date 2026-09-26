<?php

namespace App\Events\Game;

/**
 * `seat.joined` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : prise de siège (50) ; admission d'un retardataire (50).
 *
 * Charge, hors enveloppe : `{ seat: SeatView }` :
 * `PlayerIdentity::fromSeat()` au lobby, `fromGamePlayer()` en partie (C5),
 * plus `isHost`, `connection`, `kicked` et `firstRoundNumber` (§ 11.5).
 */
final class SeatJoined extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'seat' => 'object',
    ];

    public function broadcastAs(): string
    {
        return 'seat.joined';
    }
}
