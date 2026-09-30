<?php

namespace App\Events\Game;

/**
 * `seat.kicked` — canal du siège, ciblé (`private-seat.{publicId}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : expulsion (50), après commit : le client quitte ses canaux et
 * visite `room.show` (§ 11.8).
 *
 * Charge, hors enveloppe : `{}`.
 */
final class SeatKicked extends SeatBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [];

    public function broadcastAs(): string
    {
        return 'seat.kicked';
    }
}
