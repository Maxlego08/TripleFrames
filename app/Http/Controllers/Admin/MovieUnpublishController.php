<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\UnpublishMovie;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieUnpublishRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Dépublier un film publié, ou écarter un brouillon — spec 20 § 8.3 et
 * § 4.2, ligne 20 de la matrice des capacités (`can:unpublish,movie`,
 * `throttle:admin-curation`).
 *
 * Motif obligatoire ; les images du film restent publiées. Toast selon le
 * cas : film dépublié, ou film écarté.
 */
class MovieUnpublishController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(MovieUnpublishRequest $request, Movie $movie, UnpublishMovie $unpublish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $wasPublished = $unpublish->handle($movie, $curator, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasPublished
                ? __('admin.movie.unpublish.flash')
                : __('admin.movie.set_aside.flash'),
        ]);

        return back();
    }
}
