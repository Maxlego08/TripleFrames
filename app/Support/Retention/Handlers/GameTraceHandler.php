<?php

namespace App\Support\Retention\Handlers;

use App\Enums\PurgeScope;
use App\Models\GameTrace;
use App\Support\Retention\RetentionWindows;
use App\Support\Retention\TablePurgeHandler;
use Carbon\CarbonImmutable;

/**
 * Périmètre `game_trace` — 10 § 11.1 (D47 du 01/10) : la chronologie technique des parties, 14 jours, sur
 * `recorded_at` (`game_trace_recorded_idx`).
 */
final class GameTraceHandler extends TablePurgeHandler
{
    public function scope(): PurgeScope
    {
        return PurgeScope::GameTrace;
    }

    protected function connectionName(): ?string
    {
        return (new GameTrace)->getConnectionName();
    }

    protected function table(): string
    {
        return (new GameTrace)->getTable();
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
