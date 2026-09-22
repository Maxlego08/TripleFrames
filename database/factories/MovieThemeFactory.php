<?php

namespace Database\Factories;

use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see MovieTheme}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<MovieTheme>
 */
class MovieThemeFactory extends Factory
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
            'theme_id' => Theme::factory(),
            'is_auto' => false,
            'is_active' => false,
        ];
    }
}
