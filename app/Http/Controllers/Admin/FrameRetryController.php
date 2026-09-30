<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\RetryFrameProcessing;
use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * « Relancer » le traitement d'une image — contrat C9, spec 20 § 5.6, ligne 15
 * de la matrice des capacités (`can:update,frame`, `throttle:admin-frame`).
 *
 * Aucun champ : l'état de l'image décide seul. Un refus est une erreur
 * traduite sous la clé `frame` ({@see RetryFrameProcessing::refusal()}),
 * jamais une page d'erreur. Retour arrière avec le toast
 * `admin.frame.flash.retry_queued`.
 */
class FrameRetryController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(Request $request, Movie $movie, Frame $frame, RetryFrameProcessing $retry): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $retry->handle($frame, $curator);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame.flash.retry_queued')]);

        return back();
    }
}
