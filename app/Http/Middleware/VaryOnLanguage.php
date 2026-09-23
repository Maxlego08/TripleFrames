<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Vary: Accept-Language`, posé **uniquement** sur les pages qui le demandent —
 * l'accueil, et lui seul en v1 (spec 05 § « Pas de préfixe de locale dans les
 * URL »).
 *
 * Le site est public et indexé (décision 1) et l'accueil est négocié depuis
 * `Accept-Language` par {@see SetLocale}. Sans cet en-tête, un cache — celui du
 * navigateur, un proxy mal réglé, un CDN posé plus tard — sert à tous la
 * première langue qu'il a vue. Le `Set-Cookie` de la négociation collante
 * limite l'empoisonnement d'un cache partagé ; il ne dit rien au cache privé,
 * et rien à Googlebot.
 *
 * **Jamais sur les pages légales** : leur corps est mono-langue FR (décision 4),
 * elles portent `<html lang="fr">` quelle que soit la locale du visiteur et
 * doivent rester cachables telles quelles.
 *
 * **La décision appartient à la route**, par le défaut {@see self::ROUTE_FLAG} :
 * une liste de noms de routes vivant dans un middleware serait une seconde
 * table de routage. Le middleware, lui, n'apporte que la mécanique.
 *
 * **Il est en tête du groupe `web`, donc le dernier à toucher la réponse.**
 * `Inertia\Middleware` pose `Vary: X-Inertia` en écrasant l'en-tête : posé plus
 * bas dans l'oignon, celui-ci serait silencieusement effacé. Un `Vary` déjà
 * présent est conservé — l'en-tête est une union, et l'écraser retirerait une
 * dimension de cache que quelqu'un d'autre a jugée nécessaire.
 */
class VaryOnLanguage
{
    /** Défaut de route qui demande l'en-tête : `->defaults(...)`, sur la route elle-même. */
    public const string ROUTE_FLAG = 'vary_language';

    private const string HEADER = 'Accept-Language';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->wanted($request)) {
            // Une seule ligne d'en-tête, jamais un tableau : `setVary()` avec un
            // tableau émet autant de lignes `Vary` que d'éléments. C'est
            // équivalent pour un cache conforme, et illisible pour tout le reste.
            $response->headers->set('Vary', implode(', ', $this->merged($response->getVary())));
        }

        return $response;
    }

    private function wanted(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && ($route->defaults[self::ROUTE_FLAG] ?? false) === true;
    }

    /**
     * Les dimensions déjà déclarées, plus la nôtre, sans doublon — la
     * comparaison est insensible à la casse, les noms d'en-tête l'étant.
     *
     * @param  array<array-key, string>  $existing
     * @return list<string>
     */
    private function merged(array $existing): array
    {
        $dimensions = array_values($existing);

        foreach ($dimensions as $dimension) {
            if (strcasecmp(trim($dimension), self::HEADER) === 0) {
                return $dimensions;
            }
        }

        return [...$dimensions, self::HEADER];
    }
}
