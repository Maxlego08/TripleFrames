<?php

namespace App\Events\Game;

/**
 * `player.locked` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `SeatInputClosed`, sur `AnswerAccepted` (70).
 *
 * Charge, hors enveloppe : `{ sequenceIndex, publicId, lockRank }`, rien
 * d'autre : ni points, ni `tier_index`, ni chaîne avant la révélation (§
 * 11.7).
 */
final class PlayerLocked extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'sequenceIndex' => 'int',
        'publicId' => 'string',
        'lockRank' => 'int',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'player.locked';
    }
}
