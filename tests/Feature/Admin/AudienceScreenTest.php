<?php

use App\Models\AudienceDaily;
use App\Models\AudiencePresence;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Audience\AudienceRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Écran « Audience » — spec 20 § 12.4, ligne 44, D48 du 01/10
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    Date::setTestNow(CarbonImmutable::parse('2026-10-01 12:00:00'));
});

test('seul un administrateur lit l\'audience', function (): void {
    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.audience.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.audience.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/audience/index')
            ->where('window', '30d'));
});

test('l\'écran additionne les compteurs de la fenêtre et classe les pages', function (): void {
    foreach (['2026-09-30' => 3, '2026-10-01' => 5] as $day => $visitors) {
        AudienceDaily::factory()->counter($day, AudienceRecorder::METRIC_VISITORS, '', $visitors)->create();
        AudienceDaily::factory()->counter($day, AudienceRecorder::METRIC_VISITS, '', $visitors + 1)->create();
        AudienceDaily::factory()->counter($day, AudienceRecorder::METRIC_PAGEVIEWS, 'home', $visitors * 2)->create();
    }

    AudienceDaily::factory()->counter('2026-10-01', AudienceRecorder::METRIC_PAGEVIEWS, 'legal.notice', 30)->create();
    AudienceDaily::factory()->counter('2026-08-01', AudienceRecorder::METRIC_VISITORS, '', 99)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.audience.index', ['window' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.visitors', 8)
            ->where('report.totals.visits', 10)
            ->where('report.totals.pageviews', 46)
            ->has('report.daily', 2)
            ->where('report.daily.1.day', '2026-10-01')
            ->where('report.pages.0.name', 'legal.notice')
            ->where('report.pages.0.total', 30)
            ->where('report.pages.1.name', 'home')
            ->where('report.pages.1.total', 16));
});

test('le temps réel lit la présence des cinq dernières minutes et les parties en cours', function (): void {
    AudiencePresence::factory()->create(['route' => 'home', 'last_seen_at' => now()->subMinute()]);
    AudiencePresence::factory()->create(['route' => 'home', 'last_seen_at' => now()->subMinutes(2)]);
    AudiencePresence::factory()->create(['route' => 'room.show', 'last_seen_at' => now()->subMinutes(8)]);
    Game::factory()->create();
    Game::factory()->solo()->create();
    Game::factory()->completed()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.audience.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.live.visitors', 2)
            ->where('report.live.pages.0.name', 'home')
            ->where('report.live.running_games', 1)
            ->where('report.live.running_solo', 1));
});

test('l\'entonnoir de jeu compte salons, parties et joueurs par partie', function (): void {
    $game = Game::factory()->completed()->create();
    GamePlayer::factory()->for($game)->frozenFrom(Player::factory()->create())->create();
    GamePlayer::factory()->for($game)->frozenFrom(Player::factory()->create())->create();
    Game::factory()->solo()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.audience.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.funnel.games_multiplayer', 1)
            ->where('report.funnel.games_solo', 1)
            ->where('report.funnel.games_completed', 1)
            ->where('report.funnel.players_per_game', 1)
            ->where('report.funnel.rooms_created', Room::query()->count()));
});
