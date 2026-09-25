<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportIdsRequest;
use App\Jobs\Catalog\PreviewCatalogPaste;
use App\Support\Admin\PastePreview;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * L'aperçu à blanc d'un collage — spec 20 § 3.3 (n° 8).
 *
 * **Mêmes règles que le collage** : la requête est celle du collage
 * ({@see ImportIdsRequest}, règles de `CatalogImportValidationRules`), si
 * bien qu'un collage illisible ou trop long est refusé ici comme il le serait
 * à l'import, sous le même champ.
 *
 * **Aucun appel TMDB dans la requête, aucune ligne `import_run`** : la
 * méthode ouvre l'aperçu en cache sous un jeton `bin2hex(random_bytes(16))`,
 * dispatche {@see PreviewCatalogPaste} sur la file par défaut et renvoie à
 * l'écran d'import, qui sonde `paste_preview` jusqu'à complétude. L'aperçu est
 * indicatif : « Importer ces films » ouvre ensuite le collage réel, qui
 * rejoue toutes les gardes.
 */
class ImportPreviewController extends Controller
{
    /**
     * Ouvre un aperçu et le confie au traitement d'arrière-plan.
     */
    public function store(ImportIdsRequest $request, TmdbClient $tmdb): RedirectResponse
    {
        if (! $tmdb->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.tmdb_disabled')]);

            return back();
        }

        $userId = (int) $request->user()?->getAuthIdentifier();
        $identifiers = $request->identifiers();

        $token = PastePreview::open($userId, $identifiers);

        dispatch(new PreviewCatalogPaste($userId, $token, $identifiers));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.import.preview.toast.queued')]);

        return to_route('admin.import.index');
    }
}
