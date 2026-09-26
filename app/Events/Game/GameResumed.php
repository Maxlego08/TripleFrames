<?php

namespace App\Events\Game;

/**
 * `game.resumed` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : retour d'un siège pendant la pause ; suivi de
 * `round.scheduled`.
 *
 * Charge, hors enveloppe : `{ resumedAt }`.
 */
final class GameResumed extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'resumedAt' => 'iso',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.resumed';
    }
}
