<?php

namespace Database\Factories;

use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Guess}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Guess>
 */
class GuessFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $normalized = fake()->sentence(3);
        $points = RoomSettings::defaults()->tierPoints[0];

        return [
            'round_id' => Round::factory(),
            'player_id' => Player::factory(),
            'received_at' => now(),
            'answered_at_ms' => 1000,
            'tier_index' => 1,
            'lock_rank' => 1,
            'source' => GuessSource::Text,
            'match_kind' => GuessMatchKind::Title,
            'answer_key_normalized' => $normalized,
            'submitted_normalized' => $normalized,
            'edit_distance' => 0,
            'prefix_was_ambiguous' => false,
            'points_tier' => $points,
            'points_bonus' => 0,
            'points_total' => $points,
        ];
    }
}
