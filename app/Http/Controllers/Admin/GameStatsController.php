<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AudienceRequest;
use App\Support\Admin\GameStatsReport;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Statistiques de jeu » — spec 20 § 12.6, ligne 51 de la matrice
 * des capacités (demande du porteur du 08/10), administrateur seul, en
 * lecture seule.
 *
 * Parties, réglages, réponses et films, en agrégats : aucune donnée
 * personnelle, la consultation n'est pas une lecture sensible. Même
 * fenêtre que l'audience (7, 30 ou 90 jours), lue par `AudienceRequest`.
 */
class GameStatsController extends Controller
{
    public function index(AudienceRequest $request): Response
    {
        return Inertia::render('admin/game-stats/index', [
            'report' => GameStatsReport::build($request->since(), Date::now()->toImmutable()),
            'window' => $request->window(),
            'windows' => array_keys(AudienceRequest::WINDOWS),
        ]);
    }
}
