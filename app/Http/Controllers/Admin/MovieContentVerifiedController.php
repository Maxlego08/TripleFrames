<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\VerifyMovieContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieContentVerifiedRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Cocher « contenu vérifié, pas de classification restrictive » — spec 20
 * § 4.4, ligne 21 de la matrice des capacités (`can:verifyContent,movie`,
 * `throttle:admin-curation`).
 *
 * Offert sur la fiche d'un film `unrated_pending` seulement, derrière une
 * confirmation à motif obligatoire. Aucune route ne décoche, et aucune ne
 * lève `blocked` (décision 12).
 */
class MovieContentVerifiedController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(MovieContentVerifiedRequest $request, Movie $movie, VerifyMovieContent $verify): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $verify->handle($movie, $curator, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.content_verified.flash'),
        ]);

        return back();
    }
}
