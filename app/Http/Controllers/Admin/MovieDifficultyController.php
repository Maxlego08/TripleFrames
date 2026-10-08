<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\CorrectMovieDifficulty;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieDifficultyUpdateRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Corriger la difficulté d'un film — spec 20 § 9.6, ligne 28 de la matrice
 * (`can:curate,movie`, `throttle:admin-curation`), L20-28b.
 *
 * La correction survit au réimport et resynchronise les thèmes de difficulté
 * du film dans la transaction du geste ({@see CorrectMovieDifficulty}) ;
 * journal `movie.difficulty_corrected` quand elle change.
 */
class MovieDifficultyController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(MovieDifficultyUpdateRequest $request, Movie $movie, CorrectMovieDifficulty $correct): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $changed = $correct->handle($movie, $curator, $request->override());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed
                ? __('admin.movie.difficulty.flash.saved')
                : __('admin.movie.difficulty.flash.unchanged'),
        ]);

        return to_route('admin.catalog.show', ['movie' => $movie->id]);
    }
}
