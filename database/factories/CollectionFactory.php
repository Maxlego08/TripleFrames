<?php

namespace Database\Factories;

use App\Models\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * La saga TMDB (§ 3.3) — « même saga », jamais « même œuvre », qui est le rôle
 * de `movie_group`. Confondre les deux interdirait deux Star Wars dans une
 * partie, exactement le contraire du produit.
 *
 * Cardinalité TMDB 0..1, donc `movie.collection_id` et **aucun pivot**. `name`
 * n'est pas localisé : une collection n'est jamais affichée à un joueur, ce sont
 * le thème de saga et ses `theme_label` qui le sont. Deux consommations
 * seulement — `rule_value` d'un thème de kind `saga` (une dizaine de sagas
 * retenues à la main, jamais « une collection = un thème ») et pondération de
 * `movie.movie_difficulty_derived`.
 *
 * @extends Factory<Collection>
 */
class CollectionFactory extends Factory
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
            'tmdb_id' => fake()->unique()->numberBetween(1, 900_000),
            'name' => Str::title($words).' Collection',
        ];
    }

    /**
     * Une saga nommée — celle que `theme.rule_value` désigne par son
     * `collection_id`.
     */
    public function named(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    /**
     * Une saga de démonstration : `tmdb_id` nul, comme ses films. La colonne
     * étant nullable, l'UNIQUE `collection_tmdb_uq` laisse coexister autant de
     * lignes nulles que nécessaire — les deux moteurs ignorent les NULL dans un
     * UNIQUE (§ 1.4).
     */
    public function demo(): static
    {
        return $this->state(['tmdb_id' => null]);
    }
}
