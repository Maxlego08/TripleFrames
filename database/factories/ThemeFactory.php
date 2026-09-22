<?php

namespace Database\Factories;

use App\Enums\ThemeKind;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see Theme}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'theme.'.Str::lower(Str::random(16)),
            'theme_kind' => ThemeKind::Genre,
            'rule_value' => 'genre.'.Str::lower(Str::random(8)),
            'rule_negated' => false,
            'is_published' => false,
            'sort_order' => 0,
        ];
    }
}
