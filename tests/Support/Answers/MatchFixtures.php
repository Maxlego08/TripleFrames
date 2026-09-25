<?php

namespace Tests\Support\Answers;

use App\Actions\Curation\PublishMovie;
use App\Enums\ContentAvailability;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\Round;
use App\Support\Answers\AnswerMatcher;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\AnswerKeyProjector;
use App\ValueObjects\Answers\MatchResult;

/**
 * Fixtures de l'appariement (spec 70 § 6, lot L70-3) — de vrais films, de
 * vraies clés projetées, un vrai catalogue publié.
 *
 * **Aucune clé écrite à la main** : chaque film passe par
 * `MovieFactory::playable()`, donc par `AnswerKeyProjector`, qui dérive les
 * préfixes et sous-titres et recompte `is_ambiguous` sur l'état réel de la
 * base. Un verdict prouvé sur une projection inventée prouverait un
 * comportement sur un état que le code ne produit jamais.
 */
final class MatchFixtures
{
    /**
     * Un film et ses clés projetées. Publié par défaut ; `$availability`
     * permet un brouillon ou un film dépublié, dont les clés existent aussi.
     *
     * @param  array<string, string>  $titles  locale de CATALOGUE => titre
     * @param  array<string, list<string>>  $aliases  locale de CATALOGUE => alias
     */
    public static function movie(
        string $titleOriginal,
        array $titles = [],
        array $aliases = [],
        ContentAvailability $availability = ContentAvailability::Published,
    ): Movie {
        $attributes = ['title_original' => $titleOriginal];

        if ($availability !== ContentAvailability::Published) {
            $attributes['availability'] = $availability;
        }

        // Un brouillon n'a jamais été publié.
        if ($availability === ContentAvailability::Draft) {
            $attributes['availability_changed_at'] = null;
            $attributes['first_published_at'] = null;
        }

        return Movie::factory()->playable(
            titles: $titles === [] ? ['en' => $titleOriginal] : $titles,
            aliases: $aliases === [] ? ['fr' => []] : $aliases,
        )->create($attributes);
    }

    /**
     * Une manche en cours dont ce film est la bonne réponse.
     */
    public static function roundOn(Movie $movie): Round
    {
        return Round::factory()->forMovie($movie)->running()->create();
    }

    /**
     * Le verdict d'une saisie **brute**, normalisée comme à l'étape S5 — une
     * seule fois, jamais sur une forme déjà normalisée.
     */
    public static function judge(Round $round, string $typed, ?AnswerMatcher $matcher = null): MatchResult
    {
        return ($matcher ?? app(AnswerMatcher::class))->match($round, AnswerKeyNormalizer::normalize($typed));
    }

    /**
     * La clé projetée d'un film pour une forme normalisée.
     */
    public static function key(Movie $movie, string $normalized): AnswerKey
    {
        return AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->where('normalized', $normalized)
            ->sole();
    }

    /**
     * Publie un film en pleine manche comme le fait le geste de publication :
     * la disponibilité change, puis le recompte synchrone de l'ambiguïté de
     * toutes ses formes, dans le même geste.
     */
    public static function publish(Movie $movie): void
    {
        $movie->availability = ContentAvailability::Published;
        $movie->save();

        (new AnswerKeyProjector)->recomputeAmbiguity(PublishMovie::formsOf($movie));
    }
}
