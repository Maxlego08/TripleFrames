<?php

use App\Jobs\Catalog\SyncThemeMembership;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Support\Catalog\ThemeEvaluator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Le job de réévaluation d'un thème — spec 30 § 13.1-13.2 (L30-8)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('le job est unique par thème et tourne sur la file par défaut', function (): void {
    $job = new SyncThemeMembership(7);

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->toBeInstanceOf(ShouldQueueAfterCommit::class)
        ->and($job->uniqueId())->toBe('theme-sync-7')
        ->and($job->queue)->toBeNull()
        ->and($job->connection)->toBeNull()
        ->and($job->tries)->toBe(1)
        // Un verrou d'unicité sans expiration survivrait à un job jamais
        // traité et jetterait toute correction ultérieure de la règle.
        ->and($job->uniqueFor)->toBeGreaterThan(0);

    Queue::fake();

    SyncThemeMembership::dispatch(7);
    SyncThemeMembership::dispatch(7);
    SyncThemeMembership::dispatch(8);

    // Deux corrections rapprochées n'empilent qu'un job en attente par thème.
    Queue::assertPushed(SyncThemeMembership::class, 2);
    Queue::assertPushed(SyncThemeMembership::class, static fn (SyncThemeMembership $pushed): bool => $pushed->themeId === 8);
});

it('le job réévalue le thème sur tout le catalogue', function (): void {
    $theme = Theme::factory()->genre(16)->unpublished()->create();
    $movie = Movie::factory()->withGenre(16)->create();

    (new SyncThemeMembership($theme->id))->handle(app(ThemeEvaluator::class));

    expect(MovieTheme::query()->where('theme_id', $theme->id)->pluck('movie_id')->all())->toBe([$movie->id]);
});

it('un thème disparu termine le job sans erreur', function (): void {
    Movie::factory()->withGenre(16)->create();

    (new SyncThemeMembership(1_000_000))->handle(app(ThemeEvaluator::class));

    expect(MovieTheme::query()->count())->toBe(0);
});

it('une correction de règle pendant une synchronisation n\'est jamais perdue', function (): void {
    Config::set('queue.default', 'database');

    $theme = Theme::factory()->genre(16)->create();
    $animated = Movie::factory()->withGenre(16)->create();
    $drama = Movie::factory()->withGenre(18)->create();

    SyncThemeMembership::dispatch($theme->id);

    expect(DB::table('jobs')->count())->toBe(1);

    // Pendant que le premier passage tourne, le curateur corrige la règle,
    // et son geste dispatche une nouvelle synchronisation.
    $corrected = false;

    DB::listen(static function (QueryExecuted $query) use (&$corrected, $theme): void {
        if ($corrected || ! str_contains($query->sql, 'from "movie_tmdb_tag"')) {
            return;
        }

        $corrected = true;

        DB::table('theme')->where('id', $theme->id)->update(['rule_value' => '18']);
        SyncThemeMembership::dispatch($theme->id);
    });

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

    // Le verrou d'unicité est tombé au début du traitement : la seconde
    // synchronisation attend, au lieu d'avoir été jetée en silence.
    expect($corrected)->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--queue' => 'default'])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->pluck('movie_id')->all())->toBe([$drama->id])
        ->and(MovieTheme::query()->where('movie_id', $animated->id)->exists())->toBeFalse();
});

it('le job ne part qu\'après le commit de la transaction qui le dispatche', function (): void {
    // La file réelle et non `Queue::fake()`, qui pousse sans attendre le commit.
    Config::set('queue.default', 'database');

    $inside = null;

    DB::transaction(static function () use (&$inside): void {
        SyncThemeMembership::dispatch(9);

        $inside = DB::table('jobs')->count();
    });

    expect($inside)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});
