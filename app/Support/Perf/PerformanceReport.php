<?php

namespace App\Support\Perf;

use App\Models\GameTrace;
use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use Carbon\CarbonImmutable;

/**
 * Les agrégats de la mesure sur une fenêtre — spec 20 § 12.3, spec 100
 * § 10.11 (D47 du 01/10). Lus par l'écran « Performances » et par
 * `perf:report`, jamais par le jeu.
 *
 * Les centiles se calculent en PHP, au rang le plus proche, sur au plus
 * {@see self::MAX_ROWS} lignes les plus récentes par source : identiques en
 * SQLite et en MySQL, sans fonction de fenêtre. Aucune donnée personnelle :
 * des noms de route, des classes de job, du SQL à paramètres et des
 * événements du moteur.
 */
final class PerformanceReport
{
    /** Au plus autant de lignes lues par source. */
    public const int MAX_ROWS = 50_000;

    /** Au plus autant de groupes rendus par tableau. */
    public const int MAX_GROUPS = 50;

    /**
     * @return array{since: string, requests: list<array<string, int|float|string|null>>, jobs: list<array<string, int|float|string|null>>, slow_queries: list<array<string, int|string|null>>, engine: list<array<string, int|float|string|null>>, totals: array{requests: int, request_errors: int, jobs: int, job_failures: int, traced_games: int}}
     */
    public static function build(CarbonImmutable $since): array
    {
        $requests = [];
        $jobs = [];
        $totals = ['requests' => 0, 'request_errors' => 0, 'jobs' => 0, 'job_failures' => 0, 'traced_games' => 0];

        $samples = PerfSample::query()
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get(['kind', 'name', 'status', 'duration_ms', 'query_count', 'query_ms', 'memory_kb', 'wait_ms']);

        foreach ($samples as $sample) {
            $isJob = $sample->kind === PerfSample::KIND_JOB;
            $failed = $isJob
                ? $sample->status === PerfSample::STATUS_FAILED
                : (int) $sample->status >= 500;

            if ($isJob) {
                $totals['jobs']++;
                $totals['job_failures'] += $failed ? 1 : 0;
                $jobs = self::accumulate($jobs, $sample, $failed);
            } else {
                $totals['requests']++;
                $totals['request_errors'] += $failed ? 1 : 0;
                $requests = self::accumulate($requests, $sample, $failed);
            }
        }

        $engine = [];
        $games = [];

        $traces = GameTrace::query()
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get(['game_id', 'event', 'delay_ms', 'duration_ms', 'query_count']);

        foreach ($traces as $trace) {
            $games[$trace->game_id] = true;
            $engine[$trace->event] ??= ['delays' => [], 'durations' => [], 'queries' => 0, 'count' => 0];
            $engine[$trace->event]['count']++;
            $engine[$trace->event]['queries'] += $trace->query_count ?? 0;

            if ($trace->delay_ms !== null) {
                $engine[$trace->event]['delays'][] = $trace->delay_ms;
            }

            if ($trace->duration_ms !== null) {
                $engine[$trace->event]['durations'][] = $trace->duration_ms;
            }
        }

        $totals['traced_games'] = count($games);

        return [
            'since' => $since->toIso8601String(),
            'requests' => self::groups($requests),
            'jobs' => self::groups($jobs),
            'slow_queries' => self::slowQueries($since),
            'engine' => self::engine($engine),
            'totals' => $totals,
        ];
    }

    /**
     * Le centile `$p` (0 à 100) au rang le plus proche, ou `null` sans valeur.
     *
     * @param  list<int>  $values
     */
    public static function percentile(array $values, int $p): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $rank = (int) ceil($p / 100 * count($values));

