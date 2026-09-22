<?php

namespace Database\Factories;

use App\Enums\GameMode;
use App\Enums\GameStatus;
use App\Models\Game;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Game}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $settings = RoomSettings::defaults();

        return [
            'mode' => GameMode::Multiplayer,
            'status' => GameStatus::Running,
            'input_difficulty' => $settings->inputDifficulty,
            'rounds_count' => $settings->roundsCount,
            'frames_per_round' => $settings->framesPerRound,
            'rounds_completed' => 0,
            'draw_seed' => bin2hex(random_bytes(32)),
            'draw_pool_size' => $settings->roundsCount,
            'tier_grace_ms' => PlatformLimits::tierGraceMs(),
            'preload_lead_ms' => PlatformLimits::preloadLeadMs(),
            'settings_snapshot' => $settings,
            'scoring_version' => 1,
            'validation_version' => 1,
            'started_at' => now(),
            'total_paused_ms' => 0,
        ];
    }
}
