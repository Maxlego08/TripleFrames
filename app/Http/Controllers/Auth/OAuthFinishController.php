<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\CreateOAuthAccount;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\OAuthFinishRequest;
use App\Support\I18n\Translations;
use App\Support\Identity\ProviderIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'écran « Finaliser l'inscription » — `oauth.finish` et
 * `oauth.finish.store` (spec 40 § 12.3, D51 du 01/10).
 *
 * L'inscription en attente vit en session, quinze minutes, et ne porte
 * aucun jeton du fournisseur. Le nom suggéré par le fournisseur n'est qu'un
 * préremplissage ; les CGU et l'âge sont acceptés ici, datés par
 * {@see CreateOAuthAccount}.
 */
class OAuthFinishController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $identity = self::pending($request);

        if ($identity === null) {
            return self::expired();
        }

        return Inertia::render('auth/oauth-finish', [
            'provider' => $identity->provider->value,
            'email' => $identity->email,
            'suggestedName' => $identity->suggestedName,
        ]);
    }

    public function store(OAuthFinishRequest $request, CreateOAuthAccount $create): RedirectResponse
    {
        $identity = self::pending($request);

        if ($identity === null) {
            return self::expired();
        }

        $locale = Locale::tryFrom(App::getLocale()) ?? Translations::fallback();
        $user = $create->handle($identity, $request->accountName(), $locale);

        $request->session()->forget(OAuthController::PENDING_KEY);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect(Config::string('fortify.home'));
    }

    /** L'identité en attente, si elle n'a pas expiré. */
    private static function pending(Request $request): ?ProviderIdentity
    {
        $pending = $request->session()->get(OAuthController::PENDING_KEY);

        if (! is_array($pending) || ! is_array($pending['identity'] ?? null)) {
            return null;
        }

        if (! is_int($pending['expires_at'] ?? null) || $pending['expires_at'] < Date::now()->getTimestamp()) {
            $request->session()->forget(OAuthController::PENDING_KEY);

            return null;
        }

        return ProviderIdentity::fromArray($pending['identity']);
    }

    private static function expired(): RedirectResponse
    {
        $message = __('account.oauth.errors.pending_expired');

        return to_route('login')->withErrors(['oauth' => is_string($message) ? $message : 'account.oauth.errors.pending_expired']);
    }
}
