<?php

namespace App\Events\Game;

/**
 * `host.changed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : transfert d'hôte (50).
 *
 * Charge, hors enveloppe : `{ hostPublicId: string, previousHostPublicId:
 * string | null }`.
 */
final class HostChanged extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'hostPublicId' => 'string',
        'previousHostPublicId' => '?string',
    ];

    public function broadcastAs(): string
    {
        return 'host.changed';
    }
}
