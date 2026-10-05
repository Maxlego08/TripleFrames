<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FrameBatchImportRequest;
use App\Http\Requests\Admin\FrameBatchStoreRequest;
use App\Jobs\Curation\ImportFrameBatch;
use App\Models\User;
use App\Support\Curation\FrameBatchImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Lots d'images » — spec 20 § 5.10, D57 du 05/10, ligne 46 de la
 * matrice (`can:importBatch,App\Models\Frame`).
 *
 * Trois gestes : afficher le dernier lot de son auteur, déposer un lot (son
 * aperçu à blanc, sur la base seule), l'importer (un job sur la file par
 * défaut, que l'écran sonde). Aucun appel TMDB dans la requête : c'est la
 * commande lancée par le job qui retélécharge les originaux, image par
 * image, sous les gardes de l'ajout unitaire.
 */
class FrameBatchController extends Controller
{
    public function index(Request $request): Response
    {
        $state = FrameBatchImport::latest($this->userId($request));

        return Inertia::render('admin/frame-batch/index', [
            'frame_batch' => $state === null ? null : FrameBatchImport::toProps($state),
            'poll_seconds' => Config::integer('catalog.curation.poll_seconds'),
            'max_kilobytes' => FrameBatchStoreRequest::MAX_KILOBYTES,
        ]);
    }

    public function store(FrameBatchStoreRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        FrameBatchImport::open($user, $request->batch());

        return to_route('admin.frame_batch.index');
    }

    public function import(FrameBatchImportRequest $request): RedirectResponse
    {
        $userId = $this->userId($request);
        $token = $request->token();
        $state = FrameBatchImport::find($userId, $token);

        if ($state === null || $state['status'] !== FrameBatchImport::PREVIEWED) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.frame_batch.expired')]);

            return to_route('admin.frame_batch.index');
        }

        FrameBatchImport::queue($userId, $token);
        dispatch(new ImportFrameBatch($userId, $token));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.frame_batch.toast.queued')]);

        return to_route('admin.frame_batch.index');
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
