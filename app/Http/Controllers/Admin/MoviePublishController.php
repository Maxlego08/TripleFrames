<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\PublishMovie;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoviePublishRequest;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Publier ou republier un film — spec 20 § 8.1, ligne 19 de la matrice des
 * capacités (`can:publish,movie`, `throttle:admin-curation`).
 *
 * Posté depuis la confirmation de l'éditeur de la banque ou de la fiche, qui
 * a d'abord montré l'aperçu d'ambiguïté (`publication_preview`, § 8.2) et en
 * renvoie l'empreinte. Chaque refus — contenu non vérifié, couverture
 * incomplète, aucune clé exacte, aperçu périmé — revient en erreur traduite
 * sous la confirmation, jamais en 403, et n'écrit rien. Retour à l'écran
 * d'où la confirmation est partie.
 */
class MoviePublishController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(MoviePublishRequest $request, Movie $movie, PublishMovie $publish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $first = $publish->handle($movie, $curator, $request->ambiguityDigest());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $first
                ? __('admin.movie.publish.flash.published')
                : __('admin.movie.publish.flash.republished'),
        ]);

        return back();
    }
}
