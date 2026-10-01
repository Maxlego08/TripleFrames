<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\ReviewMovieFrames;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieFramesReviewRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Valider en lot les images d'un film — D42 du 30/09, spec 20 § 7.9, même
 * capacité et même limiteur que la revue unitaire (ligne 17 bis de la matrice :
 * `can:create,App\Models\FrameReview`, `throttle:admin-curation`).
 *
 * Posté depuis la confirmation de la fiche du film ou d'un groupe de la file
 * de revue. Chaque refus — plus rien à valider, liste périmée, empreinte
 * changée — revient en erreur traduite sous `frames`, jamais en 403, et
 * n'écrit rien. La publication du FILM n'est jamais automatique : la fiche
 * propose ensuite « Publier » si ses gardes sont remplies.
 */
class MovieFramesReviewController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(MovieFramesReviewRequest $request, Movie $movie, ReviewMovieFrames $review): RedirectResponse
    {
        /** @var User $reviewer */
        $reviewer = $request->user();

        $reviews = $review->handle($movie, $reviewer, $request->seen());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('admin.review.batch.flash', count($reviews), ['count' => count($reviews)]),
        ]);

        return back();
    }
}
