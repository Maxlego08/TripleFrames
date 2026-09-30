<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\RecordCurationHeartbeat;
use App\Http\Controllers\Controller;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;

/**
 * Le battement de débit — spec 20 § 10.1, ligne 24 de la matrice des
 * capacités (`can:curate,movie`, `throttle:admin-heartbeat`), réponse 204.
 *
 * Posté par `use-curation-heartbeat.ts` depuis l'éditeur, la fiche ou la
 * revue d'une image du film, seulement après une saisie depuis le tick
 * précédent. Il ne rend rien : le temps actif n'est jamais montré au curateur
 * pendant qu'il travaille, il ne sert qu'au tableau du débit, en agrégat.
 */
class CurationHeartbeatController extends Controller
{
    public function store(Request $request, Movie $movie, RecordCurationHeartbeat $heartbeat): Response
    {
        /** @var User $curator */
        $curator = $request->user();

        $heartbeat->handle($curator, $movie, Date::now()->toImmutable());

        return response()->noContent();
    }
}
