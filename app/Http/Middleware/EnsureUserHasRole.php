<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Le seuil de rôle d'un GROUPE de routes : `->middleware('role:curator')`.
 *
 * Il ne remplace pas les policies, il les précède. Les policies gardent la
 * RESSOURCE (« ce balayage-ci est-il reprenable ? ») et sont posées route par
 * route par `can:` ; ce middleware garde la PORTE (« cette personne a-t-elle
 * affaire ici ? »). Les deux sont nécessaires, et pour une raison mesurable :
 * `can:view,movie` ne s'exécute qu'APRÈS la substitution de liaison, donc après
 * qu'un identifiant inconnu a déjà produit un 404. Un joueur distinguerait
 * alors « ce film existe » (403) de « ce film n'existe pas » (404) — une fuite
 * d'existence sur toute la table `movie`.
 *
 * D'où le rang de priorité posé dans `bootstrap/app.php` : ce middleware
 * s'exécute AVANT `SubstituteBindings`, et un joueur reçoit 403 dans les deux
 * cas. La liaison n'est jamais résolue pour qui n'a pas le droit de la voir.
 *
 * Deux issues, jamais confondues :
 *
 * - **non connecté** → `AuthenticationException`, donc exactement le chemin du
 *   middleware `auth` : redirection vers `route('login')` pour une requête de
 *   navigateur (la redirection de l'invité est enregistrée une fois pour toutes
 *   par `ApplicationBuilder`, sur l'exception elle-même), et 401 JSON pour un
 *   appel qui attend du JSON ;
 * - **connecté mais sous le seuil** → 403 sec, sans message et sans nommer la
 *   ressource.
 *
 * Le seuil est hiérarchique ({@see UserRole::atLeast()}) : `role:curator`
 * laisse passer un administrateur parce qu'il est au-dessus, jamais par une
 * exception écrite pour lui. C'est la même discipline que les policies, et le
 * projet s'interdit pour la même raison tout `Gate::before`.
 *
 * Un paramètre inconnu LÈVE plutôt que de refuser : `role:curateur` qui se
 * contenterait de renvoyer 403 fermerait le back-office en silence, et
 * `role:currator` qui laisserait passer l'ouvrirait en silence — les deux sont
 * pires qu'un plantage. Même parti que `TranslationDomains::need()`.
 */
class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $required = UserRole::tryFrom($role);

        if ($required === null) {
            throw new InvalidArgumentException(
                "Rôle inconnu : [{$role}]. Rôles de la hiérarchie : "
                .implode(', ', array_column(UserRole::cases(), 'value')).'.',
            );
        }

        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        if (! $user->role->atLeast($required)) {
            throw new AccessDeniedHttpException;
        }

        return $next($request);
    }
}
