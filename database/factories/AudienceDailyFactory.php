<?php

namespace Database\Factories;

use App\Models\AudienceDaily;
use App\Support\Audience\AudienceRecorder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see AudienceDaily} — un compteur du jour
 * (spec 10 § 7.12, D48 du 01/10).
 *
 * @extends Factory<AudienceDaily>
 */
class AudienceDailyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'day' => now()->toDateString(),
            'metric' => AudienceRecorder::METRIC_PAGEVIEWS,
            'dimension' => 'home',
            'total' => 1,
        ];
    }

    /** Le compteur `$metric` / `$dimension` du jour `$day`. */
    public function counter(string $day, string $metric, string $dimension, int $total): static
    {
        return $this->state(fn (array $attributes): array => [
            'day' => $day,
            'metric' => $metric,
            'dimension' => $dimension,
            'total' => $total,
        ]);
    }
}
