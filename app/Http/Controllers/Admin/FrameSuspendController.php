<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\SuspendFrame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuspensionRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Suspendre une image — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:suspend,frame`, administrateur seul, image publiée,
 * `throttle:admin-curation`). Motif facultatif.
 */
class FrameSuspendController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(SuspensionRequest $request, Movie $movie, Frame $frame, SuspendFrame $suspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $suspend->handle($frame, $admin, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.frame.suspend.flash'),
        ]);

        return back();
    }
}
