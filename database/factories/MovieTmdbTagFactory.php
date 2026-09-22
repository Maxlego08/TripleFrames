<?php

namespace Database\Factories;

use App\Enums\TmdbTagKind;
use App\Models\Movie;
use App\Models\MovieTmdbTag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * L'étiquette TMDB brute (§ 3.6), **seul support local** des règles de genre et
 * de studio.
 *
 * Sans elle, deux des cinq discriminants de thème n'ont aucune donnée locale :
 * la décennie se lit sur `movie.release_year`, la langue sur
 * `movie.original_language`, la saga sur `movie.collection_id`, la difficulté
 * sur `movie.movie_difficulty` — mais le genre et le studio ne sont nommés nulle
 * part côté film. C'est pourquoi l'exigence 2 du § 13.3 impose **au moins une
 * ligne par film de démonstration** : sans étiquette, `movie_theme.is_auto`
 * n'est calculable pour aucun thème de genre, et publier un nouveau thème
 * exigerait un re-balayage TMDB du catalogue entier.
 *
 * `tmdb_tag_id` est un identifiant TMDB **brut**, jamais un libellé : le nom
 * d'un genre est du contenu traduit, il vit en `theme_label`.
 *
 * UNIQUE `(movie_id, tag_kind, tmdb_tag_id)` : poser deux étiquettes sur un même
 * film se fait en les **nommant** (`->genre(16)->genre(12)` sur deux instances),
 * jamais par un `->count(2)` qui retomberait sur le même tirage aléatoire.
 *
 * @extends Factory<MovieTmdbTag>
 */
class MovieTmdbTagFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `created_at` est laissée à Eloquent : la table n'a pas d'`updated_at` et
     * le modèle porte `const UPDATED_AT = null`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ids = MovieFactory::TMDB_GENRE_IDS;

        return [
            'movie_id' => Movie::factory(),
            'tag_kind' => TmdbTagKind::Genre,
            'tmdb_tag_id' => $ids[array_rand($ids)],
        ];
    }

    /**
     * Un genre TMDB nommé — la valeur que porte `theme.rule_value` d'un thème
     * de `theme_kind = genre`, et que la règle automatique évalue localement,
     * sans aucun appel réseau.
     */
    public function genre(int $tmdbTagId): static
    {
        return $this->state([
            'tag_kind' => TmdbTagKind::Genre,
            'tmdb_tag_id' => $tmdbTagId,
        ]);
    }

    /**
     * Une société de production TMDB nommée — le support des thèmes de studio
     * (Disney, Pixar, Ghibli), nommés au produit.
     */
    public function company(int $tmdbTagId): static
    {
        return $this->state([
            'tag_kind' => TmdbTagKind::Company,
            'tmdb_tag_id' => $tmdbTagId,
        ]);
    }
}
