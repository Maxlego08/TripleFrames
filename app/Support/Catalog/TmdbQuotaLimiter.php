<?php

namespace App\Support\Catalog;

use App\Models\ImportRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;

/**
 * L'étranglement de quota TMDB — **un limiteur de débit en code, pas une
 * table** (§ 9.2), **partagé en cache** entre tous les processus (spec 20
 * § 3.6, lot L20-24a).
 *
 * Le schéma ne porte que l'état reprenable : `import_run.last_request_at`,
 * `tmdb_page_cursor` et les quatre compteurs. C'est ce qui fait survivre
 * l'étranglement à un redémarrage de worker — au démarrage, l'horodatage du
 * dernier appel est relu et l'espacement reprend là où il s'était arrêté, au
 * lieu de repartir en rafale.
 *
 * **Partagé** : dès que plusieurs curateurs ou plusieurs workers `default`
 * existent, un débit tenu par chaque processus pour lui seul laisserait leur
 * somme dépasser le quota. Un **calendrier** commun vit donc dans le cache
 * (Redis en production) : l'instant du prochain créneau libre, en
 * microsecondes, lu et avancé sous un verrou atomique. Deux voies :
 *
 * - **balayage** ({@see self::throttle()}) — imports, collages,
 *   resynchronisations, rattrapages console : il prend le prochain créneau
 *   libre du calendrier commun et attend son tour ;
 * - **interactif** ({@see self::admit()} hors balayage) — recherche, visuels
 *   de l'éditeur, écran de resynchronisation, fiche lue par un geste : il
 *   **passe en priorité**, sans attendre derrière les créneaux déjà réservés
 *   par les balayages — il n'est espacé que des autres appels interactifs —
 *   et **repousse d'un créneau** le calendrier des balayages : la somme reste
 *   plafonnée.
 *
 * Chaque appel d'API du client TMDB passe par {@see self::admit()}
 * (`TmdbClient::get()`) ; un appel précédé de {@see self::throttle()} y est
 * déjà admis et n'est pas compté deux fois. D'où la liaison **singleton**
 * (`AppServiceProvider`) : la commande et le client partagent la même
 * instance. Le téléchargement d'un fichier image (CDN `image.tmdb.org`) n'est
 * pas un appel d'API et n'est pas compté.
 *
 * **Jamais bloquant pour le reste** : un cache dont le verrou ne répond pas
 * dans le délai laisse l'appel passer sous le seul espacement local, plutôt
 * qu'arrêter un import ou une recherche.
 *
 * **Ce n'est pas la reprise sur 429.** Le client TMDB retente déjà les 429 avec
 * un retrait exponentiel et obéit à `Retry-After`. Ce limiteur sert à ne pas
 * les déclencher : un balayage qui passe son temps en retrait est plus lent
 * qu'un balayage qui espace ses appels.
 *
 * `Sleep` est la façade Laravel, donc neutralisable par `Sleep::fake()` : aucun
 * test n'attend réellement.
 */
final class TmdbQuotaLimiter
{
    /** Défaut de `config('catalog.import.requests_per_second')`. */
    public const int DEFAULT_REQUESTS_PER_SECOND = 35;

    /** Prochain créneau libre des balayages, en microsecondes depuis l'époque. */
    public const string SWEEP_NEXT_KEY = 'tmdb-quota:sweep-next';

    /** Prochain créneau libre des appels interactifs. */
    public const string INTERACTIVE_NEXT_KEY = 'tmdb-quota:interactive-next';

    public const string LOCK_KEY = 'tmdb-quota:lock';

    /** Durée de vie du verrou, en secondes : une section critique dure quelques millisecondes. */
    private const int LOCK_SECONDS = 5;

    /** Attente maximale du verrou, en secondes, avant de passer sous le seul espacement local. */
    private const int LOCK_WAIT_SECONDS = 2;

    /**
     * Durée de vie d'un créneau en cache, en secondes : un créneau passé ne
     * contraint plus rien, la clé peut disparaître.
     */
    private const int SLOT_TTL_SECONDS = 600;

    private ?CarbonImmutable $lastRequestAt = null;

    /** Le prochain appel du client a déjà été admis par {@see self::throttle()}. */
    private bool $admitted = false;

    /**
     * Reprend l'espacement depuis l'état enregistré d'un balayage : un worker
     * redémarré ne repart jamais en rafale.
     */
    public function resumeFrom(ImportRun $run): void
    {
        $this->lastRequestAt = $run->last_request_at;
    }

