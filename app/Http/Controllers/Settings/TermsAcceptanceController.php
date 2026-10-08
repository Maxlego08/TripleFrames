<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\RecordConsents;
use App\Enums\ConsentKind;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTermsAccepted;
use App\Http\Requests\Settings\TermsAcceptanceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'interstitiel de ré-acceptation des CGU — spec 40 § 13.1 (Q40-6, n° 12,
 * n° 13, n° 39 ; D66 du 07/10).
 *
 * Accepter écrit, par l'écrivain unique {@see RecordConsents}, une ligne
 * `terms` à la version courante — et `age` si le compte n'a jamais déclaré
 * son âge —, puis les projections, et rend la page demandée. Ne pas accepter
 * laisse le compte suspendu de ses fonctions : l'écran n'offre que la
 * déconnexion (et, quand ils existent, l'export et la suppression) ; le jeu
 * reste ouvert.
 */
class TermsAcceptanceController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || EnsureTermsAccepted::isCurrent($user)) {
            return redirect()->intended(Config::string('fortify.home'));
        }

        return Inertia::render('auth/terms-update', [
            // Première acceptation (aucune version), ou nouvelle version.
            'firstAcceptance' => $user->terms_version === null,
            'asksAge' => $user->age_confirmed_at === null,
        ]);
    }

    public function store(TermsAcceptanceRequest $request, RecordConsents $consents): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User && ! EnsureTermsAccepted::isCurrent($user)) {
            $consents->handle($user, $request->asksAge()
                ? [ConsentKind::Terms, ConsentKind::Age]
                : [ConsentKind::Terms]);
        }

        return redirect()->intended(Config::string('fortify.home'));
    }
}
