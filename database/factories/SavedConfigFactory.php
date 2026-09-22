<?php

namespace Database\Factories;

use App\Models\SavedConfig;
use App\Models\User;
use App\Settings\RoomSettings;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fabrique de test de {@see SavedConfig}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<SavedConfig>
 */
class SavedConfigFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->word().' '.fake()->word();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'name_normalized' => Str::lower($name),
            'settings' => RoomSettings::defaults(),
        ];
    }
}
