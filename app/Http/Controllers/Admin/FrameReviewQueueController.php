<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\CoverageLossPreview;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use Illuminate\Http\Request;
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
 * `unpublish_preview` est une prop FACULTATIVE, calculée au seul
 * rechargement partiel qui ouvre la confirmation d'une dépublication :
 * l'avertissement de couverture (§ 8.4) précède alors tout envoi, comme dans
 * l'éditeur. L'image visée est désignée par le même paramètre.
 */
class FrameReviewQueueController extends Controller
{
    public function index(Request $request, CoverageLossPreview $coverageLoss): Response
    {
        $queue = new ReviewQueue;

        return Inertia::render('admin/review/index', [
            'queue' => fn (): array => $this->queue($queue),
            'unpublish_preview' => Inertia::optional(fn (): ?array => $this->unpublishPreview($request, $coverageLoss)),
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
