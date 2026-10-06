<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameTmdbStoreRequest;
use App\Models\Movie;
use App\Models\User;
use App\Support\Curation\TmdbFrameIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Ajouter une variante depuis un visuel TMDB — contrat C9, spec 20 § 5.3,
 * ligne 13 de la matrice des capacités (`can:create,App\Models\Frame,movie`,
 * `throttle:admin-frame`).
 *
 * La séquence de gardes — appartenance, langue, dimensions, plancher,
 * téléchargement, ajout dédoublonné — vit dans {@see TmdbFrameIntake},
 * partagée avec l'import d'un lot d'images (§ 5.10, D57 du 05/10) : chaque
 * refus y est une **erreur de validation traduite**, jamais une page
 * d'erreur.
 *
 * Retour arrière avec le toast `admin.frame.flash.queued`, **sans chemin ni
 * empreinte** : le curateur n'attend jamais le job, il enchaîne (§ 6.1).
 */
class FrameTmdbController extends Controller
{
    /**
     * Ajoute la variante et confie son traitement à la file `default`.
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(FrameTmdbStoreRequest $request, Movie $movie, TmdbFrameIntake $intake): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $intake->add(
            movie: $movie,
            curator: $curator,
            level: $request->frameLevel(),
            crop: $request->crop(),
            filePath: $request->tmdbFilePath(),
            cropSeconds: $request->cropSeconds(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame.flash.queued')]);

        return back();
    }
}
