<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Http\Controllers\Controller;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use App\Support\Admin\SeedList;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Admin\AdminActionDetails;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * « Importer la liste d'amorçage » — spec 20 § 3.5.
 *
 * Le serveur lit le fichier VERSIONNÉ, retire les identifiants déjà au
 * catalogue, films retirés compris, et ouvre **un seul** collage portant les
 * `paste_max_ids` premiers identifiants restants, dans l'ordre du fichier.
 * Un nouveau clic, le collage fini, importe le lot suivant : l'opération est
 * idempotente et reprend toujours au premier identifiant manquant.
 *
 * **Jamais deux collages ouverts à la fois** : le collage s'ouvre par
 * `ImportLauncher::openExclusively(ImportRunKind::Paste, …)`, dont le verrou
 * refuse tant qu'un collage est en file ou en cours — celui d'un « Importer »
 * de la recherche compris. Le verrou n'est ni contourné ni dupliqué ici.
 *
 * Aucun corps de requête, donc aucun FormRequest : la seule entrée est le
 * fichier du dépôt, jamais un contenu posté. Aucun appel TMDB dans la requête
 * non plus : le collage part sur la file par défaut, exactement comme un
 * collage manuel.
 */
class ImportSeedListController extends Controller
{
    /**
     * Ouvre le collage du lot suivant et le confie au worker.
     */
    public function store(Request $request, TmdbClient $tmdb): RedirectResponse
    {
        if (! $tmdb->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.tmdb_disabled')]);

            return back();
        }

        $identifiers = SeedList::identifiers();

        if ($identifiers === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.import.seed_list.empty')]);

            return back();
        }

        $batch = SeedList::nextBatch(SeedList::remaining($identifiers));

        if ($batch === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.import.seed_list.done')]);

            return back();
        }

        /** @var User $actor */
        $actor = $request->user();

        $run = ImportLauncher::openExclusively(
            ImportRunKind::Paste,
            ImportFilter::default(),
            $actor,
            AdminActionType::ImportSeedListStarted,
            AdminActionDetails::importIds($batch),
        );

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.import_already_running')]);

            return back();
        }

        dispatch(RunCatalogImport::paste($run, $batch));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.toast.queued')]);

        return to_route('admin.import.show', ['importRun' => $run->id]);
    }
}
