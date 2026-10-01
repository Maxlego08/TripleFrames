<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\PerfSample;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `perf` — 10 § 11.1 (D47 du 01/10) : les échantillons de mesure, leurs requêtes lentes partant en cascade, 14 jours, sur
 * `recorded_at` (`perf_sample_recorded_idx`).
 */
final class PerfSampleHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::Perf;
    }

    protected function connectionName(): ?string
    {
        return (new PerfSample)->getConnectionName();
    }

    protected function table(): string
    {
        return (new PerfSample)->getTable();
    }

    protected function pilotColumn(): string
    {
        return 'recorded_at';
    }

    protected function keyColumn(): string
    {
        return 'id';
    }

    protected function cutoff(CarbonImmutable $asOf): CarbonImmutable
    {
        return $asOf->subDays(RetentionWindows::PERF_DAYS);
    }
}
