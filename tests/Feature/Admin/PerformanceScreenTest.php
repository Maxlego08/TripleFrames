<?php

use App\Models\Game;
use App\Models\GameTrace;
use App\Models\PerfSample;
use App\Models\PerfSlowQuery;
use App\Models\User;
use App\Support\Perf\GameTraceWriter;
use App\Support\Perf\PerformanceReport;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Écran « Performances » et `perf:report` — spec 20 § 12.3, ligne 43,
| D47 du 01/10
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
});

test('seul un administrateur lit les performances', function (): void {
    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.performance.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.performance.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/performance/index')
            ->where('window', '24h'));
});

test('les agrégats par route donnent volume, centiles, maximum et erreurs', function (): void {
    foreach ([10, 20, 30, 40] as $duration) {
        PerfSample::factory()->forRoute('round.answer.store', $duration)->create();
    }

    PerfSample::factory()->forRoute('round.answer.store', 500, '500')->create();
    PerfSample::factory()->forJob('App\\Jobs\\Game\\AdvanceRound', 15, 'game')->create();
    PerfSample::factory()->forJob('App\\Jobs\\Game\\AdvanceRound', 25, 'game', failed: true)->create();
    PerfSample::factory()->forRoute('home', 999)->create(['recorded_at' => now()->subDays(8)]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.performance.index', ['window' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.requests', 5)
            ->where('report.totals.request_errors', 1)
            ->where('report.totals.jobs', 2)
            ->where('report.totals.job_failures', 1)
            ->has('report.requests', 1)
            ->where('report.requests.0.name', 'round.answer.store')
            ->where('report.requests.0.count', 5)
            ->where('report.requests.0.p50_ms', 30)
            ->where('report.requests.0.p95_ms', 500)
            ->where('report.requests.0.max_ms', 500)
            ->where('report.requests.0.errors', 1)
            ->where('report.jobs.0.errors', 1));
});

test('les requêtes lentes se regroupent par empreinte et le moteur rend ses retards', function (): void {
    $sample = PerfSample::factory()->create();
    PerfSlowQuery::factory()->count(2)->create(['perf_sample_id' => $sample->id]);
    $game = Game::factory()->create();

    foreach ([10, 30, 50] as $delay) {
        GameTrace::factory()->broadcast($game, 'tier.opened', $delay)->create();
    }

    $report = PerformanceReport::build(now()->subHour()->toImmutable());

    expect($report['slow_queries'])->toHaveCount(1)
        ->and($report['slow_queries'][0]['count'])->toBe(2)
        ->and($report['slow_queries'][0]['context'])->toBe('home')
        ->and($report['engine'][0]['event'])->toBe(GameTraceWriter::BROADCAST_PREFIX.'tier.opened')
        ->and($report['engine'][0]['p50_delay_ms'])->toBe(30)
        ->and($report['engine'][0]['max_delay_ms'])->toBe(50)
        ->and($report['totals']['traced_games'])->toBe(1);
});

test('perf:report affiche les agrégats en console', function (): void {
    PerfSample::factory()->forRoute('round.answer.store', 42)->create();

    $this->artisan('perf:report', ['--hours' => 1])
        ->expectsOutputToContain('round.answer.store')
        ->assertSuccessful();
});

test('la fiche d\'une partie porte sa chronologie technique', function (): void {
    $game = Game::factory()->create();
    GameTrace::factory()->broadcast($game, 'round.closed', 12)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.games.show', ['game' => $game->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('trace', 1)
            ->where('trace.0.event', GameTraceWriter::BROADCAST_PREFIX.'round.closed')
            ->where('trace.0.delay_ms', 12));
});
