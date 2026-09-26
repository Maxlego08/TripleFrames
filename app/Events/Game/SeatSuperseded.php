<?php

namespace App\Events\Game;

/**
 * `seat.superseded` — canal du siège, ciblé (`private-seat.{publicId}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `ClaimSeatTab`, si un jeton de siège actif précédent
 * existait : l'onglet supplanté se resynchronise et passe en lecture seule
 * (§ 12.7).
 *
 * Charge, hors enveloppe : `{}`.
 */
final class SeatSuperseded extends SeatBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [];

    public function broadcastAs(): string
    {
        return 'seat.superseded';
    }
}
