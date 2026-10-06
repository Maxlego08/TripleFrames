<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\ResolveOAuthCallback;
use App\Enums\OAuthIntent;
use App\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Identity\OAuthOutcome;
use App\Support\Identity\OAuthProviders;
use App\Support\Identity\ProviderIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * L'aller-retour chez un fournisseur — `oauth.redirect` et `oauth.callback`
 * (spec 40 § 12.1 et § 12.2, D51 du 01/10).
 *
 * L'aller pose l'INTENTION en session (se connecter, lier, confirmer) et
 * renvoie au fournisseur ; le retour relit l'intention, interroge le
 * fournisseur, et traduit le verdict de {@see ResolveOAuthCallback} en
 * session et en redirection. Un fournisseur inactif répond 404 ; un retour
 * sans intention, refusé par l'utilisateur ou en erreur ramène à la
 * connexion (ou aux comptes liés) avec un message traduit, jamais une page
 * d'erreur.
 */
class OAuthController extends Controller
{
    /** Clé de session de l'intention posée à l'aller. */
    public const string INTENT_KEY = 'oauth.intent';

    /** Clé de session de l'inscription en attente (§ 12.3). */
    public const string PENDING_KEY = 'oauth.pending';

    /** Durée de vie d'une inscription en attente, en secondes. */
    public const int PENDING_TTL_SECONDS = 900;

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $resolved = self::enabledProvider($provider);
        $intent = OAuthIntent::tryFrom((string) $request->query('intent', OAuthIntent::Login->value)) ?? OAuthIntent::Login;
        $user = $request->user();

        if ($intent->requiresAccount() && ! $user instanceof User) {
            return to_route('login');
        }

        if ($intent === OAuthIntent::Login && $user instanceof User) {
            return redirect(Config::string('fortify.home'));
        }

        $request->session()->put(self::INTENT_KEY, [
            'provider' => $resolved->value,
            'intent' => $intent->value,
        ]);

        return Socialite::driver($resolved->value)->redirect();
    }

    public function callback(Request $request, string $provider, ResolveOAuthCallback $resolve): RedirectResponse
    {
        $resolved = self::enabledProvider($provider);
        $stored = $request->session()->pull(self::INTENT_KEY);
        $current = $request->user() instanceof User ? $request->user() : null;

        $intent = is_array($stored) && ($stored['provider'] ?? null) === $resolved->value
            ? OAuthIntent::tryFrom((string) ($stored['intent'] ?? ''))
            : null;

        if ($intent === null) {
            return self::refuse($current, OAuthOutcome::refused('failed'));
        }

        try {
            $identity = ProviderIdentity::fromSocialite($resolved, Socialite::driver($resolved->value)->user());
        } catch (Throwable) {
            return self::refuse($current, OAuthOutcome::refused('failed'));
        }

        $outcome = $resolve->handle($identity, $intent, $current);

        return match ($outcome->type) {
            OAuthOutcome::LOGGED_IN => self::logIn($request, $outcome),
            OAuthOutcome::TWO_FACTOR => self::challenge($request, $outcome),
            OAuthOutcome::LINKED => self::linked(),
            OAuthOutcome::CONFIRMED => self::confirmed($request),
            OAuthOutcome::PENDING => self::pending($request, $identity),
            default => self::refuse($current, $outcome),
        };
    }

    /**
     * Le fournisseur de l'URL, s'il est connu ET actif ; 404 sinon, pour qu'un
     * fournisseur sans clés n'existe pas.
     */
    public static function enabledProvider(string $provider): OAuthProvider
    {
        $resolved = OAuthProvider::tryFrom($provider);

        if ($resolved === null || ! OAuthProviders::isEnabled($resolved)) {
            throw new NotFoundHttpException;
        }

        return $resolved;
    }

    private static function logIn(Request $request, OAuthOutcome $outcome): RedirectResponse
    {
        if ($outcome->user !== null) {
            Auth::login($outcome->user);
            $request->session()->regenerate();
        }

        return redirect()->intended(Config::string('fortify.home'));
    }

    /**
     * Le défi de second facteur de Fortify, exactement comme après un mot de
     * passe : `login.id` en session, puis l'écran de défi.
     */
    private static function challenge(Request $request, OAuthOutcome $outcome): RedirectResponse
    {
        $request->session()->put([
            'login.id' => $outcome->user?->getKey(),
            'login.remember' => false,
        ]);

        return to_route('two-factor.login');
    }

    private static function linked(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.linked.linked')]);

        return to_route('linked_accounts.edit');
    }

    /** La confirmation fraîche que lit `password.confirm` (`RequirePassword`). */
    private static function confirmed(Request $request): RedirectResponse
    {
        $request->session()->put('auth.password_confirmed_at', Date::now()->getTimestamp());

        return redirect()->intended(route('security.edit'));
    }

    private static function pending(Request $request, ProviderIdentity $identity): RedirectResponse
    {
        $request->session()->put(self::PENDING_KEY, [
            'identity' => $identity->toArray(),
            'expires_at' => Date::now()->getTimestamp() + self::PENDING_TTL_SECONDS,
        ]);

        return to_route('oauth.finish');
    }

    private static function refuse(?User $current, OAuthOutcome $outcome): RedirectResponse
    {
        $message = __($outcome->refusalKey());
        $errors = ['oauth' => is_string($message) ? $message : $outcome->refusalKey()];

        return $current !== null
            ? to_route('linked_accounts.edit')->withErrors($errors)
            : to_route('login')->withErrors($errors);
    }
}
