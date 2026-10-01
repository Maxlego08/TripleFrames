<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Support\Perf\PerformanceReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * `perf:report` — les agrégats de la mesure en console, pour une session SSH
 * (spec 100 § 10.11, D47 du 01/10). Les mêmes que l'écran « Performances » ;
 * lecture seule, aucune donnée personnelle. Textes en français, par clés
 * `admin.console.perf.*`, comme toute commande d'exploitation.
 */
class PerfReportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'perf:report
        {--hours=24 : Fenêtre, en heures}
        {--limit=15 : Lignes par tableau}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Affiche les performances mesurées : routes, jobs, moteur de partie, requêtes SQL lentes';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, (int) $this->option('limit'));

        $report = PerformanceReport::build(Date::now()->toImmutable()->subHours($hours));
        $totals = $report['totals'];

        $this->line(self::text('admin.console.perf.summary', [
            'hours' => $hours,
            'requests' => $totals['requests'],
            'request_errors' => $totals['request_errors'],
            'jobs' => $totals['jobs'],
            'job_failures' => $totals['job_failures'],
            'games' => $totals['traced_games'],
        ]));

        $this->newLine();
        $this->info(self::text('admin.console.perf.requests'));
        $this->table(
            self::headers(['route', 'count', 'p50', 'p95', 'max', 'queries', 'errors']),
            array_map(static fn (array $row): array => [$row['name'], $row['count'], $row['p50_ms'], $row['p95_ms'], $row['max_ms'], $row['avg_queries'], $row['errors']], array_slice($report['requests'], 0, $limit)),
        );

        $this->info(self::text('admin.console.perf.jobs'));
        $this->table(
            self::headers(['job', 'count', 'p50', 'p95', 'max', 'queries', 'errors']),
            array_map(static fn (array $row): array => [$row['name'], $row['count'], $row['p50_ms'], $row['p95_ms'], $row['max_ms'], $row['avg_queries'], $row['errors']], array_slice($report['jobs'], 0, $limit)),
        );

        $this->info(self::text('admin.console.perf.engine'));
        $this->table(
            self::headers(['event', 'count', 'delay_p50', 'delay_p95', 'delay_max', 'duration_p95', 'queries']),
            array_map(static fn (array $row): array => [$row['event'], $row['count'], $row['p50_delay_ms'], $row['p95_delay_ms'], $row['max_delay_ms'], $row['p95_duration_ms'], $row['avg_queries']], $report['engine']),
        );

        $this->info(self::text('admin.console.perf.slow_queries'));
        $this->table(
            self::headers(['count', 'max', 'avg', 'context', 'sql']),
            array_map(static fn (array $row): array => [$row['count'], $row['max_ms'], $row['avg_ms'], $row['context'], mb_strimwidth((string) $row['sql'], 0, 120, '…')], array_slice($report['slow_queries'], 0, $limit)),
        );

        return self::SUCCESS;
    }

    /**
     * Les en-têtes d'un tableau, par clés `admin.console.perf.column.*`.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function headers(array $keys): array
    {
        return array_map(static fn (string $key): string => self::text('admin.console.perf.column.'.$key), $keys);
    }

    /**
     * @param  array<string, int|string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $translated = trans($key, $replace, Locale::French->value);

        return is_string($translated) ? $translated : '';
    }
}
