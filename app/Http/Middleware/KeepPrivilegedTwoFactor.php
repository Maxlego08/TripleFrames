<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `two_factor.keep` — spec 40 § 13.8 (L40-15, n° 10 option A, D66 du
 * 07/10).
 *
 * La 2FA TOTP est obligatoire pour `curator` et `admin` (garde `admin.2fa`).
 * Un compte de ce rang ne la désactive donc pas lui-même : la route
 * `two-factor.disable` de Fortify lui est refusée, avec un message traduit
 * (`account.two_factor.errors.privileged_required`), jamais un 403 muet.
 * Il ne la remplace pas non plus : `two-factor.enable` avec `force` sur un
 * TOTP déjà confirmé écrirait un secret neuf sans jamais effacer
 * `two_factor_confirmed_at` (Fortify `EnableTwoFactorAuthentication`), donc
 * `admin.2fa` croirait confirmé un secret que personne n'a enrôlé ; ce cas
 * est refusé (`account.two_factor.errors.privileged_rotate`). Un `enable`
 * sans `force` sur un secret existant est sans effet chez Fortify et passe ;
 * la régénération des codes de récupération reste permise.
 * L'écran Sécurité masque le bouton (`canDisableTwoFactor`) ; cette garde est
 * la règle, le masquage n'en est que l'affichage.
 *
 * Posé sur le groupe de Fortify (`config/fortify.php` › `middleware`), qui
 * enregistre ses routes lui-même ; il agit par NOM de route, comme
 * `accounts.switches`. Il s'exécute avant `auth` : sans compte connecté, il
 * laisse passer et `auth` décide.
 *
 * Un compte qui perd son téléphone se connecte par un code de récupération ;
 * changer d'application d'authentification passe par un retrait de rôle
 * (`role.changed` vers `player`, qui ôte l'exigence), puis un nouvel
 * enrôlement et la restitution du rôle — geste d'un autre administrateur
 * (procédure de `docs/REPRISE.md`).
 */
class KeepPrivilegedTwoFactor
{
    /** La route de Fortify refusée aux rôles privilégiés. */
    public const string DISABLE_ROUTE = 'two-factor.disable';

    /** La route de Fortify refusée, avec `force`, à un TOTP privilégié confirmé. */
    public const string ENABLE_ROUTE = 'two-factor.enable';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $user = $request->user();

        if (! $route instanceof Route || ! $user instanceof User || self::canDisable($user)) {
            return $next($request);
        }

        $name = $route->getName();

        if ($name === self::DISABLE_ROUTE) {
            self::refuse('account.two_factor.errors.privileged_required');
        }

        if ($name === self::ENABLE_ROUTE
            && $request->boolean('force')
            && $user->two_factor_confirmed_at !== null) {
            self::refuse('account.two_factor.errors.privileged_rotate');
        }

        return $next($request);
    }

    /**
     * @throws ValidationException
     */
    private static function refuse(string $key): never
    {
        $message = __($key);

        throw ValidationException::withMessages([
            'two_factor' => [is_string($message) ? $message : $key],
        ]);
    }

    /** Vrai si le compte peut couper son second facteur : sous le seuil `curator`. */
    public static function canDisable(User $user): bool
    {
        return ! $user->role->atLeast(UserRole::Curator);
    }
}
