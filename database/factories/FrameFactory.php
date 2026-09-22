<?php

namespace Database\Factories;

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Models\Frame;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see Frame}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Frame>
 */
class FrameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movie_id' => Movie::factory(),
            'frame_level' => FrameLevel::Level3,
            'availability' => ContentAvailability::Draft,
            'processing_state' => FrameProcessingState::Pending,
            'source_kind' => FrameSourceKind::Tmdb,
            'source_hash' => hash('sha256', Str::random(32)),
            'crop_x' => 0,
            'crop_y' => 0,
            'crop_width' => 1280,
            'crop_height' => 720,
        ];
    }
}
