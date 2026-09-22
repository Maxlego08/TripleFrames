<?php

namespace Database\Factories;

use App\Enums\AnswerKeyKind;
use App\Models\AnswerKey;
use App\Models\Movie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see AnswerKey}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<AnswerKey>
 */
class AnswerKeyFactory extends Factory
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
            'key_kind' => AnswerKeyKind::Title,
            'source_locale' => 'fr',
            'normalized' => fake()->sentence(3),
            'is_ambiguous' => false,
        ];
    }
}
