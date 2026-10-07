<?php

namespace App\Events\Game;

/**
 * `game.pause_request_cancelled` — canal du salon, diffusé
 * (`presence-room.{roomKey}`). Spec 60 § 11.3, D64 du 07/10.
 *
 * Émetteur : `CancelPauseRequest`, l'hôte retirant une demande de pause pas
 * encore effective.
 *
 * Charge, hors enveloppe : `{}`.
 */
final class GamePauseRequestCancelled extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.pause_request_cancelled';
    }
}
