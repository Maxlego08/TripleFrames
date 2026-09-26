<?php

namespace App\Events\Game;

/**
 * `round.closed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `CloseRound`, à `D` ou à la fin anticipée.
 *
 * Charge, hors enveloppe : `{ sequenceIndex, roundNumber, endedAt,
 * revealStartsAt, revealEndsAt }` : `revealStartsAt` = `ended_at +
 * tier_grace_ms` (§ 11.5).
 */
final class RoundClosed extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'roundNumber' => 'int',
        'endedAt' => 'iso',
        'revealStartsAt' => 'iso',
        'revealEndsAt' => 'iso',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'round.closed';
    }
}
