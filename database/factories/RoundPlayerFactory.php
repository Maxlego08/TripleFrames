<?php

namespace Database\Factories;

use App\Enums\RoundPlayerInputState;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundPlayer}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<RoundPlayer>
 */
class RoundPlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'player_id' => Player::factory(),
            'input_state' => RoundPlayerInputState::Open,
            'wrong_attempts' => 0,
        ];
    }
}
