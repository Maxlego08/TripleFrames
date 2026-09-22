<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Models\Round;
use App\Models\RoundChoiceSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see RoundChoiceSet}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<RoundChoiceSet>
 */
class RoundChoiceSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'round_id' => Round::factory(),
            'locale' => Locale::French,
            'choice_1' => fake()->sentence(3),
            'choice_2' => fake()->sentence(3),
            'choice_3' => fake()->sentence(3),
            'choice_4' => fake()->sentence(3),
            'composed_at' => now(),
        ];
    }
}
