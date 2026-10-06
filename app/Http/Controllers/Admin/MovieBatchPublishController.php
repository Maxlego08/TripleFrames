<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\PublishReadyMovies;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieBatchPublishRequest;
use App\Models\User;
use App\Support\Curation\ReadyBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;
use Throwable;

/**
 * « Publier les films prêts » — spec 20 § 8.1 bis, ligne 47 de la matrice,
 * D59 du 06/10 (`can:publishReady`, `throttle:admin-curation`).
 *
 * L'écran montre le lot AVANT tout envoi : chaque film prêt avec son
 * avertissement d'ambiguïté, calculé le lot entier compté comme publié, et
 * les films prêts mis de côté avec leur condition manquante. L'envoi poste
 * les identifiants et l'empreinte lus ; un lot changé entre-temps revient en
 * erreur traduite sur l'écran rechargé, sans rien écrire.
 */
class MovieBatchPublishController extends Controller
{
    /**
     * @throws JsonException
     */
    public function create(ReadyBatch $batch): Response
    {
        return Inertia::render('admin/catalog/ready', [
            'batch' => $batch->preview(),
        ]);
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(MovieBatchPublishRequest $request, PublishReadyMovies $publish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $count = $publish->handle($curator, $request->movieIds(), $request->ambiguityDigest());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('admin.catalog.publish_ready.flash', $count, ['count' => $count]),
        ]);

        return to_route('admin.catalog.index');
    }
}
