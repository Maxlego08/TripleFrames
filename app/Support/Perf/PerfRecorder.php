<?php

namespace App\Support\Perf;

use App\Http\Middleware\MeasureRequest;
use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * La mesure des requêtes HTTP et des jobs — spec 100 § 10.11 (D47 du 01/10).
 *
 * Une **portée** s'ouvre au début d'une requête du groupe `web`
 * ({@see MeasureRequest}) ou d'un job (événements de
 * file), et se referme en écrivant, si elle est échantillonnée, une ligne
 * `perf_sample` et ses requêtes lentes. Les portées s'empilent : un job
 * exécuté dans une requête (file `sync`) compte ses requêtes pour lui ET pour
 * la requête qui l'enveloppe.
 *
 * Un seul écouteur `DB::listen` alimente la portée courante ; les requêtes
 * que la mesure écrit elle-même ne sont jamais comptées.
 *
 * **Jamais bloquant, jamais de contenu** : tout échec d'écriture est avalé ;
 * une requête lente n'est retenue que par son SQL à paramètres, jamais ses
 * valeurs liées. Singleton du conteneur, inerte si `perf.enabled` est faux.
 */
final class PerfRecorder
{
    /**
     * @var list<array{kind: string, method: string|null, queue: string|null, wait_ms: int|null, started: int, queries: int, query_ms: float, slow: list<array{sql: string, ms: int}>, sampled: bool}>
     */
    private array $scopes = [];

    /** Vrai pendant que la mesure écrit ses propres lignes. */
    private bool $writing = false;

    public function enabled(): bool
    {
        return config('perf.enabled') === true;
    }

    /**
     * Ouvre une portée. L'échantillonnage se décide ici, une fois.
     */
    public function begin(string $kind, ?string $method = null, ?string $queue = null, ?int $waitMs = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $rate = config('perf.sample_rate');
        $rate = is_numeric($rate) ? (float) $rate : 1.0;

        $this->scopes[] = [
            'kind' => $kind,
            'method' => $method,
            'queue' => $queue,
            'wait_ms' => $waitMs,
            'started' => self::nowNs(),
            'queries' => 0,
            'query_ms' => 0.0,
            'slow' => [],
            'sampled' => $rate >= 1.0 || (mt_rand() / mt_getrandmax()) < $rate,
        ];
    }

    /** Vrai si une portée est ouverte. */
    public function active(): bool
    {
        return $this->scopes !== [];
    }

    /**
     * L'écouteur `DB::listen` : compte la requête dans chaque portée ouverte,
     * et la retient comme lente dans la portée courante au-delà du seuil.
     */
    public function query(QueryExecuted $query): void
    {
        if ($this->writing || $this->scopes === []) {
            return;
        }

        $threshold = config('perf.slow_query_ms');
        $threshold = is_numeric($threshold) ? (float) $threshold : 100.0;
        $max = config('perf.slow_queries_per_sample');
        $max = is_int($max) ? $max : 20;

        foreach (array_keys($this->scopes) as $index) {
            $this->scopes[$index]['queries']++;
            $this->scopes[$index]['query_ms'] += $query->time;
        }

        $top = array_key_last($this->scopes);

        if ($query->time >= $threshold && count($this->scopes[$top]['slow']) < $max) {
            $this->scopes[$top]['slow'][] = [
                'sql' => mb_substr($query->sql, 0, 2000),
                'ms' => (int) round($query->time),
            ];
        }
    }

    /**
     * Les compteurs de la portée courante, sans la fermer : durée écoulée et
     * requêtes SQL. `null` hors portée.
     *
     * @return array{duration_ms: int, query_count: int}|null
     */
    public function snapshot(): ?array
    {
        $top = array_key_last($this->scopes);

        if ($top === null) {
            return null;
        }

        return [
            'duration_ms' => self::elapsedMs($this->scopes[$top]['started']),
            'query_count' => $this->scopes[$top]['queries'],
        ];
    }

    /**
     * Ferme la portée courante et écrit son échantillon s'il est retenu.
     * Rend l'échantillon écrit, ou `null`.
     */
    public function finish(string $name, string $status): ?PerfSample
    {
        $scope = array_pop($this->scopes);

        if ($scope === null || ! $scope['sampled']) {
            return null;
        }

        $this->writing = true;

        try {
            $now = Date::now()->toImmutable();

            $sample = new PerfSample;
            $sample->kind = $scope['kind'];
            $sample->name = mb_substr($name, 0, 150);
            $sample->method = $scope['method'];
            $sample->queue = $scope['queue'] === null ? null : mb_substr($scope['queue'], 0, 20);
            $sample->status = mb_substr($status, 0, 12);
            $sample->duration_ms = self::elapsedMs($scope['started']);
            $sample->query_count = min($scope['queries'], 65_535);
            $sample->query_ms = (int) round($scope['query_ms']);
            $sample->memory_kb = intdiv(memory_get_peak_usage(true), 1024);
            $sample->wait_ms = $scope['wait_ms'];
            $sample->recorded_at = $now;
            $sample->save();

            foreach ($scope['slow'] as $slow) {
                $line = new PerfSlowQuery;
                $line->perf_sample_id = $sample->id;
                $line->sql_text = $slow['sql'];
                $line->sql_hash = sha1($slow['sql']);
                $line->duration_ms = $slow['ms'];
                $line->recorded_at = $now;
                $line->save();
            }

            return $sample;
        } catch (Throwable) {
            // Mesurer ne casse jamais une requête ni un job (§ 10.11).
            return null;
        } finally {
            $this->writing = false;
        }
    }

    /**
     * Exécute `$write` sans compter ses requêtes : les écritures de la
     * chronologie de partie ne gonflent pas la portée qu'elles décrivent.
     *
     * @param  callable(): void  $write
     */
    public function silently(callable $write): void
    {
        $previous = $this->writing;
        $this->writing = true;

        try {
            $write();
        } finally {
            $this->writing = $previous;
        }
    }

    /** Oublie toutes les portées ouvertes (fin de job en échec, tests). */
    public function reset(): void
    {
        $this->scopes = [];
        $this->writing = false;
    }

    /**
     * L'horloge monotone en nanosecondes. `hrtime(true)` rend un flottant
     * sur une plateforme 32 bits seulement ; le projet tourne en 64 bits.
     */
    public static function nowNs(): int
    {
        $now = hrtime(true);

        return is_int($now) ? $now : (int) round($now);
    }

    /** Millisecondes entières écoulées depuis un instant {@see self::nowNs()}. */
    public static function elapsedMs(int $startedNs): int
    {
        return max(0, intdiv(self::nowNs() - $startedNs, 1_000_000));
    }

    /** Millisecondes entières écoulées entre deux instants, signées. */
    public static function diffMs(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv((int) $to->format('Uu') - (int) $from->format('Uu'), 1000);
    }
}
