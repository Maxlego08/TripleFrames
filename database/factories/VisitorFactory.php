<?php

namespace Database\Factories;

use App\Models\Visitor;
use App\Support\Visitor\ConsentCookie;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fabrique de test de {@see Visitor} — un visiteur consentant (spec 10
 * § 7.1 bis, D62 du 06/10).
 *
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'consent_version' => ConsentCookie::VERSION,
            'consented_at' => $now,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ];
    }
}
