<?php

namespace App\Events\Game;

/**
 * `tier.opened` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `OpenTier` à `Tᵢ`, `i` = 1..N.
 *
 * Charge, hors enveloppe : `{ sequenceIndex, roundNumber, tierIndex,
 * opensAt: IsoMs, next: TierImageRef | null }` : `opensAt` = `Tᵢ`
 * théorique, `next` = palier `i+1` frappé dans la même transition, nul au
 * dernier palier (§ 11.5).
 */
final class TierOpened extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'roundNumber' => 'int',
        'tierIndex' => 'int',
        'opensAt' => 'iso',
        'next' => '?object',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'tier.opened';
    }
}
