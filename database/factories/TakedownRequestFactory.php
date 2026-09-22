<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\RequesterCapacity;
use App\Enums\TakedownStatus;
use App\Models\TakedownRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see TakedownRequest}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<TakedownRequest>
 */
class TakedownRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reference' => Str::upper(Str::random(12)),
            'status' => TakedownStatus::Received,
            'requester_name' => fake()->name(),
            'requester_email' => fake()->safeEmail(),
            'requester_capacity' => RequesterCapacity::RightsHolder,
            'locale' => Locale::French,
            'body' => fake()->paragraph(),
            'received_at' => now(),
        ];
    }
}
