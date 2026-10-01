<?php

use App\Models\GameTrace;
use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use App\Support\Retention\RetentionWindows;

/*
|--------------------------------------------------------------------------
| Purge de la mesure — spec 10 § 11.1, périmètres `perf` et `game_trace`,
| D47 du 01/10
|--------------------------------------------------------------------------
*/

it('purge la mesure et la chronologie au-delà de 14 jours, requêtes lentes comprises', function (): void {
    $old = now()->subDays(RetentionWindows::PERF_DAYS)->subMinute();
    $recent = now()->subDays(RetentionWindows::PERF_DAYS)->addHour();

    $stale = PerfSample::factory()->create(['recorded_at' => $old]);
    PerfSlowQuery::factory()->create(['perf_sample_id' => $stale->id, 'recorded_at' => $old]);
    $kept = PerfSample::factory()->create(['recorded_at' => $recent]);
    $staleTrace = GameTrace::factory()->create(['recorded_at' => $old]);
    $keptTrace = GameTrace::factory()->create(['recorded_at' => $recent]);

    $this->artisan('purge:run', ['--sync' => true])->assertSuccessful();

    expect(PerfSample::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(PerfSlowQuery::query()->count())->toBe(0)
        ->and(GameTrace::query()->pluck('id')->all())->toBe([$keptTrace->id])
        ->and(GameTrace::query()->whereKey($staleTrace->id)->exists())->toBeFalse();
});
