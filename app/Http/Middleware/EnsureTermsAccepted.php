<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `terms.current` — ré-acceptation des CGU, spec 40 § 13.1 (Q40-6,
 * n° 12, n° 13, n° 39 ; D66 du 07/10).
 *
 * Un compte connecté dont `users.terms_version` diffère de
 * `config('legal.terms_version')` — version périmée, ou aucune, comme le
 * premier administrateur — est renvoyé vers l'interstitiel `terms.show`.
 * L'URL demandée est retenue (lecture seulement) pour y revenir après
 * l'acceptation.
 *
 * Posé sur les PAGES DE COMPTE seules (`routes/settings.php`, puis
 * historique et configurations). JAMAIS sur : le jeu (`routes/game.php`,
 * salons, solo — principe 10, le jeu n'est jamais bloqué), le back-office
 * (n° 13 : `/admin` en est exempté), la déconnexion, l'export et la
 * suppression du compte (droits du titulaire, exerçables sans accepter un
 * nouveau contrat), l'interstitiel lui-même, les pages légales, OAuth, la
 * vérification d'adresse et les routes de Fortify. Aucun e-mail n'annonce
 * un changement de version (n° 39) : le contrôle se fait à la page suivante.
 *
 * Corollaire de n° 13 : un compte `curator` ou `admin` n'est pas renvoyé
 * depuis l'écran Sécurité ({@see self::PRIVILEGED_EXEMPT_ROUTES}), où la
 * porte `admin.2fa` l'envoie enrôler son second facteur — sans quoi des CGU
 * non acceptées fermeraient le back-office par ce détour.
 *
 * Sans compte connecté, il laisse passer : `auth`, posé avant lui, décide.
 */
class EnsureTermsAccepted
{
    /** Le nom de l'interstitiel vers lequel le compte est renvoyé. */
    public const string INTERSTITIAL_ROUTE = 'terms.show';

    /**
     * Pages de compte ouvertes à un compte privilégié malgré des CGU non
     * acceptées : l'enrôlement du second facteur exigé par `admin.2fa`.
     *
     * @var list<string>
     */
    public const array PRIVILEGED_EXEMPT_ROUTES = ['security.edit', 'user-password.update'];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || self::isCurrent($user) || self::privilegedExempt($request, $user)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        return redirect()->route(self::INTERSTITIAL_ROUTE);
    }

    /** Vrai si le compte a accepté la version courante des CGU. */
    public static function isCurrent(User $user): bool
    {
        return $user->terms_version === Config::string('legal.terms_version');
    }

    private static function privilegedExempt(Request $request, User $user): bool
    {
        $route = $request->route();

        return $route instanceof Route
            && in_array($route->getName(), self::PRIVILEGED_EXEMPT_ROUTES, true)
            && $user->role->atLeast(UserRole::Curator);
    }
}