    /**
     * À appeler **juste avant** chaque appel TMDB d'un balayage. Attend le
     * prochain créneau du calendrier commun — et au moins l'intervalle depuis
     * le dernier appel de ce processus —, puis note l'instant sur le
     * balayage : `last_request_at` est écrit en mémoire, la commande
     * l'enregistre avec le curseur.
     */
    public function throttle(?ImportRun $run = null): void
    {
        $interval = $this->minimumIntervalMicroseconds();

        if ($interval > 0) {
            $local = $this->lastRequestAt === null
                ? 0
                : self::micros($this->lastRequestAt) + $interval;

            $this->sleepUntil($this->reserveSweepSlot($interval, $local));
        }

        $this->lastRequestAt = CarbonImmutable::now();
        $this->admitted = true;

        if ($run instanceof ImportRun) {
            $run->last_request_at = $this->lastRequestAt;
        }
    }

    /**
     * Admission d'un appel d'API du client TMDB. Un appel déjà admis par
     * {@see self::throttle()} passe ; tout autre est **interactif** : il passe
     * avant les balayages, espacé des seuls autres appels interactifs, et
     * repousse d'un créneau le calendrier des balayages.
     */
    public function admit(): void
    {
        if ($this->admitted) {
            $this->admitted = false;

            return;
        }

        $interval = $this->minimumIntervalMicroseconds();

        if ($interval > 0) {
            $this->sleepUntil($this->reserveInteractiveSlot($interval));
        }
    }

    /**
     * Suspend le balayage le temps qu'un quota dépassé se recharge. Appelée sur
     * un 429 que le client n'a pas su absorber : `TmdbException::$retryAfterSeconds`
     * est la seule valeur qui connaisse la fenêtre réellement appliquée.
     */
    public function backOff(int $seconds): void
    {
        $seconds = min($seconds, $this->maximumBackOffSeconds());

        if ($seconds > 0) {
            Sleep::sleep($seconds);
        }

        $this->lastRequestAt = CarbonImmutable::now();
    }

    /**
     * Le créneau d'un appel de balayage : le premier instant libre du
     * calendrier commun, jamais avant `$notBefore` (espacement local).
     */
    private function reserveSweepSlot(int $interval, int $notBefore): int
    {
        return $this->underLock(
            static function () use ($interval, $notBefore): int {
                $slot = max(self::micros(CarbonImmutable::now()), $notBefore, self::cached(self::SWEEP_NEXT_KEY));

                Cache::put(self::SWEEP_NEXT_KEY, $slot + $interval, self::SLOT_TTL_SECONDS);

                return $slot;
            },
            fallback: $notBefore,
        );
    }

    /**
     * Le créneau d'un appel interactif : maintenant, ou après le dernier appel
     * interactif ; le calendrier des balayages recule d'un intervalle.
     */
    private function reserveInteractiveSlot(int $interval): int
    {
        return $this->underLock(
            static function () use ($interval): int {
                $slot = max(self::micros(CarbonImmutable::now()), self::cached(self::INTERACTIVE_NEXT_KEY));

                Cache::put(self::INTERACTIVE_NEXT_KEY, $slot + $interval, self::SLOT_TTL_SECONDS);
                Cache::put(
                    self::SWEEP_NEXT_KEY,
                    max($slot, self::cached(self::SWEEP_NEXT_KEY)) + $interval,
                    self::SLOT_TTL_SECONDS,
                );

                return $slot;
            },
            fallback: 0,
        );
    }

    /**
     * @param  callable(): int  $reserve
     */
    private function underLock(callable $reserve, int $fallback): int
    {
        try {
            $slot = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $reserve);
        } catch (LockTimeoutException) {
            return $fallback;
        }

        return is_int($slot) ? $slot : $fallback;
    }

    private function sleepUntil(int $slot): void
    {
        $wait = $slot - self::micros(CarbonImmutable::now());

        if ($wait > 0) {
            Sleep::usleep($wait);
        }
    }

    private static function cached(string $key): int
    {
        $value = Cache::get($key);

        return is_int($value) ? $value : 0;
    }

    private static function micros(CarbonImmutable $instant): int
    {
        return (int) $instant->getPreciseTimestamp();
    }

    /**
     * Le même plafond que celui du retrait exponentiel du client. Une fenêtre
     * annoncée par un tiers ne décide pas seule combien de temps un worker
     * reste immobile : un `Retry-After: 86400` l'immobiliserait 24 h.
     */
    private function maximumBackOffSeconds(): int
    {
        return max(1, Config::integer('services.tmdb.max_retry_after_seconds', 60));
    }

    /**
     * Intervalle minimal entre deux appels, en microsecondes. Un débit nul ou
     * négatif désactive l'étranglement — c'est la voie des tests.
     */
    private function minimumIntervalMicroseconds(): int
    {
        $perSecond = Config::integer(
            'catalog.import.requests_per_second',
            self::DEFAULT_REQUESTS_PER_SECOND,
        );

        return $perSecond > 0 ? (int) floor(1_000_000 / $perSecond) : 0;
    }
}
