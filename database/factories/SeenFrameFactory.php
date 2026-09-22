<?php

namespace Database\Factories;

use App\Models\Frame;
use App\Models\Room;
use App\Models\SeenFrame;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see SeenFrame}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<SeenFrame>
 */
class SeenFrameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'frame_id' => Frame::factory(),
            'last_seen_at' => now(),
        ];
    }
}
