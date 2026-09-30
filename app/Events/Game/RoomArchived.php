<?php

namespace App\Events\Game;

/**
 * `room.archived` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : archivage (50). Le client quitte ses canaux et visite
 * `room.show` (§ 11.8).
 *
 * Charge, hors enveloppe : `{}`.
 */
final class RoomArchived extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [];

    public function broadcastAs(): string
    {
        return 'room.archived';
    }
}
