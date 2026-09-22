<?php

namespace Database\Factories;

use App\Enums\FrameSourceKind;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Support\Curation\ExclusionGrid;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see FrameReview}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<FrameReview>
 */
class FrameReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'frame_id' => Frame::factory(),
            'reviewer_name' => fake()->name(),
            'reviewer_role' => UserRole::Curator,
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
            'decision' => ReviewDecision::Passed,
            'reviewed_hash' => hash('sha256', Str::random(32)),
            'declared_source_kind' => FrameSourceKind::Tmdb,
            'answers' => array_fill_keys(ExclusionGrid::slugs(), true),
            'reviewed_at' => now(),
        ];
    }
}
