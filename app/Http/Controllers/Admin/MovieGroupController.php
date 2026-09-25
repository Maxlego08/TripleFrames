<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\SetMovieGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieGroupUpdateRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Regrouper deux films, rejoindre un groupe, retirer un film de son groupe —
 * spec 20 § 9.4, ligne 23 de la matrice des capacités (`can:curate,movie`,
 * `throttle:admin-curation`).
 *
 * Un geste manuel, jamais une suggestion appliquée seule : la fiche propose
 * les candidats exacts (homonymes et remakes au même titre), le curateur
 * tranche. Un refus — film introuvable ou retiré, film déjà groupé ailleurs —
 * revient en erreur traduite, jamais en 403. Toast selon l'issue.
 */
class MovieGroupController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(MovieGroupUpdateRequest $request, Movie $movie, SetMovieGroup $group): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $outcome = $group->handle(
            $movie,
            $curator,
            $request->withMovieId(),
            $request->groupId(),
            $request->leave(),
            $request->label(),
            $request->note(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($outcome) {
                SetMovieGroup::CREATED => __('admin.movie.group.flash.created'),
                SetMovieGroup::JOINED => __('admin.movie.group.flash.joined'),
                SetMovieGroup::LEFT => __('admin.movie.group.flash.left'),
                default => __('admin.movie.group.flash.unchanged'),
            },
        ]);

        return back();
    }
}
