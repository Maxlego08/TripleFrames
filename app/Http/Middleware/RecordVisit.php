<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminJournal;
use App\Support\Audience\AudienceRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compte une page vue pour la mesure d'audience — spec 100 § 10.12
 * (D48 du 01/10).
 *
 * Middleware du groupe `web`, **terminable** : rien ne se passe avant
 * l'envoi de la réponse. Une page vue est un `GET` rendu 200, chargement
 * complet ou visite Inertia — jamais un rechargement partiel ni un
 * préchargement ({@see AdminJournal::countsAsVisit()}) —, sur une route
 * nommée hors du back-office, par un navigateur qui ne se déclare pas robot.
 *
 * Ne transmet à l'enregistreur que le nom de la route (jamais l'URL), le
 * domaine d'un référent externe, la langue et un type d'appareil grossier ;
 * l'adresse et le navigateur ne servent qu'à l'empreinte du jour, jamais
 * écrite telle quelle.
 */
class RecordVisit
{
    /** Un navigateur qui se déclare robot, outil ou aperçu de lien. */
    private const string BOT_PATTERN = '/bot|crawl|spider|slurp|curl|wget|python|httpclient|headless|preview|facebookexternalhit|monitor|uptime|lighthouse/i';

    public function __construct(private readonly AudienceRecorder $recorder) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->recorder->enabled() || ! self::isPageView($request, $response)) {
            return;
        }

        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        if (! is_string($name) || $name === '' || str_starts_with($name, 'admin.')) {
            return;
        }

        $this->recorder->pageView(
            (string) $request->ip(),
            (string) $request->userAgent(),
            $name,
            self::referrerHost($request),
            app()->getLocale(),
            self::device((string) $request->userAgent()),
        );
    }

    /** Un `GET` rendu 200 en page, complet ou Inertia, ni partiel ni préchargé, par un humain. */
    public static function isPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $userAgent = (string) $request->userAgent();

        if ($userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return false;
        }

        $isInertia = $response->headers->has('X-Inertia');
        $isHtml = str_contains((string) $response->headers->get('Content-Type'), 'text/html');

        return ($isInertia || $isHtml) && AdminJournal::countsAsVisit($request);
    }

    /** Le domaine d'un référent externe, sans chemin ni requête ; `null` pour un référent interne. */
    public static function referrerHost(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');
        $host = is_string($referrer) ? parse_url($referrer, PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = mb_strtolower(preg_replace('/^www\./i', '', $host) ?? $host);

        return $host === mb_strtolower(preg_replace('/^www\./i', '', $request->getHost()) ?? $request->getHost())
            ? null
            : mb_substr($host, 0, 150);
    }

    /** `mobile`, `tablet` ou `desktop`, d'après le seul en-tête du navigateur. */
    public static function device(string $userAgent): string
    {
        if (preg_match('/ipad|tablet|kindle|silk|(android(?!.*mobile))/i', $userAgent) === 1) {
            return 'tablet';
        }

        return preg_match('/mobi|iphone|ipod|android/i', $userAgent) === 1 ? 'mobile' : 'desktop';
    }
}
