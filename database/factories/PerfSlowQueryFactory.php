<?php

namespace Database\Factories;

use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see PerfSlowQuery} — une requête lente, à
 * paramètres (spec 10 § 7.11, D47 du 01/10).
 *
 * @extends Factory<PerfSlowQuery>
 */
class PerfSlowQueryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sql = 'select * from "movie" where "id" = ?';

        return [
            'perf_sample_id' => PerfSample::factory(),
            'sql_text' => $sql,
            'sql_hash' => sha1($sql),
            'duration_ms' => 250,
            'recorded_at' => now(),
        ];
    }
}
