<?php

namespace Database\Factories;

use App\Models\TmdbCompany;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Le nom d'une société TMDB (spec 10 § 3.6 bis) : un identifiant public et un
 * nom inventé, jamais un extrait de base de production.
 *
 * @extends Factory<TmdbCompany>
 */
class TmdbCompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->unique()->words(2, true);

        return [
            'tmdb_id' => fake()->unique()->numberBetween(1, 9_000_000),
            'name' => Str::title($words).' Pictures',
        ];
    }

    /**
     * Une société nommée, désignée par son identifiant TMDB — celui que porte
     * `movie_tmdb_tag.tmdb_tag_id` d'une étiquette `company`.
     */
    public function named(int $tmdbId, string $name): static
    {
        return $this->state([
            'tmdb_id' => $tmdbId,
            'name' => $name,
        ]);
    }
}
