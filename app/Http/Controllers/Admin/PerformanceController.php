<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PerformanceRequest;
use App\Support\Perf\PerformanceReport;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Performances » — spec 20 § 12.3, ligne 43 de la matrice des
 * capacités (D47 du 01/10), administrateur seul, en lecture seule.
 *
 * Requêtes par route, jobs par classe, requêtes SQL lentes, retards et
 * durées du moteur, sur une fenêtre d'une heure, d'un jour ou d'une semaine.
 * Aucune donnée personnelle : la consultation n'est pas une lecture sensible.
 */
class PerformanceController extends Controller
{
    public function index(PerformanceRequest $request): Response
    {
        return Inertia::render('admin/performance/index', [
            'report' => PerformanceReport::build($request->since()),
            'window' => $request->window(),
            'windows' => array_keys(PerformanceRequest::WINDOWS),
            'enabled' => config('perf.enabled') === true,
            'slow_query_ms' => config('perf.slow_query_ms'),
            'sample_rate' => config('perf.sample_rate'),
        ]);
    }
}
