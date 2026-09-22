<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Theme;
use App\Models\ThemeLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see ThemeLabel}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<ThemeLabel>
 */
class ThemeLabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theme_id' => Theme::factory(),
            'locale' => Locale::French,
            'label' => fake()->sentence(2),
        ];
    }
}
