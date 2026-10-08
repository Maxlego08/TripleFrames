<?php

namespace App\Events\Game;

/**
 * `game.paused` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteurs : `EndReveal` sans siège présent (écart (b) du § 22 bis) ou sur
 * demande de pause manuelle ; `RequestGamePause` entre deux manches (D64 du
 * 07/10).
 *
 * Charge, hors enveloppe : `{ pausedAt, interruptsAt, kind }` :
 * `interruptsAt` = échéance de la pause (`PauseDeadline`, § 11.5), `kind` =
 * `empty` ou `manual`.
 */
final class GamePaused extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'pausedAt' => 'iso',
        'interruptsAt' => 'iso',
        'kind' => 'string',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.paused';
    }
}
