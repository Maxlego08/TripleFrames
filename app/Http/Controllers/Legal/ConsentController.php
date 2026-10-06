<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Legal\ConsentRequest;
use App\Support\Visitor\ConsentChoice;
use App\Support\Visitor\ConsentCookie;
use App\Support\Visitor\VisitorTracker;
use Illuminate\Http\RedirectResponse;

/**
 * Enregistrer le choix de la bannière de consentement (D62 du 06/10, spec 90
 * § 4.6) : accepter dépose l'identifiant de visiteur, refuser le retire et
 * supprime le visiteur. Le choix lui-même est mémorisé par le cookie
 * `consent`. Retour à la page d'où le choix est parti, sans autre effet.
 */
class ConsentController extends Controller
{
    public function store(ConsentRequest $request, VisitorTracker $visitors): RedirectResponse
    {
        $choice = $request->choice();

        $visitorCookie = $choice === ConsentChoice::Accepted
            ? $visitors->accept($request)
            : $visitors->refuse($request);

        return back()
            ->withCookie(ConsentCookie::make($choice))
            ->withCookie($visitorCookie);
    }
}
