<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\ReviewFrame;
use App\Enums\ContentAvailability;
use App\Enums\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameReviewStoreRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Passer une revue d'image — spec 20 § 7.5, ligne 17 de la matrice des
 * capacités (`can:create,App\Models\FrameReview`, `throttle:admin-curation`).
 *
 * Une revue passante PUBLIE l'image ; une revue rejetée la range dans
 * « Rejetées ». Chaque refus — image verrouillée, octets ou niveau changés,
 * source contestée, image déjà jugée — revient en erreur traduite sous le
 * champ qu'il concerne, jamais en 403, et n'écrit aucune preuve. Retour à la
 * file, d'où la revue est postée : l'image suivante y prend le focus.
 */
class FrameReviewController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(FrameReviewStoreRequest $request, Movie $movie, Frame $frame, ReviewFrame $review): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        // Lu avant le verrou pour le seul libellé du toast : l'action relit
        // tout sous le verrou, et c'est elle qui décide.
        $wasInPlay = $frame->availability === ContentAvailability::Published;

        $line = $review->handle(
            $frame,
            $reviewer,
            $request->gridVersion(),
            $request->reviewedHash(),
            $request->answers(),
            $request->declaredSourceReference(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match (true) {
                $line->decision === ReviewDecision::Rejected => __('admin.review.flash.rejected'),
                $wasInPlay => __('admin.review.flash.rereviewed'),
                default => __('admin.review.flash.published'),
            },
        ]);

        return back();
    }
}
