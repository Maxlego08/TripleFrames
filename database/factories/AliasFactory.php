<?php

namespace Database\Factories;

use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Une variante acceptée en réponse, à usage **exclusif de validation** (§ 3.4).
 *
 * Jamais affichée : un alias FR n'est jamais proposé comme titre EN à la
 * révélation. La validation ne passe jamais par cette table — elle passe par
 * `answer_key`, et un alias créé sans sa clé projetée n'est accepté nulle part.
 * C'est {@see MovieFactory::playable()} qui projette ; une fixture qui ajoute un
 * alias après coup rappelle {@see MovieFactory::recomputeProjection()} pour la
 * couverture de titres, et crée elle-même la clé par
 * `AnswerKey::factory()->alias(...)`.
 *
 * Aucune unicité ne porte sur le texte : en MySQL `utf8mb4_unicode_ci`
 * « Amelie » et « Amélie » violeraient une contrainte que SQLite BINARY
 * laisserait passer — le même test au vert d'un côté, au rouge de l'autre. Le
 * dédoublonnage réel est `unique(normalized, movie_id)` sur `answer_key`, donc
 * plusieurs alias par couple `(movie_id, locale)` sont NORMAUX ici.
 *
 * Un alias ne produit **jamais** de clé de nature `prefix` (décision 13), et la
 * translittération latine n'est pas un alias : elle vit sur
 * `movie.title_original_latin` (A4).
 *
 * @extends Factory<Alias>
 */
class AliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->words(2, true);

        return [
            'movie_id' => Movie::factory(),
            'locale' => Locale::French->value,
            'alias' => Str::title($words),
            'origin' => ContentOrigin::Curator,
            'created_by_id' => null,
        ];
    }

    /**
     * La locale de CATALOGUE de cet alias. Seuls les alias des locales
     * **activées** entrent dans `answer_key`.
     */
    public function forLocale(Locale|string $locale): static
    {
        return $this->state([
            'locale' => $locale instanceof Locale ? $locale->value : $locale,
        ]);
    }

    /**
     * Le texte accepté, tel quel.
     */
    public function accepting(string $alias): static
    {
        return $this->state(['alias' => $alias]);
    }

    /**
     * Un alias venu de TMDB (`alternative_titles`), écrasable au réimport.
     */
    public function tmdb(): static
    {
        return $this->state([
            'origin' => ContentOrigin::Tmdb,
            'created_by_id' => null,
        ]);
    }

    /**
     * Un alias curé — ou promu depuis `near_miss`, ce que `origin` ne distingue
     * pas : la promotion est un geste de curateur.
     */
    public function curatedBy(?User $curator = null): static
    {
        return $this->state([
            'origin' => ContentOrigin::Curator,
            'created_by_id' => $curator?->id,
        ]);
    }
}
