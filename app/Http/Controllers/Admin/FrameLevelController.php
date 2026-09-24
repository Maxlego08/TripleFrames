<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\ChangeFrameLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameLevelUpdateRequest;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Changer le niveau d'une image — spec 20 § 5.7, ligne 16 de la matrice des
 * capacités (`can:update,frame`, `throttle:admin-curation`).
 *
 * Une image publiée sort du jeu et repasse en revue ; le toast le dit
 * (`admin.frame.flash.level_changed_review`). Un refus d'état est une erreur
 * traduite sous la clé `frame`, jamais un 403.
 */
class FrameLevelController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(FrameLevelUpdateRequest $request, Movie $movie, Frame $frame, ChangeFrameLevel $change): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $leftPlay = $change->handle($frame, $curator, $request->frameLevel());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $leftPlay
                ? __('admin.frame.flash.level_changed_review')
                : __('admin.frame.flash.level_changed'),
        ]);

        return back();
    }
}
