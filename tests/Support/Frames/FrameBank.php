<?php

namespace Tests\Support\Frames;

use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieProjection;
use Database\Factories\MovieFactory;
use RuntimeException;

/**
 * Banques d'images de fixture pour les gestes de curation sur une image
 * existante (spec 20 § 5.7 et § 8.4) : re-recadrer, changer le niveau,
 * dépublier ou écarter.
 *
 * Chaque image est PUBLIÉE par la fabrique — dérivé et master réellement
 * écrits sur le disque `frames`, revue passante sur les octets écrits —, puis
 * la projection du film est recalculée sur l'état réel de la base : ce que
 * mesurent les tests, c'est l'effet du geste sur cette projection. Tout
 * appelant pose donc `Storage::fake(FrameStoragePrefix::DISK)` d'abord.
 */
final class FrameBank
{
    /**
     * Un film PUBLIÉ dont la banque porte une image publiée par niveau donné,
     * dans l'ordre — un niveau répété donne plusieurs variantes.
     *
     * @param  list<int>  $levels
     * @return array{0: Movie, 1: list<Frame>}
     */
    public static function publishedMovie(array $levels): array
    {
        return self::movieWith(Movie::factory()->published()->create(), $levels);
    }

    /**
     * Les images publiées, ajoutées à un film déjà créé.
     *
     * @param  list<int>  $levels
     * @return array{0: Movie, 1: list<Frame>}
     */
    public static function movieWith(Movie $movie, array $levels): array
    {
        $frames = [];

        foreach ($levels as $level) {
            $frames[] = Frame::factory()
                ->for($movie)
                ->level(FrameLevel::from($level))
                ->published()
                ->create();
        }

        MovieFactory::recomputeProjection($movie);

        return [$movie->refresh(), $frames];
    }

    /**
     * La projection du film, relue en base.
     */
    public static function projection(Movie $movie): MovieProjection
    {
        return MovieProjection::query()->find($movie->id)
            ?? throw new RuntimeException('Projection absente : le film a été créé sans elle.');
    }
}
