<?php

namespace App\Http\Middleware;

use App\Support\Identity\AccountSwitches;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `accounts.switches` — spec 40 § 8.2.
 *
 * Ferme l'inscription et les passkeys SANS désenregistrer une seule route :
 * les helpers Wayfinder `register` et les actions des contrôleurs de
 * passkeys sont importés par le front et régénérés au build en CI, et une
 * route retirée selon l'environnement casserait la compilation TypeScript
 * ou produirait des assets différents selon la machine qui les construit.
 *
 * Posé sur le groupe de Fortify (`config/fortify.php` › `middleware`), qui
 * enregistre ses routes lui-même, et sur `well-known.passkeys`, déclarée
 * hors de ce groupe dans `routes/settings.php` : sans lui, cette route
 * annoncerait aux gestionnaires de mots de passe un point d'enrôlement de
 * passkey sur une production où les passkeys n'existent pas.
 *
 * Un 404, jamais un 403 : la fonction n'existe pas pour le visiteur, et le
 * code de retour ne doit rien apprendre de l'état du jalon. Il agit par NOM
 * de route, jamais par chemin : Fortify peut préfixer ses chemins
 * (`fortify.prefix`), jamais renommer ses routes.
 *
 * Il applique aussi le limiteur nommé `register` à l'envoi d'une inscription
 * OUVERTE (spec 40 § 13.1) : Fortify n'a aucune clé de limiteur
 * d'inscription, et la route doit rester enregistrée. Une inscription fermée
 * répond 404 sans rien compter.
 */
class EnforceAccountSwitches
{
    /** Routes fermées quand l'inscription l'est : l'écran et l'envoi. */
    public const array REGISTRATION_ROUTES = ['register', 'register.store'];

    /** L'envoi d'une inscription, que borne le limiteur {@see self::REGISTER_LIMITER}. */
    public const string REGISTER_SUBMIT_ROUTE = 'register.store';

    /** Le limiteur nommé de l'inscription, déclaré par `FortifyServiceProvider`. */
    public const string REGISTER_LIMITER = 'register';

    /** Préfixe de nom de toutes les routes de passkeys de Fortify. */
    public const string PASSKEY_ROUTE_PREFIX = 'passkey.';

    /** Point de découverte des passkeys, hors du groupe de Fortify. */
    public const string PASSKEY_DISCOVERY_ROUTE = 'well-known.passkeys';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if ($name !== null && self::closes($name)) {
            abort(404);
        }

        if ($name === self::REGISTER_SUBMIT_ROUTE) {
            return app(ThrottleRequests::class)->handle($request, $next, self::REGISTER_LIMITER);
        }

        return $next($request);
    }

    /** Vrai si la route nommée est fermée par l'état actuel des interrupteurs. */
    public static function closes(string $routeName): bool
    {
        if (in_array($routeName, self::REGISTRATION_ROUTES, true)) {
            return ! AccountSwitches::registrationOpen();
        }

        if ($routeName === self::PASSKEY_DISCOVERY_ROUTE || str_starts_with($routeName, self::PASSKEY_ROUTE_PREFIX)) {
            return ! AccountSwitches::passkeysEnabled();
        }

        return false;
    }
}
