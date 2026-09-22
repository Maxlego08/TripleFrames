<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see Player}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'public_id' => Str::upper(Str::random(12)),
            'locale' => Locale::French,
            'joined_at' => now(),
            'connection_state' => PlayerConnectionState::Connected,
            'last_seen_at' => now(),
        ];
    }
}
