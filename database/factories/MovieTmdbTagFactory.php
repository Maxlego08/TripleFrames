<?php

namespace Database\Factories;

use App\Enums\TmdbTagKind;
use App\Models\Movie;
use App\Models\MovieTmdbTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see MovieTmdbTag}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<MovieTmdbTag>
 */
class MovieTmdbTagFactory extends Factory
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
            'tag_kind' => TmdbTagKind::Genre,
            'tmdb_tag_id' => fake()->numberBetween(1, 100000),
        ];
    }
}
