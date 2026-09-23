<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\ImportRun;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * La reprise d'un balayage suspendu.
 *
 * **Aucun corps de requête, donc aucun FormRequest** : la seule garde de la
 * route est `ImportRunPolicy::update`, qui exige le seuil curateur ET
 * `status = running`. Ce second terme n'est pas cosmétique — reprendre un
 * balayage terminé relancerait un curseur déjà consommé et fausserait les
 * quatre compteurs, qui sont la preuve opposable de ce qu'un balayage a fait.
 *
 * **Seuls les `discover` sont reprenables.** La liste d'un collage n'est
 * stockée nulle part, et le bouton est donc rendu désactivé avec son motif
 * plutôt qu'absent en silence ; ce refus-ci est la moitié serveur de la même
 * règle.
 */
class ImportResumeController extends Controller
{
    /**
     * Remet un balayage suspendu dans la file.
     */
    public function store(ImportRun $importRun, TmdbClient $tmdb): RedirectResponse
    {
        if (! $tmdb->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.tmdb_disabled')]);

            return back();
        }

        if (! AdminCatalogPresenter::isResumable($importRun)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.run_not_resumable')]);

            return back();
        }

        // Au PLAFOND, jamais au plancher. `pages_default` est le défaut d'un
        // champ de saisie, pas le budget d'une reprise : reprendre une page à
        // la fois ferait avancer un balayage de vingt films par clic, soit
        // plusieurs centaines d'allers-retours pour le filtre par défaut, sans
        // qu'aucun texte de l'écran n'explique pourquoi.
        dispatch(RunCatalogImport::discover($importRun, ImportController::defaults()['pages_max']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.toast.resumed')]);

        return to_route('admin.import.show', ['importRun' => $importRun->id]);
    }
}
