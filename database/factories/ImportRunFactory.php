<?php

namespace Database\Factories;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see ImportRun}.
 *
 * Elle alimente EXACTEMENT les colonnes `NOT NULL` de la migration — celles sans
 * défaut comme celles qui en portent un, reprises à l'identique — et rien de
 * plus. Aucun état « intéressant » n'est posé ici : les états de domaine
 * appartiennent aux specs qui les possèdent, pas à une fabrique.
 *
 * @extends Factory<ImportRun>
 */
class ImportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_kind' => ImportRunKind::Discover,
            'status' => ImportRunStatus::Running,
            'is_widened' => false,
            'total_seen' => 0,
            'total_imported' => 0,
            'total_skipped' => 0,
            'total_refused_content' => 0,
        ];
    }
}
