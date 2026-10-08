<?php

namespace App\Events\Game;

/**
 * `game.pause_requested` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, D64 du 07/10.
 *
 * Émetteur : `RequestGamePause` pendant une manche jouée (programmée et `T₁`
 * franchi, en cours ou en révélation) : la pause prendra effet à la fin de
 * la révélation de cette manche (`game.paused` `kind: manual`).
 *
 * Charge, hors enveloppe : `{ requestedAt }`.
 */
final class GamePauseRequested extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'requestedAt' => 'iso',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.pause_requested';
    }
}
