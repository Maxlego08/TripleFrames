<?php

namespace Database\Factories;

use App\Models\PerfSample;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see PerfSample} — une requête HTTP mesurée
 * (spec 10 § 7.11, D47 du 01/10).
 *
 * @extends Factory<PerfSample>
 */
class PerfSampleFactory extends Factory
{
    /**
     * Une requête GET réussie sur l'accueil, rapide.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => PerfSample::KIND_REQUEST,
            'name' => 'home',
            'method' => 'GET',
            'queue' => null,
            'status' => '200',
            'duration_ms' => 40,
            'query_count' => 3,
            'query_ms' => 5,
            'memory_kb' => 8_192,
            'wait_ms' => null,
            'recorded_at' => now(),
        ];
    }

    /** Une requête sur la route `$name`, de durée `$durationMs`. */
    public function forRoute(string $name, int $durationMs, string $status = '200'): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PerfSample::KIND_REQUEST,
            'name' => $name,
            'duration_ms' => $durationMs,
            'status' => $status,
        ]);
    }

    /** Un job de la classe `$class`, sur la file `$queue`. */
    public function forJob(string $class, int $durationMs, string $queue = 'default', bool $failed = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => PerfSample::KIND_JOB,
            'name' => $class,
            'method' => null,
            'queue' => $queue,
            'status' => $failed ? PerfSample::STATUS_FAILED : PerfSample::STATUS_PROCESSED,
            'duration_ms' => $durationMs,
            'wait_ms' => 10,
        ]);
    }
}