        return $values[max(0, min(count($values) - 1, $rank - 1))];
    }

    /**
     * Verse un échantillon dans le groupe de son nom.
     *
     * @param  array<string, array{durations: list<int>, queries: int, query_ms: int, memory_kb: int, errors: int, wait: list<int>}>  $buckets
     * @return array<string, array{durations: list<int>, queries: int, query_ms: int, memory_kb: int, errors: int, wait: list<int>}>
     */
    private static function accumulate(array $buckets, PerfSample $sample, bool $failed): array
    {
        $bucket = $buckets[$sample->name] ?? ['durations' => [], 'queries' => 0, 'query_ms' => 0, 'memory_kb' => 0, 'errors' => 0, 'wait' => []];

        $bucket['durations'][] = $sample->duration_ms;
        $bucket['queries'] += $sample->query_count;
        $bucket['query_ms'] += $sample->query_ms;
        $bucket['memory_kb'] = max($bucket['memory_kb'], $sample->memory_kb);
        $bucket['errors'] += $failed ? 1 : 0;

        if ($sample->wait_ms !== null) {
            $bucket['wait'][] = $sample->wait_ms;
        }

        $buckets[$sample->name] = $bucket;

        return $buckets;
    }

    /**
     * @param  array<string, array{durations: list<int>, queries: int, query_ms: int, memory_kb: int, errors: int, wait: list<int>}>  $buckets
     * @return list<array<string, int|float|string|null>>
     */
    private static function groups(array $buckets): array
    {
        $rows = [];

        foreach ($buckets as $name => $bucket) {
            $count = max(1, count($bucket['durations']));

            $rows[] = [
                'name' => (string) $name,
                'count' => count($bucket['durations']),
                'p50_ms' => self::percentile($bucket['durations'], 50),
                'p95_ms' => self::percentile($bucket['durations'], 95),
                'max_ms' => self::percentile($bucket['durations'], 100) ?? 0,
                'avg_queries' => round($bucket['queries'] / $count, 1),
                'avg_query_ms' => round($bucket['query_ms'] / $count, 1),
                'max_memory_kb' => $bucket['memory_kb'],
                'errors' => $bucket['errors'],
                'p95_wait_ms' => self::percentile($bucket['wait'], 95),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['p95_ms'] ?? 0) <=> ($a['p95_ms'] ?? 0));

        return array_slice($rows, 0, self::MAX_GROUPS);
    }

    /**
     * Les requêtes lentes regroupées par empreinte : volume, maximum, moyenne,
     * dernière occurrence et contexte de la plus récente.
     *
     * @return list<array<string, int|string|null>>
     */
    private static function slowQueries(CarbonImmutable $since): array
    {
        $groups = [];

        $lines = PerfSlowQuery::query()
            ->with('sample:id,name')
            ->where('recorded_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get();

        foreach ($lines as $line) {
            $groups[$line->sql_hash] ??= [
                'sql' => $line->sql_text,
                'count' => 0,
                'total_ms' => 0,
                'max_ms' => 0,
                'last_at' => $line->recorded_at->toIso8601String(),
                'context' => $line->sample->name ?? null,
            ];
            $groups[$line->sql_hash]['count']++;
            $groups[$line->sql_hash]['total_ms'] += $line->duration_ms;
            $groups[$line->sql_hash]['max_ms'] = max($groups[$line->sql_hash]['max_ms'], $line->duration_ms);
        }

        $rows = [];

        foreach ($groups as $group) {
            $rows[] = [
                'sql' => $group['sql'],
                'count' => $group['count'],
                'max_ms' => $group['max_ms'],
                'avg_ms' => intdiv($group['total_ms'], $group['count']),
                'last_at' => $group['last_at'],
                'context' => $group['context'],
            ];
        }

        usort($rows, static fn (array $a, array $b): int => ($b['max_ms'] <=> $a['max_ms']));

        return array_slice($rows, 0, self::MAX_GROUPS);
    }

    /**
     * Les événements du moteur : retard (réel − théorique) et durée.
     *
     * @param  array<string, array{delays: list<int>, durations: list<int>, queries: int, count: int}>  $events
     * @return list<array<string, int|float|string|null>>
     */
    private static function engine(array $events): array
    {
        $rows = [];

        foreach ($events as $event => $bucket) {
            $rows[] = [
                'event' => (string) $event,
                'count' => $bucket['count'],
                'p50_delay_ms' => self::percentile($bucket['delays'], 50),
                'p95_delay_ms' => self::percentile($bucket['delays'], 95),
                'max_delay_ms' => $bucket['delays'] === [] ? null : max($bucket['delays']),
                'p50_duration_ms' => self::percentile($bucket['durations'], 50),
                'p95_duration_ms' => self::percentile($bucket['durations'], 95),
                'avg_queries' => round($bucket['queries'] / $bucket['count'], 1),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['event'], (string) $b['event']));

        return $rows;
    }
}
