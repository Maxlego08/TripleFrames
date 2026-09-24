<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La seconde authentification des rôles privilégiés — alias `admin.2fa`
 * (spec 20 § 2.4, mécanisme de `10` A16).
 *
 * Un compte `curator` ou `admin` dont le second facteur n'est pas CONFIRMÉ
 * (`two_factor_confirmed_at` nul) ne franchit pas la porte `/admin` : il est
 * renvoyé vers l'écran d'enrôlement `admin.two_factor.required`, qui explique
 * pourquoi la porte est fermée et mène à la sécurité du compte, où Fortify
 * enrôle avec confirmation (`config/fortify.php` : `'confirm' => true`). Un
 * secret posé mais jamais confirmé ne vaut rien : c'est la confirmation, et
 * elle seule, qui prouve que le téléphone du curateur produit les codes.
 *
 * **Jamais un 403 muet** (question 1 de `REPRISE.md`) : le curateur doit
 * savoir quoi faire, pas seulement qu'on lui refuse l'entrée.
 *
 * **Toujours une redirection 303**, lecture comme écriture : un POST
 * intercepté n'est jamais exécuté, et le navigateur suit la redirection en
 * GET — une 302 sur une écriture laisserait au client le choix de rejouer la
 * méthode d'origine vers l'écran d'enrôlement.
 *
 * **Place dans la pile**, posée par `bootstrap/app.php` et `routes/admin.php` :
 *
 * - APRÈS `role:curator` dans le groupe : un joueur reçoit 403 avant toute
 *   redirection d'enrôlement, qui confirmerait l'existence de la porte ;
 * - AVANT `SubstituteBindings` dans la liste de priorité, juste derrière
 *   `role` : sans ce rang, un curateur sans second facteur distinguerait par
 *   le couple 404 / redirection un identifiant de film inconnu d'un
 *   identifiant réel, et énumérerait la table `movie` sans jamais passer la
 *   porte.
 *
 * **Ce que la garde ne juge pas** : la manière dont la session s'est ouverte.
 * Elle suppose que tout chemin de connexion d'un compte à 2FA active passe le
 * défi (Fortify au J1 ; l'OAuth du J2 est tenu par la spec 40). Elle ne lit
 * que `two_factor_confirmed_at`.
 *
 * Un compte sous le seuil `curator` n'a rien à faire ici : la garde le laisse
 * passer sans rien décider, `role` l'a déjà refusé en amont. C'est le seuil de
 * rôle qui garde la porte ; cette garde n'en est que la serrure.
 */
class EnsurePrivilegedTwoFactor
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Même issue que `role` sans session : c'est le chemin du middleware
        // `auth`, redirection vers la connexion — jamais une redirection
        // d'enrôlement, qui ne mènerait nulle part.
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if ($user->role->atLeast(UserRole::Curator) && $user->two_factor_confirmed_at === null) {
            return redirect()->route('admin.two_factor.required', status: Response::HTTP_SEE_OTHER);
        }

        return $next($request);
    }
}
