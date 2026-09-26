<?php

namespace App\Events\Game;

/**
 * `seat.updated` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : transition de présence (60) ; expulsion (50) ; masquage (40,
 * J2).
 *
 * Charge, hors enveloppe : `{ seat: SeatView }` (§ 11.5).
 */
final class SeatUpdated extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'seat' => 'object',
    ];

    public function broadcastAs(): string
    {
        return 'seat.updated';
    }
}
