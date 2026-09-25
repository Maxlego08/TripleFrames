<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\DeleteMovieTitle;
use App\Actions\Curation\SaveMovieTitle;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieTitleUpdateRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Corriger ou retirer le titre d'un film dans une locale activée — spec 20
 * § 9.1, ligne 22 de la matrice des capacités (`can:curate,movie`,
 * `throttle:admin-curation`).
 *
 * La locale est dans l'URL et liée à `App\Enums\Locale` : une locale de
 * catalogue non activée (`ja`, `ko`) répond 404 — ses lignes s'affichent en
 * lecture seule sur la fiche, elles n'alimentent ni l'affichage d'un joueur
 * ni `answer_key`.
 *
 * Posté depuis la fiche du film, qui a d'abord montré l'aperçu du texte
 * (`text_preview`) ; retour à la fiche, toast de réussite. Un refus — texte
 * vide, ligne TMDB qu'on voudrait retirer — revient en erreur traduite, jamais
 * en 403.
 */
class MovieTitleController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(MovieTitleUpdateRequest $request, Movie $movie, Locale $locale, SaveMovieTitle $save): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $save->handle($movie, $curator, $locale, $request->movieTitle());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.titles.flash.saved'),
        ]);

        return back();
    }

    /**
     * @throws ValidationException la ligne est absente, ou d'origine TMDB
     * @throws Throwable
     */
    public function destroy(Request $request, Movie $movie, Locale $locale, DeleteMovieTitle $delete): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $delete->handle($movie, $curator, $locale);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.titles.flash.removed'),
        ]);

        return back();
    }
}
