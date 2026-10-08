<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\UnsuspendFrame;
use App\Enums\ContentAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuspensionRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Lever la suspension d'une image — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:unsuspend,frame`, administrateur seul, jamais tant que son film est
 * suspendu, `throttle:admin-curation`). Toast selon l'état rendu : en jeu,
 * ou à revoir.
 */
class FrameUnsuspendController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(SuspensionRequest $request, Movie $movie, Frame $frame, UnsuspendFrame $unsuspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $state = $unsuspend->handle($frame, $admin, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $state === ContentAvailability::Published
                ? __('admin.frame.unsuspend.flash_published')
                : __('admin.frame.unsuspend.flash_review'),
        ]);

        return back();
    }
}
