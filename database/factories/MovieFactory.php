<?php

namespace Database\Factories;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Movie}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Movie>
 */
class MovieFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 1000000),
            'import_source' => ImportSource::Discover,
            'is_import_exception' => false,
            'exception_for_language' => false,
            'exception_for_vote_count' => false,
            'exception_for_release_year' => false,
            'title_original' => fake()->sentence(3),
            'original_language' => 'en',
            'vote_count' => 0,
            'adult' => false,
            'availability' => ContentAvailability::Draft,
            'content_flag' => ContentFlag::UnratedPending,
            'curation_active_seconds' => 0,
        ];
    }
}
