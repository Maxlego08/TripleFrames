<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportIdsRequest;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Admin\AdminActionDetails;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Le collage d'identifiants — la voie d'EXCEPTION, différée comme l'autre.
 *
 * Le filtre par défaut est **enregistré sans être appliqué** : il documente le
 * défaut en vigueur au moment du geste, ce qui rend les trois motifs relisibles
 * des mois plus tard. `is_widened` reste faux — un collage n'élargit rien, il
 * contourne (§ 9.2).
 *
 * La liste collée voyage dans la charge utile du job et, depuis D41 du 30/09,
 * dans le `details` de la ligne `import.paste_started` du journal — pour la
 * trace, jamais pour la reprise : aucune colonne d'`import_run` ne la porte. C'est pour cette raison exacte
 * qu'un collage interrompu n'est pas reprenable depuis l'écran — question
 * renvoyée à la spec 20.
 */
class ImportIdsController extends Controller
{
    /**
     * Ouvre un collage et le confie au worker.
     */
    public function store(ImportIdsRequest $request, TmdbClient $tmdb): RedirectResponse
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
        $identifiers = $request->identifiers();

        $run = ImportLauncher::openExclusively(
            ImportRunKind::Paste,
            ImportFilter::default(),
            $actor,
            AdminActionType::ImportPasteStarted,
            AdminActionDetails::importIds($identifiers),
        );

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.import_already_running')]);

            return back();
        }

        dispatch(RunCatalogImport::paste($run, $identifiers));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.toast.queued')]);

        return to_route('admin.import.show', ['importRun' => $run->id]);
    }
}
