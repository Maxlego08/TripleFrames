<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\GameTrace;
use App\Support\Perf\GameTraceWriter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see GameTrace} — une diffusion de frontière et son
 * retard (spec 10 § 7.11, D47 du 01/10).
 *
 * @extends Factory<GameTrace>
 */
class GameTraceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'sequence_index' => 1,
            'tier_index' => 1,
            'event' => GameTraceWriter::BROADCAST_PREFIX.'tier.opened',
            'player_id' => null,
            'theoretical_at' => now()->subMilliseconds(40),
            'recorded_at' => now(),
            'delay_ms' => 40,
            'duration_ms' => null,
            'query_count' => null,
            'details' => null,
        ];
    }

    /** Diffusion `$event` de la partie, avec ce retard. */
    public function broadcast(Game $game, string $event, int $delayMs): static
    {
        return $this->state(fn (array $attributes): array => [
            'game_id' => $game->id,
            'event' => GameTraceWriter::BROADCAST_PREFIX.$event,
            'delay_ms' => $delayMs,
        ]);
    }
}
