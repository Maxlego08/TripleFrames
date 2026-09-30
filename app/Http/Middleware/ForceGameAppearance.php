<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forçage sombre des pages `game/*` — moitié SERVEUR (spec 90 § 2.2, contrat
 * C16 § 2.2). Alias `game.appearance`, déclaré dans `bootstrap/app.php`.
 *
 * Toute page nommée `game/*` est forcée en sombre, et elle seule (principe
 * 13) : le lobby, le salon expiré et le solo, avec tous leurs états — manche,
 * révélation, podium —, qui ne sont jamais des pages distinctes. Le forçage
 * tient en trois moitiés, et une seule ne suffit pas :
 *
 * 1. ce middleware partage `appearance = dark` et `appearanceForced`, dont
 *    `app.blade.php` tire la classe `dark` et l'attribut
 *    `data-appearance-forced` de `<html>` au PREMIER chargement ;
 * 2. l'attribut dit au boot client (`initializeTheme()`) de ne pas reposer la
 *    préférence stockée par-dessus ;
 * 3. `useForcedAppearance('dark')`, appelé par `GameLayout`, couvre la
 *    navigation Inertia, qui ne repasse jamais par Blade.
 *
 * Le partage a lieu AVANT `$next` : la vue est rendue pendant que la requête
 * descend, et le middleware ne peut rien décider après coup. D'où la **règle
 * de groupe** : une route qui porte `game.appearance` ne rend QUE des pages
 * `game/*`. Une route qui devrait rendre soit une page de jeu, soit un
 * formulaire d'entrée (lien de salon ouvert sans siège) se découpe : elle
 * redirige le visiteur sans siège vers la page d'entrée `room/*`, hors du
 * groupe, qui suit l'apparence du visiteur. `ShellTest` garde la règle.
 *
 * Il est posé sur les routes de `routes/game.php` qui rendent les pages
 * `game/*` — `room.show` depuis le lot L50-3b, puis `solo.show` (L60-16) —,
 * APRÈS `HandleAppearance` (fin du groupe `web`), dont il supplante la valeur
 * lue dans le cookie. Une exception levée derrière lui rend la page publique
 * `error` : `ErrorPageResponder` remet alors l'apparence du visiteur (spec 90
 * § 4.8), pour qu'aucune page hors `game/*` ne soit jamais forcée.
 *
 * Il n'écrit aucune couleur : il ne fait que choisir le jeu de tokens `.dark`
 * de `resources/css/app.css`. Aucun SSR en v1 (`config/inertia.php`) : ce
 * partage est la seule moitié serveur.
 */
final class ForceGameAppearance
{
    /** L'apparence forcée des pages `game/*`. */
    public const string APPEARANCE = 'dark';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', self::APPEARANCE);
        View::share('appearanceForced', true);

        return $next($request);
    }
}
