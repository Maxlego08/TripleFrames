<?php

namespace App\Support\Http;

use App\Enums\ErrorPageStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Support\I18n\TranslationDomains;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Inertia\ExceptionResponse;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Rendu des erreurs HTTP en pages traduites — spec 90 § 4.8, contrat C15
 * § 2.3. Branché par `Inertia::handleExceptionsUsing()` dans
 * `bootstrap/app.php › withExceptions()`, **hors mode debug** : en debug, la
 * page d'erreur du framework reste, avec sa trace.
 *
 * - Requête qui attend du JSON ({@see self::expectsJson()}, la même règle que
 *   `shouldRenderJsonWhen`) : la réponse JSON du framework, jamais la page. Le
 *   client de jeu traite ses codes lui-même (409, 429 : specs 60 et 70).
 * - 419 en visite Inertia : retour à la page précédente, avec un flash
 *   `toast` dont le message est résolu ici, dans la langue du visiteur
 *   (destinataire unique, spec 05 § Erreurs). Sur une page de jeu, ce flash
 *   est rendu en texte et annoncé, jamais en toast (spec 90 § 2.3).
 * - Les six statuts d'{@see ErrorPageStatus} sinon, 419 d'un chargement
 *   complet compris : la page `error`, au statut d'origine — ou la page
 *   `admin/error` (spec 20) si le domaine `admin` était sélectionné avant
 *   l'exception.
 * - Tout autre statut (401, 405, 422, redirections…) : la réponse du
 *   framework, intacte.
 *
 * **Pourquoi les props partagées et une locale résolues ici.** Les erreurs
 * les plus fréquentes naissent AVANT `SetLocale` et `HandleInertiaRequests`,
 * ajoutés en fin de groupe `web` : une URL inconnue ne traverse aucun groupe,
 * une liaison de route introuvable (code de salon inconnu), `role:curator`,
 * `throttle:*`, le jeton CSRF et le mode maintenance lèvent avant eux. Or
 * `HandleInertiaRequests` est le seul à poser `locale`, `locales`,
 * `translations` et `name` : sans eux, `PublicLayout` plante et la page n'est
 * ni rendue ni traduite (règle 4). D'où, avant le rendu de la page joueur :
 * la locale par {@see SetLocale::resolve()}, le domaine `legal`, et la remise
 * à zéro de l'apparence. Pour `admin/error`, la remise à zéro seule : la
 * locale reste le `fr` de `ForceAdminLocale`, le domaine `admin` seul, qui
 * n'existe qu'en français.
 *
 * **Jamais une seconde panne.** Si le rendu de la page échoue à son tour
 * (base injoignable, vue introuvable), l'échec est rapporté et la réponse du
 * framework part telle quelle : une exception levée ici remonterait hors du
 * noyau et ne laisserait au visiteur qu'une page blanche.
 */
final class ErrorPageResponder
{
    /** Page Inertia des erreurs joueur, dans `PublicLayout`. */
    public const string PAGE = 'error';

    /** Page Inertia des erreurs du back-office, écrite par la spec 20. */
    public const string ADMIN_PAGE = 'admin/error';

    public function __construct(private readonly SetLocale $locales) {}

    /**
     * Vrai si la requête attend du JSON : routes `api/*`, ou `expectsJson()`
     * (appels `useHttp()` et soumissions de jeu). Une visite Inertia envoie
     * `Accept: text/html, application/xhtml+xml` : elle n'en fait pas partie.
     */
    public static function expectsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function __invoke(ExceptionResponse $response): ?Response
    {
        $request = $response->request;
        $status = ErrorPageStatus::tryFrom($response->statusCode());

        if ($status === null || (bool) config('app.debug') || self::expectsJson($request)) {
            return null;
        }

        try {
            return $this->respond($response, $request, $status);
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }
    }

    private function respond(ExceptionResponse $response, Request $request, ErrorPageStatus $status): Response
    {
        // Lu AVANT toute remise à zéro : `selected()` rend `['admin']` seul
        // dès que `ForceAdminLocale` est passé.
        $admin = in_array(TranslationDomains::ADMIN, app(TranslationDomains::class)->selected(), true);

        // Étape 1 — locale : celle du visiteur, résolue sans effet de bord.
        // Le back-office garde le `fr` posé par `ForceAdminLocale` : réimposer
        // la locale du visiteur chargerait un dictionnaire `admin` vide.
        if (! $admin) {
            App::setLocale($this->locales->resolve($request)->value);
        }

        if ($status === ErrorPageStatus::PageExpired && $request->hasHeader(Header::INERTIA) && $request->hasSession()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __($status->descriptionKey()),
            ]);

            // 303 et non 302 : la réponse ne repasse pas par
            // `HandleInertiaRequests`, qui convertit d'ordinaire le 302 d'un
            // PUT, PATCH ou DELETE — que le navigateur rejouerait sinon avec
            // la même méthode.
            return back(Response::HTTP_SEE_OTHER);
        }

        // Étape 3 — apparence : celle du visiteur. `ForceGameAppearance`
        // partage l'apparence forcée AVANT le contrôleur (spec 90 § 2.2) :
        // sans cette remise à zéro, une exception levée dans une route de jeu
        // rendrait une page hors `game/*` en sombre forcé, et aucun
        // `useForcedAppearance` monté ne retirerait ensuite l'attribut.
        $appearance = $request->cookie('appearance');

        View::share('appearanceForced', false);
        View::share('appearance', is_string($appearance) ? $appearance : 'system');

        if ($admin) {
            return $this->page($response, self::ADMIN_PAGE, $status);
        }

        // Étape 2 — domaines : `common` et `legal` exactement (spec 90 § 6.3,
        // pied de page sur tous les écrans). Une sélection neuve, et non un
        // simple ajout : une exception levée dans une route de jeu, qui a déjà
        // déclaré `game` et `room`, ne les expédie pas à une page qui ne les
        // appelle jamais.
        $domains = new TranslationDomains;
        $domains->need('legal');
        App::instance(TranslationDomains::class, $domains);

        return $this->page($response, self::PAGE, $status);
    }

    /**
     * La page Inertia, au statut d'origine, avec les props partagées de
     * `HandleInertiaRequests`.
     */
    private function page(ExceptionResponse $response, string $component, ErrorPageStatus $status): Response
    {
        $rendered = $response
            ->render($component, ['status' => $status->value])
            ->usingMiddleware(HandleInertiaRequests::class)
            ->withSharedData()
            ->toResponse($response->request);

        // Les en-têtes de l'exception d'origine — `Retry-After` d'un 429 ou
        // du mode maintenance — que la réponse du framework aurait portés.
        if ($response->exception instanceof HttpExceptionInterface) {
            $rendered->headers->add($response->exception->getHeaders());
        }

        // Comme toute réponse du middleware Inertia : la même URL sert une
        // page complète ou une charge JSON selon cet en-tête, et un cache de
        // navigation ne doit jamais rendre l'une pour l'autre.
        $rendered->headers->set('Vary', Header::INERTIA);

        return $rendered;
    }
}
