<?php

namespace App\Support\Audience;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * La mesure d'audience, sans cookie — spec 100 § 10.12 (D48 du 01/10).
 *
 * Chaque page vue incrémente des **compteurs quotidiens** (`audience_daily`) ;
 * rien n'est écrit par visite. Le visiteur est une **empreinte du jour** —
 * `HMAC-SHA256(adresse | navigateur, sel du jour)` tronquée — dont le sel,
 * tiré au hasard, ne vit qu'en cache 48 h : l'adresse n'est jamais écrite,
 * et deux jours ne se relient pas. La visite (pause de moins de 30 minutes)
 * et le « déjà vu aujourd'hui » vivent aussi en cache seulement.
 *
 * Sortie = dernière page d'une visite : à chaque page vue d'une visite en
 * cours, le compteur de sortie passe de la page précédente à la nouvelle, au
 * jour où la visite a commencé.
 *
 * **Jamais bloquant** : tout échec est avalé ; appelé après la réponse.
 */
final class AudienceRecorder
{
    /** Une visite se clôt après cette pause, en minutes. */
    public const int VISIT_IDLE_MINUTES = 30;

    /** Un visiteur est « présent » s'il a vu une page dans cet intervalle. */
    public const int PRESENCE_MINUTES = 10;

    /** Longueur de l'empreinte retenue. */
    public const int HASH_LENGTH = 16;

    public const string METRIC_PAGEVIEWS = 'pageviews';

    public const string METRIC_VISITORS = 'visitors';

    public const string METRIC_VISITS = 'visits';

    public const string METRIC_ENTRIES = 'entries';

    public const string METRIC_EXITS = 'exits';

    public const string METRIC_REFERRERS = 'referrers';

    public const string METRIC_LOCALES = 'locales';

    public const string METRIC_DEVICES = 'devices';

    public function __construct(private readonly Cache $cache) {}

    public function enabled(): bool
    {
        return config('audience.enabled') === true;
    }

    /**
     * Une page vue.
     *
     * @param  string  $route  Nom de la route, jamais l'URL.
     * @param  string|null  $referrerHost  Domaine du référent externe, ou `null`.
     */
    public function pageView(
        string $ip,
        string $userAgent,
        string $route,
        ?string $referrerHost,
        string $locale,
        string $device,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        try {
            $now = Date::now()->toImmutable();
            $day = $now->toDateString();
            $visitor = $this->fingerprint($ip, $userAgent, $day);

            $this->increment($day, self::METRIC_PAGEVIEWS, $route);

            if ($this->cache->add('audience:seen:'.$day.':'.$visitor, 1, $now->addHours(48))) {
                $this->increment($day, self::METRIC_VISITORS);
                $this->increment($day, self::METRIC_LOCALES, $locale);
                $this->increment($day, self::METRIC_DEVICES, $device);
            }

            $this->trackVisit($visitor, $route, $day, $referrerHost, $now);
            $this->touchPresence($visitor, $route, $now);
        } catch (Throwable) {
            // Mesurer l'audience ne casse jamais une page (§ 10.12).
        }
    }

    /**
     * L'empreinte du jour : HMAC salé par le sel du jour, tiré au premier
     * usage et gardé en cache seulement.
     */
    public function fingerprint(string $ip, string $userAgent, string $day): string
    {
        $salt = $this->cache->get('audience:salt:'.$day);

        if (! is_string($salt) || $salt === '') {
            $candidate = bin2hex(random_bytes(32));
            $this->cache->add('audience:salt:'.$day, $candidate, Date::now()->addHours(48));
            $salt = $this->cache->get('audience:salt:'.$day);
            $salt = is_string($salt) && $salt !== '' ? $salt : $candidate;
        }

        return substr(hash_hmac('sha256', $ip.'|'.$userAgent, $salt), 0, self::HASH_LENGTH);
    }

    /**
     * Visite : nouvelle si aucune page depuis 30 minutes (entrée, sortie
     * provisoire, provenance) ; sinon la sortie passe à la nouvelle page.
     */
    private function trackVisit(string $visitor, string $route, string $day, ?string $referrerHost, CarbonImmutable $now): void
    {
        $key = 'audience:visit:'.$visitor;
        $visit = $this->cache->get($key);

        if (is_array($visit) && is_string($visit['route'] ?? null) && is_string($visit['day'] ?? null)) {
            $this->decrement($visit['day'], self::METRIC_EXITS, $visit['route']);
            $this->increment($visit['day'], self::METRIC_EXITS, $route);
            $this->cache->put($key, ['route' => $route, 'day' => $visit['day']], $now->addMinutes(self::VISIT_IDLE_MINUTES));

            return;
        }

        $this->increment($day, self::METRIC_VISITS);
        $this->increment($day, self::METRIC_ENTRIES, $route);
        $this->increment($day, self::METRIC_EXITS, $route);

        if ($referrerHost !== null) {
            $this->increment($day, self::METRIC_REFERRERS, $referrerHost);
        }

        $this->cache->put($key, ['route' => $route, 'day' => $day], $now->addMinutes(self::VISIT_IDLE_MINUTES));
    }

    /** La présence : upsert de l'empreinte, et effacement des absents. */
    private function touchPresence(string $visitor, string $route, CarbonImmutable $now): void
    {
        DB::table('audience_presence')->upsert(
            [['visitor_hash' => $visitor, 'route' => mb_substr($route, 0, 150), 'last_seen_at' => $now->format('Y-m-d H:i:s.v')]],
            ['visitor_hash'],
            ['route', 'last_seen_at'],
        );

        DB::table('audience_presence')
            ->where('last_seen_at', '<', $now->subMinutes(self::PRESENCE_MINUTES)->format('Y-m-d H:i:s.v'))
            ->delete();
    }

    private function increment(string $day, string $metric, string $dimension = ''): void
    {
        DB::table('audience_daily')->upsert(
            [['day' => $day, 'metric' => $metric, 'dimension' => mb_substr($dimension, 0, 150), 'total' => 1]],
            ['day', 'metric', 'dimension'],
            ['total' => DB::raw('total + 1')],
        );
    }

    private function decrement(string $day, string $metric, string $dimension): void
    {
        DB::table('audience_daily')
            ->where('day', $day)
            ->where('metric', $metric)
            ->where('dimension', mb_substr($dimension, 0, 150))
            ->where('total', '>', 0)
            ->decrement('total');
    }
}
