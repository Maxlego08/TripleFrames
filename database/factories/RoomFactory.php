<?php

namespace Database\Factories;

use App\Enums\RoomStatus;
use App\Models\Room;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see Room}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $settings = RoomSettings::defaults();
        $code = Str::upper(Str::random(6));

        return [
            'room_code' => $code,
            'room_code_active' => $code,
            'status' => RoomStatus::Lobby,
            'capacity' => $settings->capacity,
            'frames_per_round' => $settings->framesPerRound,
            'rounds_count' => $settings->roundsCount,
            'input_difficulty' => $settings->inputDifficulty,
            'allow_late_join' => $settings->allowLateJoin,
            'settings' => $settings,
            'last_activity_at' => now(),
        ];
    }
}
