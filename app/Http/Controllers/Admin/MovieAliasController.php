<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\AddAlias;
use App\Actions\Curation\DeleteAlias;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieAliasStoreRequest;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

/**
 * Ajouter ou retirer un alias — spec 20 § 9.2, ligne 22 de la matrice des
 * capacités (`can:curate,movie`, `throttle:admin-curation`).
 *
 * Ajouter : une locale activée, un texte rogné ; l'écran a d'abord montré la
 * forme normalisée, dit si elle est déjà acceptée pour ce film et, sur un
 * film publié, ce qu'elle rendrait ambigu (`text_preview`). Retirer : tout
 * alias, TMDB compris — l'alias est lié à son film (`scopeBindings`), celui
 * d'un autre film répond 404. Retour à la fiche, toast de réussite.
 */
class MovieAliasController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(MovieAliasStoreRequest $request, Movie $movie, AddAlias $add): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $add->handle($movie, $curator, $request->aliasLocale(), $request->aliasText());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.aliases.flash.added'),
        ]);

        return back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(Request $request, Movie $movie, Alias $alias, DeleteAlias $delete): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $delete->handle($movie, $curator, $alias);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.aliases.flash.removed'),
        ]);

        return back();
    }
}
