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
 * nommée hors du back-office.
 *
 * Trois sorts (amendé le 08/10) : un navigateur qui se déclare robot, outil
 * ou aperçu de lien n'est compté qu'en `bots`, par famille ; une visite
 * Inertia est comptée tout de suite (seul un JavaScript qui tourne l'émet) ;
 * un chargement complet reste **en attente** de la preuve JavaScript que la
 * page envoie une fois visible (`audience.seen`, {@see AudienceRecorder::confirm()}).
 *
 * Ne transmet à l'enregistreur que le nom de la route (jamais l'URL), le
 * domaine d'un référent externe, la langue et un type d'appareil grossier ;
 * l'adresse et le navigateur ne servent qu'à l'empreinte du jour, jamais
 * écrite telle quelle.
 */
class RecordVisit
{
    /**
     * Un navigateur qui se déclare robot, outil, sonde ou aperçu de lien.
     * Complétée le 08/10 : aperçus des messageries, clients HTTP des
     * langages, automates de navigateur, robots d'IA, scanners.
     */
    private const string BOT_PATTERN = '/bot|crawl|spider|slurp|curl|wget|python|httpclient|http-client|headless|preview|facebookexternalhit|monitor|uptime|lighthouse'
        .'|^whatsapp|slack-|embedly|iframely|vkshare|okhttp|axios|node-fetch|undici|java\/|libwww|scrapy|httpx|aiohttp|guzzle|postman|insomnia'
        .'|phantomjs|selenium|puppeteer|playwright|webdriver|pagespeed|gtmetrix|pingdom|statuscake|datadog|newrelic|nagios|zabbix|check_http|feedfetcher|mediapartners'
        .'|google-inspectiontool|googleother|bingpreview|semrush|ahrefs|petalbot|bytespider|gptbot|chatgpt|claude-|anthropic|perplexity|ccbot|diffbot'
        .'|ia_archiver|archive\.org|validator|nmap|nikto|masscan|zgrab|censys|shodan|sqlmap|nuclei/i';

    /** La famille d'un robot : le premier nom en « …bot », « …crawler » ou « …spider » de l'en-tête. */
    private const string BOT_NAME_PATTERN = '/[a-z][a-z0-9._-]*(?:bot|crawler|spider)\b/i';

    /** Longueur maximale d'une famille de robot. */
    private const int BOT_FAMILY_LENGTH = 40;

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

        if (! is_string($name) || $name === '' || str_starts_with($name, 'admin.') || $request->is('admin', 'admin/*')) {
            return;
        }

        $userAgent = (string) $request->userAgent();

        if (self::isBot($userAgent)) {
            $this->recorder->bot(self::botFamily($userAgent));

            return;
        }

        $arguments = [
            (string) $request->ip(),
            $userAgent,
            $name,
            self::referrerHost($request),
            app()->getLocale(),
            self::device($userAgent),
        ];

        $request->headers->has('X-Inertia')
            ? $this->recorder->pageView(...$arguments)
            : $this->recorder->pending(...$arguments);
    }

    /** Un `GET` rendu 200 en page, complet ou Inertia, ni partiel ni préchargé. */
    public static function isPageView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $isInertia = $response->headers->has('X-Inertia');
        $isHtml = str_contains((string) $response->headers->get('Content-Type'), 'text/html');

        return ($isInertia || $isHtml) && AdminJournal::countsAsVisit($request);
    }

    /** Un navigateur muet ou qui se déclare robot, outil, sonde ou aperçu. */
    public static function isBot(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1;
    }

    /** La famille d'un robot, en minuscules et bornée ; `empty` pour un en-tête muet. */
    public static function botFamily(string $userAgent): string
    {
        if (trim($userAgent) === '') {
            return 'empty';
        }

        if (preg_match(self::BOT_NAME_PATTERN, $userAgent, $name) === 1
            || preg_match(self::BOT_PATTERN, $userAgent, $name) === 1) {
            return mb_substr(mb_strtolower($name[0]), 0, self::BOT_FAMILY_LENGTH);
        }

        return 'other';
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
