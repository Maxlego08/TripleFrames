<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieProjection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see MovieProjection}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<MovieProjection>
 */
class MovieProjectionFactory extends Factory
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
            'levels_mask' => 0,
            'levels_count' => 0,
            'level_1_variants' => 0,
            'level_2_variants' => 0,
            'level_3_variants' => 0,
            'level_4_variants' => 0,
            'level_5_variants' => 0,
            'variants_total' => 0,
            'title_locale_mask' => 0,
            'title_mask_version' => Locale::MASK_VERSION,
            'recomputed_at' => now(),
        ];
    }
}
