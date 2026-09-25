<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Curation\ThroughputReport;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le tableau du débit de curation et le verdict du lot pilote — spec 20
 * § 10.2 à § 10.4, ligne 8 de la matrice des capacités
 * (`can:viewAny,Movie`). **Aucune écriture.**
 *
 * Agrégat seulement, jamais nominatif : {@see ThroughputReport} ne lit aucune
 * colonne d'auteur. Le rapport est une prop paresseuse, pour que
 * « Rafraîchir » ne recharge que lui.
 */
class ThroughputController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/throughput', [
            'report' => fn (): array => ThroughputReport::measure()->toArray(),
        ]);
    }
}
