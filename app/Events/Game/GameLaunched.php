<?php

namespace App\Events\Game;

/**
 * `game.launched` — canal du salon, diffusé (`presence-room.{roomKey}`).
 * Spec 60 § 11.3, contrat C7 § 2.3.
 *
 * Émetteur : transaction de lancement (50), après commit.
 *
 * Charge, hors enveloppe : `{ mode: 'multiplayer', roundsCount,
 * framesPerRound, inputDifficulty, revealDurationMs, speedBonus, seats:
 * SeatView[] }` : colonnes figées de `game`, `revealDurationMs` =
 * `settings_snapshot->revealDuration × 1000`, `seats` par `game_player.id`
 * croissant (§ 11.5).
 */
final class GameLaunched extends RoomBroadcast
{
    /** @var array<string, string> */
    public const array FIELDS = [
        'mode' => 'string',
        'roundsCount' => 'int',
        'framesPerRound' => 'int',
        'inputDifficulty' => 'string',
        'revealDurationMs' => 'int',
        'speedBonus' => 'bool',
        'seats' => 'list',
    ];

    /** Événement de partie : jamais émis sans partie. */
    public const bool GAME_BOUND = true;

    public function broadcastAs(): string
    {
        return 'game.launched';
    }
}
