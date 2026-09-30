<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\UnpublishFrame;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameUnpublishRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Dépublier une image publiée, ou écarter une image jamais publiée — spec 20
 * § 8.4, ligne 18 de la matrice des capacités (`can:unpublish,frame`,
 * `throttle:admin-curation`).
 *
 * L'avertissement de couverture précède l'envoi, à l'écran (prop
 * `unpublish_preview`, lot L20-10) : le geste lui-même n'est jamais refusé
 * parce qu'il rend un film incomplet (E10-23). Toast selon le cas : image
 * dépubliée, ou image écartée.
 */
class FrameUnpublishController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(FrameUnpublishRequest $request, Movie $movie, Frame $frame, UnpublishFrame $unpublish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $wasPublished = $unpublish->handle($frame, $curator, $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasPublished
                ? __('admin.frame.flash.unpublished')
                : __('admin.frame.flash.set_aside'),
        ]);

        return back();
    }
}
