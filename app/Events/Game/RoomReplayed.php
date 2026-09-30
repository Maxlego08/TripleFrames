<?php

namespace App\Events\Game;

/**
 * `room.replayed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `ReplayRoom` (50), après commit : le salon repasse au lobby,
 * sans navigation.
 *
 * Charge, hors enveloppe : `RoomSettingsState` recalculé (contrat C0 §
 * 3.4).
 */
final class RoomReplayed extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'settings' => 'object',
        'warnings' => 'list',
        'pool' => 'object',
    ];

    public function broadcastAs(): string
    {
        return 'room.replayed';
    }
}
