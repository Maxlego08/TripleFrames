<?php

namespace Database\Factories;

use App\Models\AudiencePresence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see AudiencePresence} — un visiteur présent
 * (spec 10 § 7.12, D48 du 01/10).
 *
 * @extends Factory<AudiencePresence>
 */
class AudiencePresenceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'visitor_hash' => bin2hex(random_bytes(8)),
            'route' => 'home',
            'last_seen_at' => now(),
        ];
    }
}
