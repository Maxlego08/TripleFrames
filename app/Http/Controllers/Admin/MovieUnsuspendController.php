<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\UnsuspendMovie;
use App\Enums\ContentAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieUnsuspendRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Lever la suspension d'un film — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:unsuspend,movie`, administrateur seul, `throttle:admin-curation`).
 *
 * L'état restauré se lit dans le journal ; la garde de publication est
 * rejouée sous verrou. Toast selon l'issue : film rendu dans son état
 * antérieur, ou rendu dépublié parce que la garde a échoué.
 */
class MovieUnsuspendController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(MovieUnsuspendRequest $request, Movie $movie, UnsuspendMovie $unsuspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $outcome = $unsuspend->handle($movie, $admin, $request->reason(), $request->ambiguityDigest());

        Inertia::flash('toast', [
            'type' => $outcome['guard_failed'] ? 'warning' : 'success',
            'message' => match (true) {
                $outcome['guard_failed'] => __('admin.movie.unsuspend.flash_guard_failed'),
                $outcome['state'] === ContentAvailability::Published => __('admin.movie.unsuspend.flash_published'),
                $outcome['state'] === ContentAvailability::Unpublished => __('admin.movie.unsuspend.flash_unpublished'),
                default => __('admin.movie.unsuspend.flash_draft'),
            },
        ]);

        return back();
    }
}
