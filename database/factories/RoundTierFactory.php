<?php

namespace Database\Factories;

use App\Enums\FrameLevel;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundTier}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<RoundTier>
 */
class RoundTierFactory extends Factory
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
            'round_id' => Round::factory(),
            'tier_index' => 1,
            'frame_level' => FrameLevel::Level1,
            'starts_at_offset_ms' => 0,
            'duration_ms' => $settings->tierDurations[0] * 1000,
            'points' => $settings->tierPoints[0],
        ];
    }
}
