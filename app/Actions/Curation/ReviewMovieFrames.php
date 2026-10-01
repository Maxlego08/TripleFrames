<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\FrameReviewWriter;
use App\Support\Curation\ReviewQueue;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Valider en lot les images d'un film en attente de revue — D42 du 30/09,
 * spec 20 § 7.9, même capacité que la revue unitaire (ligne 17 bis de la matrice).
 *
 * **Le lot** est {@see ReviewQueue::batchOf()} : les images « À revoir » ou
 * « À re-revoir » du film, hors « Rejetées », à source déclarée exploitable.
 * Une image en traitement, en échec, rejetée, écartée, suspendue ou retirée
 * n'en fait jamais partie : elle reste en revue individuelle.
 *
 * **La grille** : pour chaque image, les réponses « rien à signaler » de la
 * grille COURANTE à son niveau ({@see ExclusionGrid::slugsFor()}), donc une
 * décision `passed` dérivée comme partout ({@see ExclusionGrid::decisionFor()}).
 *
 * **Le processus démontrable reste image par image** : une ligne
 * `frame_review` par image — nom réel, horodatage, version de grille,
 * empreinte revue, source déclarée —, écrite et suivie de la publication par
 * {@see FrameReviewWriter}, exactement comme {@see ReviewFrame}. Le journal
 * n'en porte qu'UNE ligne, `movie.frames_reviewed`, et aucune
 * `frame.reviewed`.
 *
 * **Tout ou rien, sous verrou** — le film d'abord, ses images ensuite, dans
 * l'ordre des autres gestes de curation. Chaque refus n'écrit rien :
 *
 * 1. `admin.review.batch.empty` : le film n'a plus aucune image à valider en
 *    lot ;
 * 2. `admin.review.batch.stale_list` : les images envoyées ne sont plus
 *    exactement celles du lot — une image est entrée, sortie ou a été jugée
 *    ailleurs depuis l'affichage ;
 * 3. `admin.review.batch.stale` : une empreinte a changé — un re-recadrage
 *    est passé entre-temps.
 */
final class ReviewMovieFrames
{
    public function __construct(
        private readonly MovieProjector $projector,
        private readonly AdminJournal $journal,
    ) {}

    /**
     * Écrit une revue passante par image du lot, les publie, et consigne le
     * lot au journal.
     *
     * @param  array<int, string>  $seen  empreinte affichée, par identifiant d'image
     * @return list<FrameReview>
     *
     * @throws ValidationException un refus relu sous le verrou, sans aucune écriture
     * @throws Throwable
     */
    public function handle(Movie $movie, User $reviewer, array $seen): array
    {
        return DB::transaction(function () use ($movie, $reviewer, $seen): array {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();
            $batch = ReviewQueue::batchOf($locked, lock: true);

            Gate::forUser($reviewer)->authorize('create', FrameReview::class);

            if ($batch === []) {
                throw ValidationException::withMessages(['frames' => __('admin.review.batch.empty')]);
            }

            $expected = array_map(static fn (Frame $frame): int => $frame->id, $batch);
            $given = array_keys($seen);

            sort($expected);
            sort($given);

            if ($given !== $expected) {
                throw ValidationException::withMessages(['frames' => __('admin.review.batch.stale_list')]);
            }

            foreach ($batch as $frame) {
                if (! hash_equals((string) $frame->published_hash, $seen[$frame->id])) {
                    throw ValidationException::withMessages(['frames' => __('admin.review.batch.stale')]);
                }
            }

            $gridVersion = ExclusionGrid::CURRENT_VERSION;
            $now = Date::now()->toImmutable()->startOfSecond();
            $reviews = [];

            foreach ($batch as $frame) {
                $answers = array_fill_keys(ExclusionGrid::slugsFor($frame->frame_level, $gridVersion), true);
                $decision = ExclusionGrid::decisionFor($answers, $frame->frame_level, $gridVersion);

                // Inatteignable : « rien à signaler » sur chaque item du
                // niveau est, par définition, une revue passante.
                if ($decision !== ReviewDecision::Passed) {
                    throw new LogicException("La validation en lot de l'image #{$frame->id} ne passe pas la grille v{$gridVersion}.");
                }

                $review = FrameReviewWriter::record($frame, $reviewer, $gridVersion, $decision, $answers, $now);

                FrameReviewWriter::publish($frame, $review, $now);

                $reviews[] = $review;
            }

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2),
            // une seule fois pour le lot.
            $this->projector->recompute($locked);

            $this->journal->record(
                $reviewer,
                AdminActionType::MovieFramesReviewed,
                $locked->id,
                details: AdminActionDetails::framesReviewed($expected, $gridVersion),
            );

            return $reviews;
        });
    }
}
