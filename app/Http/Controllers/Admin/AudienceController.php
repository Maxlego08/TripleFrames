<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AudienceRequest;
use App\Support\Audience\AudienceReport;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Audience » — spec 20 § 12.4, ligne 44 de la matrice des
 * capacités (D48 du 01/10), administrateur seul, en lecture seule.
 *
 * Des compteurs quotidiens sans cookie, le temps réel et l'entonnoir de jeu.
 * Aucune donnée personnelle : la consultation n'est pas une lecture sensible.
 */
class AudienceController extends Controller
{
    public function index(AudienceRequest $request): Response
    {
        return Inertia::render('admin/audience/index', [
            'report' => AudienceReport::build($request->since(), Date::now()->toImmutable()),
            'window' => $request->window(),
            'windows' => array_keys(AudienceRequest::WINDOWS),
            'enabled' => config('audience.enabled') === true,
        ]);
    }
}
