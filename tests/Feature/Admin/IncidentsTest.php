<?php

use App\Enums\RoundIncidentReason;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\Round;
use App\Models\RoundTier;
use App\Models\User;
use App\Support\Curation\IncidentReport;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Films jamais trouvés et incidents — spec 20 § 12.1, ligne 29, L20-29
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();

    Config::set('catalog.curation.incidents_window_days', 30);
});

test('l\'agrégat par film ne joint jamais round_player, guess ni player', function (): void {
    $movie = Movie::factory()->create();
    Round::factory()->forMovie($movie)->completed(foundCount: 0)->create();
    Round::factory()->forMovie($movie)->cancelled(RoundIncidentReason::FrameUnavailable)->create();
    RoundTier::factory()
        ->for(Round::factory()->forMovie($movie)->completed(foundCount: 2))
        ->substituted(Frame::factory()->create())
        ->create();

    $queries = [];

    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $rows = app(IncidentReport::class)->rows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['movie']->id)->toBe($movie->id)
        ->and($rows[0]['completed'])->toBe(2)
        ->and($rows[0]['never_found'])->toBe(1)
        ->and($rows[0]['cancelled'])->toBe(['frame_unavailable' => 1])
        ->and($rows[0]['substituted'])->toBe(['frame_unavailable' => 1]);

    foreach ($queries as $sql) {
        expect($sql)->not->toMatch('/"(round_player|guess|player)"/');
    }

    // L'écran ne porte aucune identité de joueur.
    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.incidents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/incidents/index')
            ->where('window_days', 30)
            ->has('incidents.data', 1)
            ->where('incidents.data.0.movie.id', $movie->id)
            ->where('incidents.data.0.never_found', 1)
            ->where('incidents.data.0.completed', 2)
            ->missing('incidents.data.0.players'));
});

test('seules les manches terminées comptent comme jamais trouvées', function (): void {
    $movie = Movie::factory()->create();
    // En cours, en révélation, annulée : jamais « jamais trouvée ».
    Round::factory()->forMovie($movie)->running()->create(['found_count' => 0]);
    Round::factory()->forMovie($movie)->revealing()->create(['found_count' => 0]);
    Round::factory()->forMovie($movie)->completed(foundCount: 1)->create();

    expect(app(IncidentReport::class)->rows())->toBe([]);

    Round::factory()->forMovie($movie)->completed(foundCount: 0)->create();

    $rows = app(IncidentReport::class)->rows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['never_found'])->toBe(1)
        ->and($rows[0]['completed'])->toBe(2)
        ->and($rows[0]['cancelled'])->toBe([]);
});

test('la fenêtre glissante suit la configuration', function (): void {
    $movie = Movie::factory()->create();

    $this->travelTo(now()->subDays(20));
    Round::factory()->forMovie($movie)->completed(foundCount: 0)->create();
    Round::factory()->forMovie($movie)->cancelled(RoundIncidentReason::NoVariantAvailable)->create();
    $this->travelBack();

    expect(app(IncidentReport::class)->rows())->toHaveCount(1);

    Config::set('catalog.curation.incidents_window_days', 10);

    expect(app(IncidentReport::class)->rows())->toBe([]);

    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.incidents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('window_days', 10)
            ->has('incidents.data', 0));
});

test('les films sont rangés du plus touché au moins touché', function (): void {
    $once = Movie::factory()->create();
    $twice = Movie::factory()->create();
    $cancelledOnly = Movie::factory()->create();

    Round::factory()->forMovie($once)->completed(foundCount: 0)->create();
    Round::factory()->forMovie($twice)->completed(foundCount: 0)->count(2)->create();
    Round::factory()->forMovie($cancelledOnly)->cancelled(RoundIncidentReason::MovieWithdrawn)->create();

    $ids = array_map(static fn (array $row): int => $row['movie']->id, app(IncidentReport::class)->rows());

    expect($ids)->toBe([$twice->id, $once->id, $cancelledOnly->id]);
});

test('une fenêtre hors bornes est refusée', function (mixed $days): void {
    Config::set('catalog.curation.incidents_window_days', $days);

    expect(static fn (): int => IncidentReport::windowDays())->toThrow(InvalidArgumentException::class);
})->with([0, -1, '30']);

test('un joueur ne voit pas l\'écran des incidents', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.incidents.index'))
        ->assertForbidden();
});
