<?php

namespace App\Http\Middleware;

use App\Enums\LegalPage;
use App\Support\Http\PageMeta;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Directives d'indexation et de référent, sur **toute** réponse de
 * l'application (spec 90 § 5.2, tableau route par route au § 5.3).
 *
 * **`X-Robots-Tag: noindex, nofollow` par défaut.** Au jalon 1, le site est
 * intégralement `noindex` (D30 du 23/09). L'en-tête n'est omis que si les
 * quatre conditions sont réunies à la fois :
 *
 * 1. `config('app.indexable') === true` — la variable de PRODUCTION
 *    `SITE_INDEXABLE`, jamais une valeur du dépôt (spec 100 § 12). Comparaison
 *    stricte : une clé absente, nulle ou d'un autre type garde le `noindex` ;
 * 2. la route porte le défaut {@see self::ROUTE_FLAG} à `true`, posé sur la
 *    route elle-même (`->defaults(RobotsDirectives::ROUTE_FLAG, true)`), sur
 *    le patron de {@see VaryOnLanguage::ROUTE_FLAG}. Seuls `home`,
 *    `legal.notice`, `legal.terms` et `legal.privacy` y ont droit — jamais un
 *    lien de salon, une page de jeu, « signaler un contenu », le back-office
 *    ni une route technique ; `IndexingTest` refuse tout autre porteur ;
 * 3. la méthode est `GET` ou `HEAD` ;
 * 4. le statut est 200 : une erreur sur une route indexable reste `noindex`.
 *
 * **Garde des pages provisoires** (n° 19, spec 90 § 11.5, spec 100 § 21 —
 * D66 du 07/10) : une page légale dont `config('legal.pages.<page>.provisional')`
 * n'est pas `false` reste `noindex` même quand la variable est levée, pour
 * qu'un texte à « [À FOURNIR » ne soit jamais indexé si `SITE_INDEXABLE`
 * passe à vrai avant que tous les textes soient livrés.
 *
 * Les conditions 1, 2 et la garde forment {@see self::routeIndexable()},
 * seule décision partagée avec la balise canonique de `app.blade.php`
 * ({@see PageMeta}) : une canonique n'existe que là où
 * l'en-tête peut tomber.
 *
 * **Il n'écrase jamais un `X-Robots-Tag` déjà posé** : la réponse d'image de
 * `/f/{serveToken}` et de l'aperçu admin pose le sien (`FrameImageResponse`,
 * contrats C8 et C9, E10-59), et c'est elle qui fait foi.
 *
 * **`Referrer-Policy: strict-origin-when-cross-origin`** sur toute réponse qui
 * n'en porte pas déjà une (`00` § Ouverture, conformité et gouvernance,
 * point 5) : un lien de salon suivi vers un site tiers ne lui transmet que
 * l'origine, jamais le chemin, donc jamais son `room_code`.
 *
 * **L'en-tête seul, jamais une balise `<meta name="robots">`** : la vue est
 * rendue avant que ce middleware voie le statut, et deux sources qui
 * recalculent la même décision à deux moments du cycle finissent par diverger.
 *
 * **Middleware GLOBAL, en tête de la pile** (`bootstrap/app.php`), jamais du
 * groupe `web` : une URL inconnue lève avant tout middleware de route, et le
 * mode maintenance, l'encodage de chemin invalide ou un corps trop lourd
 * répondent depuis des middlewares globaux qui précèdent la pile de route.
 * Placé en tête, il est le dernier à toucher la réponse et les voit toutes.
 * Il n'agit qu'**après** `$next` : la route est alors résolue — ou absente,
 * ce qui vaut `noindex` — et le statut connu.
 */
final class RobotsDirectives
{
    /** Défaut de route qui rend la route indexable une fois l'indexation levée. */
    public const string ROUTE_FLAG = 'indexable';

    public const string NOINDEX = 'noindex, nofollow';

    public const string REFERRER_POLICY = 'strict-origin-when-cross-origin';

    private const string ROBOTS_HEADER = 'X-Robots-Tag';

    private const string REFERRER_HEADER = 'Referrer-Policy';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has(self::ROBOTS_HEADER) && ! $this->indexable($request, $response)) {
            $response->headers->set(self::ROBOTS_HEADER, self::NOINDEX);
        }

        if (! $response->headers->has(self::REFERRER_HEADER)) {
            $response->headers->set(self::REFERRER_HEADER, self::REFERRER_POLICY);
        }

        return $response;
    }

    /**
     * Les quatre conditions de la levée, toutes requises.
     */
    private function indexable(Request $request, Response $response): bool
    {
        return self::routeIndexable($request)
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && $response->getStatusCode() === Response::HTTP_OK;
    }

    /**
     * La part de la décision qui ne dépend que de la route : variable levée,
     * drapeau posé à `true`, et page légale non provisoire. La méthode et le
     * statut restent au middleware, seul à voir la réponse.
     */
    public static function routeIndexable(Request $request): bool
    {
        if (config('app.indexable') !== true) {
            return false;
        }

        $route = $request->route();

        if (! $route instanceof Route || ($route->defaults[self::ROUTE_FLAG] ?? false) !== true) {
            return false;
        }

        return LegalPage::fromRouteName($route->getName())?->isProvisional() !== true;
    }
}
