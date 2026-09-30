<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportDiscoverRequest;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Le lancement d'un balayage `discover`.
 *
 * **Aucun appel TMDB n'a lieu dans cette requête**, et ce n'est pas une
 * précaution de principe : le SAPI web porte `max_execution_time = 30` là où le
 * CLI l'écrase à 0, et cinq pages coûtent 25 à 45 secondes d'appels
 * séquentiels. La méthode ouvre la ligne `import_run`, dispatche
 * {@see RunCatalogImport} sur la file par DÉFAUT — jamais une file de jeu — et
 * redirige immédiatement vers le détail du balayage, seul endroit où le
 * curateur lira ce qu'il vient de déclencher.
 */
class ImportDiscoverController extends Controller
{
    /**
     * Ouvre un balayage et le confie au worker.
     */
    public function store(ImportDiscoverRequest $request, TmdbClient $tmdb): RedirectResponse
    {
        if (! $tmdb->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.tmdb_disabled')]);

            return back();
        }

        // Vérification et insertion sous le MÊME verrou : `throttle:admin-import`
        // autorise douze envois par minute, donc n'empêche aucun envoi
        // simultané, et aucune contrainte d'unicité ne rattrape la course.
        /** @var User $actor */
        $actor = $request->user();

        $run = ImportLauncher::openExclusively(
            ImportRunKind::Discover,
            $request->filter(),
            $actor,
            AdminActionType::ImportDiscoverStarted,
            AdminActionDetails::importPages($request->pages()),
        );

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.import_already_running')]);

            return back();
        }

        dispatch(RunCatalogImport::discover($run, $request->pages()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.toast.queued')]);

        return to_route('admin.import.show', ['importRun' => $run->id]);
    }
}
