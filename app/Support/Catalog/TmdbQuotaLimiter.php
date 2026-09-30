<?php

namespace App\Support\Catalog;

use App\Models\ImportRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;

/**
 * L'étranglement de quota TMDB — **un limiteur de débit en code, pas une
 * table** (§ 9.2).
 *
 * Le schéma ne porte que l'état reprenable : `import_run.last_request_at`,
 * `tmdb_page_cursor` et les quatre compteurs. C'est ce qui fait survivre
 * l'étranglement à un redémarrage de worker — au démarrage, l'horodatage du
 * dernier appel est relu et l'espacement reprend là où il s'était arrêté, au
 * lieu de repartir en rafale.
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

    private ?CarbonImmutable $lastRequestAt = null;

    /**
     * Reprend l'espacement depuis l'état enregistré d'un balayage : un worker
     * redémarré ne repart jamais en rafale.
     */
    public function resumeFrom(ImportRun $run): void
    {
        $this->lastRequestAt = $run->last_request_at;
    }

    /**
     * À appeler **juste avant** chaque appel TMDB. Attend le temps qu'il faut
     * pour tenir le débit, puis note l'instant sur le balayage — `last_request_at`
     * est écrit en mémoire, la commande l'enregistre avec le curseur.
     */
    public function throttle(?ImportRun $run = null): void
    {
        $interval = $this->minimumIntervalMicroseconds();

        if ($this->lastRequestAt !== null && $interval > 0) {
            $elapsed = (int) $this->lastRequestAt->diffInMicroseconds(CarbonImmutable::now(), true);

            if ($elapsed < $interval) {
                Sleep::usleep($interval - $elapsed);
            }
        }

        $this->lastRequestAt = CarbonImmutable::now();

        if ($run instanceof ImportRun) {
            $run->last_request_at = $this->lastRequestAt;
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
