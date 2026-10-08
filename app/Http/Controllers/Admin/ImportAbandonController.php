<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Throwable;

/**
 * Clore un balayage suspendu (spec 20 § 3.8, ligne 25 de la matrice).
 *
 * **Aucun corps de requête, donc aucun FormRequest** : la garde de la route
 * est `ImportRunPolicy::update` (curateur et `status = running`), rejouée
 * sous verrou par {@see ImportLauncher::abandon()}, qui écrit la ligne
 * `import.abandoned` dans la transaction du geste (D41 du 30/09, D66 du
 * 07/10). Un balayage qu'un worker tient encore est refusé par un message,
 * jamais par un 403 : le curateur a seulement cliqué trop tôt.
 */
class ImportAbandonController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(Request $request, ImportRun $importRun): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! ImportLauncher::abandon($importRun, $actor)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.import.abandon.busy')]);

            return to_route('admin.import.show', ['importRun' => $importRun->id]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.abandon.done')]);

        return to_route('admin.import.show', ['importRun' => $importRun->id]);
    }
}
