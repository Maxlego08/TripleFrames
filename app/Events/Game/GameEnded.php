<?php

namespace App\Events\Game;

/**
 * `game.ended` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : écouteur de `GameFinalized` (contrat C13), après le gel.
 *
 * Charge, hors enveloppe : `{ podium: Podium }` : issue, manches jouées et
 * prévues sont dans `Podium` (§ 11.5).
 */
final class GameEnded extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'podium' => 'object',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.ended';
    }
}
