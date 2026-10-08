<?php

namespace App\Support\ContentReport;

use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Movie;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * La cible d'un signalement de contenu, lue dans l'URL publique
 * `/report?movie=<tmdb_id>&frame=<frame.public_id>` (D63 du 07/10) : aucun
 * identifiant interne n'y figure.
 *
 * 404 pour un film introuvable, retiré (`withdrawn`) ou jamais publié, et
 * pour une image qui n'appartient pas au film, retirée ou jamais publiée. Un
 * film ou une image dépublié — ou suspendu — **depuis** la manche reste
 * signalable : le joueur l'a vu en jeu.
 */
final readonly class ContentReportTarget
{
    private function __construct(
        public Movie $movie,
        public ?Frame $frame,
    ) {}

    /** @throws NotFoundHttpException */
    public static function resolve(mixed $tmdbId, mixed $framePublicId): self
    {
        $tmdb = filter_var($tmdbId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($tmdb === false) {
            throw new NotFoundHttpException;
        }

        $movie = Movie::query()->where('tmdb_id', $tmdb)->first();

        if (! $movie instanceof Movie || ! self::wasPlayed($movie->availability, $movie->first_published_at !== null)) {
            throw new NotFoundHttpException;
        }

        if ($framePublicId === null || $framePublicId === '') {
            return new self($movie, null);
        }

        if (! is_string($framePublicId)) {
            throw new NotFoundHttpException;
        }

        $frame = Frame::query()
            ->where('public_id', $framePublicId)
            ->where('movie_id', $movie->id)
            ->first();

        if (! $frame instanceof Frame || ! self::wasPlayed($frame->availability, $frame->first_published_at !== null)) {
            throw new NotFoundHttpException;
        }

        $frame->setRelation('movie', $movie);

        return new self($movie, $frame);
    }

    /** Un contenu qui a pu être montré en jeu : publié un jour, jamais retiré. */
    private static function wasPlayed(ContentAvailability $availability, bool $everPublished): bool
    {
        return $everPublished && $availability !== ContentAvailability::Withdrawn;
    }
}
