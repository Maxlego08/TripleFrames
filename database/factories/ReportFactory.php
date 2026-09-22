<?php

namespace Database\Factories;

use App\Enums\ReportTarget;
use App\Models\Player;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Report}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target_type' => ReportTarget::Nickname,
            'reporter_player_id' => Player::factory(),
        ];
    }
}
