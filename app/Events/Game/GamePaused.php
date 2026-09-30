<?php

namespace App\Events\Game;

/**
 * `game.paused` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : `EndReveal` sans siège présent (écart (b) du § 22 bis).
 *
 * Charge, hors enveloppe : `{ pausedAt, interruptsAt }` : `interruptsAt` =
 * `paused_at + pauseTimeoutMs` (§ 11.5).
 */
final class GamePaused extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'pausedAt' => 'iso',
        'interruptsAt' => 'iso',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.paused';
    }
}
