<?php

namespace Database\Factories;

use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Le titre **affichable** d'un film dans une locale de catalogue (§ 3.4).
 *
 * `locale` est une locale de CATALOGUE `string(12)` : jamais castée par
 * `App\Enums\Locale`, parce qu'elle doit accepter `ja`, `ko`, `zh-Hant` que la
 * voie d'exception fait entrer. Les states nommés prennent donc une chaîne,
 * jamais un cas d'enum — mais seules les locales **activées** entrent dans
 * `answer_key` et dans `movie_projection.title_locale_mask`.
 *
 * **L'absence d'une ligne EST l'information** : aucun titre n'est jamais recopié
 * d'une langue vers une autre, ni à l'import, ni à la curation, ni au tirage.
 * Une fixture qui « remplirait les trous » rendrait la file « titres manquants »
 * vide et le repli d'affichage de `05` intestable.
 *
 * Aucune forme normalisée ici : `answer_key` en est l'unique propriétaire.
 * L'UNIQUE `movie_title_movie_locale_uq (movie_id, locale)` interdit deux
 * titres pour un même couple — un `->count(2)` sans `->sequence()` de locales
 * échoue, et c'est le comportement voulu.
 *
 * @extends Factory<MovieTitle>
 */
class MovieTitleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $words */
        $words = fake()->words(3, true);

        return [
            'movie_id' => Movie::factory(),
            'locale' => Locale::English->value,
            'title' => Str::title($words),
            'origin' => ContentOrigin::Tmdb,
            'edited_by_id' => null,
        ];
    }

    /**
     * La locale de catalogue de ce titre.
     */
    public function forLocale(Locale|string $locale): static
    {
        return $this->state([
            'locale' => $locale instanceof Locale ? $locale->value : $locale,
        ]);
    }

    /**
     * Le texte affiché, tel quel.
     */
    public function titled(string $title): static
    {
        return $this->state(['title' => $title]);
    }

    /**
     * Une ligne importée de TMDB — réécrite sans discussion par une
     * resynchronisation.
     */
    public function tmdb(): static
    {
        return $this->state([
            'origin' => ContentOrigin::Tmdb,
            'edited_by_id' => null,
        ]);
    }

    /**
     * Une correction de curateur. `origin = curator` est ce qui empêche une
     * resynchronisation d'écraser silencieusement le travail quotidien du
     * curateur ; sans elle, la correction disparaît au réimport suivant.
     */
    public function curated(?User $editor = null): static
    {
        return $this->state([
            'origin' => ContentOrigin::Curator,
            'edited_by_id' => $editor?->id,
        ]);
    }
}
