<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\CoverageLossPreview;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La file de revue — spec 20 § 7.3 et § 7.4, ligne 6 de la matrice des
 * capacités (`can:create,App\Models\FrameReview` : lire la file, c'est déjà
 * s'apprêter à revoir).
 *
 * Trois listes, trois onglets — « À revoir », « À re-revoir », « Rejetées » —,
 * chacune regroupée par film : les prédicats et l'ordre sont ceux de
 * {@see ReviewQueue}, seul porteur. Cet écran n'écrit rien : la revue a sa
 * route ({@see FrameReviewController}), la dépublication et la mise à l'écart
 * d'une image rejetée aussi (`admin.catalog.frames.unpublish`), le
 * re-recadrage vit dans l'éditeur de la banque.
 *
 * **Chaque liste est plafonnée à {@see self::MOVIES_PER_LIST} films** (amendé
 * le 05/10, D57) : un lot d'images importé met des centaines de films en
 * revue d'un coup, et la file entière ne tient ni dans la mémoire de la
 * requête ni dans une page. La file reste une file — premier prêt, premier
 * revu — : un film revu en sort, le suivant y entre. `queue_totals` donne les
 * vrais totaux de chaque onglet.
 *
 * `unpublish_preview` est une prop FACULTATIVE, calculée au seul
 * rechargement partiel qui ouvre la confirmation d'une dépublication :
 * l'avertissement de couverture (§ 8.4) précède alors tout envoi, comme dans
 * l'éditeur. L'image visée est désignée par le même paramètre.
 */
class FrameReviewQueueController extends Controller
{
    /** Films envoyés par liste : les premiers de la file, jamais toute la file. */
    public const int MOVIES_PER_LIST = 20;

    public function index(Request $request, CoverageLossPreview $coverageLoss): Response
    {
        $queue = new ReviewQueue;

        return Inertia::render('admin/review/index', [
            'queue' => fn (): array => $this->queue($queue),
            'queue_totals' => fn (): array => $this->totals($queue),
            // Les lots de validation en une fois, un par film affiché qui en a
            // un (D42 du 30/09, § 7.9) — lus sur la file déjà chargée.
            'review_batches' => fn (): array => $this->batches($queue),
            'unpublish_preview' => Inertia::optional(fn (): ?array => $this->unpublishPreview($request, $coverageLoss)),
            // La cadence du battement de débit (§ 10.1) : la revue d'une image
            // est une page de son film, où le temps actif se mesure.
            'heartbeat_seconds' => Config::integer('catalog.curation.heartbeat_seconds'),
        ]);
    }

    /**
     * Les trois listes, chacune en groupes par film, dans l'ordre de la file.
     *
     * @return array<string, list<array{movie: array<string, mixed>, frames: list<array<string, mixed>>}>>
     */
    private function queue(ReviewQueue $queue): array
    {
        $lists = [];

        foreach (ReviewList::cases() as $list) {
            $groups = [];

            foreach ($queue->frames($list) as $frame) {
                $last = array_key_last($groups);

                if ($last === null || $groups[$last]['movie']['id'] !== $frame->movie_id) {
                    if (count($groups) >= self::MOVIES_PER_LIST) {
                        break;
                    }

                    $groups[] = [
                        'movie' => AdminCatalogPresenter::reviewMovie($frame->movie),
                        'frames' => [],
                    ];
                    $last = array_key_last($groups);
                }

                $groups[$last]['frames'][] = AdminCatalogPresenter::reviewFrame($frame, $queue->judgingReviewOf($frame));
            }

            $lists[$list->value] = $groups;
        }

        return $lists;
    }

    /**
     * Les vrais totaux de chaque liste : films et images.
     *
     * @return array<string, array{movies: int, frames: int}>
     */
    private function totals(ReviewQueue $queue): array
    {
        $totals = [];

        foreach (ReviewList::cases() as $list) {
            $frames = $queue->frames($list);
            $totals[$list->value] = [
                'movies' => count(array_unique(array_map(static fn (Frame $frame): int => $frame->movie_id, $frames))),
                'frames' => count($frames),
            ];
        }

        return $totals;
    }

    /**
     * Les films affichés : les {@see self::MOVIES_PER_LIST} premiers de chaque
     * liste.
     *
     * @return array<int, true>
     */
    private function shownMovies(ReviewQueue $queue): array
    {
        $shown = [];

        foreach (ReviewList::cases() as $list) {
            $movies = [];

            foreach ($queue->frames($list) as $frame) {
                $movies[$frame->movie_id] = true;

                if (count($movies) > self::MOVIES_PER_LIST) {
                    unset($movies[$frame->movie_id]);

                    break;
                }
            }

            $shown += $movies;
        }

        return $shown;
    }

    /**
     * Les lots de la file, un par film, dans l'ordre de l'écran.
     *
     * @return list<array{movie_id: int, grid_version: int, frames: list<array{id: int, hash: string}>}>
     */
    private function batches(ReviewQueue $queue): array
    {
        $batches = [];
        $shown = $this->shownMovies($queue);

        foreach ($queue->batches() as $movieId => $frames) {
            if (! isset($shown[$movieId])) {
                continue;
            }

            $batch = AdminCatalogPresenter::reviewBatch($frames);

            if ($batch !== null) {
                $batches[] = ['movie_id' => $movieId, ...$batch];
            }
        }

        return $batches;
    }

    /**
     * L'avertissement de perte de couverture pour l'image désignée, ou `null`
     * s'il n'y a rien à annoncer.
     *
     * @return array{frame_id: int, playable_up_to: int|null}|null
     */
    private function unpublishPreview(Request $request, CoverageLossPreview $coverageLoss): ?array
    {
        $frameId = $request->integer(FrameBankController::PREVIEW_FRAME_PARAMETER);

        if ($frameId < 1) {
            return null;
        }

        $frame = Frame::query()->with('movie')->find($frameId);

        return $frame instanceof Frame ? $coverageLoss->forFrame($frame) : null;
    }
}
