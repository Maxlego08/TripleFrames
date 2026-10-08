<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\SuspendMovie;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuspensionRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Suspendre un film en un clic — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:suspend,movie`, administrateur seul, `throttle:admin-curation`).
 * Une confirmation, motif facultatif, aucun examen.
 */
class MovieSuspendController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(SuspensionRequest $request, Movie $movie, SuspendMovie $suspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $suspend->handle($movie, $admin, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.movie.suspend.flash'),
        ]);

        return back();
    }
}
