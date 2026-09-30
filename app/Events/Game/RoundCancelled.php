<?php

namespace App\Events\Game;

/**
 * `round.cancelled` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `CancelRound`.
 *
 * Charge, hors enveloppe : `{ sequenceIndex, roundNumber }` — aucun motif,
 * aucun titre.
 */
final class RoundCancelled extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'roundNumber' => 'int',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'round.cancelled';
    }
}
