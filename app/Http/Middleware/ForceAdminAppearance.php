<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le back-office est forcé en thème CLAIR, exactement comme l'écran de jeu sera
 * forcé en sombre : deux forçages symétriques, tous deux par les **tokens de
 * thème**, jamais par une couleur en dur. Le sélecteur d'apparence du site
 * public n'est pas touché.
 *
 * Moitié serveur du forçage. Les middlewares de ROUTE s'exécutant après
 * {@see HandleAppearance} du groupe `web`, la valeur partagée ici écrase bien
 * celle du cookie ; `resources/views/app.blade.php` n'émet alors pas la classe
 * `dark`, et son script en ligne ne fait rien, puisqu'il n'ajoute `dark` que
 * pour `appearance === 'system'`.
 *
 * **Elle ne suffit PAS à elle seule, et le prétendre serait faux.**
 * `resources/js/app.tsx` appelle `initializeTheme()` au chargement du module,
 * donc après Blade : sans marqueur, cette fonction relit `localStorage` et
 * repose `dark` quelques microtâches plus tard, ce qui peint la page en noir
 * jusqu'au montage du layout. D'où la seconde valeur partagée ici,
 * `appearanceForced` : Blade en tire l'attribut `data-appearance-forced` sur
 * `<html>`, `initializeTheme()` le lit et applique le forçage **sans jamais
 * écrire dans `localStorage` ni dans le cookie**. La préférence du site public
 * reste donc intacte, et il n'y a pas de clignotement.
 *
 * La troisième moitié est cliente et vit dans `use-forced-appearance.ts` : une
 * navigation Inertia depuis une page joueur ne repasse jamais par Blade. C'est
 * ce hook qui pose et retire l'attribut au fil des montages.
 *
 * Symétrique de {@see ForceAdminLocale}, posé par l'alias `admin.appearance`.
 */
class ForceAdminAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', 'light');
        View::share('appearanceForced', true);

        return $next($request);
    }
}
