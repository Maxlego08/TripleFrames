<?php

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Écran « Statistiques de jeu » — spec 20 § 12.6, ligne 51 (08/10)
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();
    Date::setTestNow(CarbonImmutable::parse('2026-10-01 12:00:00'));
});

test('seul un administrateur lit les statistiques de jeu', function (): void {
    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.game-stats.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.game-stats.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/game-stats/index')
            ->where('window', '30d')
            ->has('report.daily', 30)
            ->has('report.hours', 24));
});

test('l\'écran compte les parties de la fenêtre, leur issue, leurs joueurs et leurs réponses', function (): void {
    $finished = Game::factory()->completed()->create([
        'started_at' => '2026-09-30 10:00:00',
        'ended_at' => '2026-09-30 10:20:00',
        'total_paused_ms' => 0,
    ]);
    Game::factory()->solo()->create(['started_at' => '2026-10-01 09:00:00']);
    Game::factory()->completed()->create(['started_at' => '2026-08-01 10:00:00', 'ended_at' => '2026-08-01 10:20:00']);

    $alice = Player::factory()->create();
    $bob = Player::factory()->create();
    GamePlayer::factory()->for($finished)->frozenFrom($alice)->create();
    GamePlayer::factory()->for($finished)->frozenFrom($bob)->create();

    $found = Round::factory()->forGame($finished)->forMovie(Movie::factory()->create())->completed()->create();
    $missed = Round::factory()->forGame($finished)->forMovie(Movie::factory()->create())->atSequence(2)->completed(0)->create();

    foreach ([$found, $missed] as $round) {
        RoundPlayer::factory()->forRound($round, $alice)->create(['wrong_attempts' => 1]);
        RoundPlayer::factory()->forRound($round, $bob)->create();
    }

    Guess::factory()->forRound($found, $alice)->atTier(2)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.game-stats.index', ['window' => '7d']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.games', 2)
            ->where('report.totals.multiplayer', 1)
            ->where('report.totals.solo', 1)
            ->where('report.totals.completed', 1)
            ->where('report.totals.running', 1)
            ->where('report.totals.players_per_game', 2)
            ->where('report.totals.average_duration_ms', 20 * 60 * 1000)
            ->where('report.totals.rounds', 2)
            ->where('report.totals.participations', 4)
            ->where('report.totals.finds', 1)
            ->where('report.totals.find_rate', 0.25)
            ->where('report.totals.wrong_per_participation', 0.5)
            ->where('report.daily.5.multiplayer', 1)
            ->where('report.daily.5.completed', 1)
            ->where('report.daily.6.solo', 1)
            ->where('report.hours.10.games', 1)
            ->where('report.hours.9.games', 1)
            ->where('report.tiers', [['tier' => 2, 'finds' => 1]])
            ->has('report.movies.played', 2)
            ->has('report.movies.hardest', 0));
});

test('une manche en cours ne livre jamais son film, même au back-office', function (): void {
    $game = Game::factory()->create(['started_at' => '2026-10-01 11:00:00']);
    $secret = Movie::factory()->create(['title_original' => 'Titre encore secret']);
    $round = Round::factory()->forGame($game)->forMovie($secret)->running()->create();
    $player = Player::factory()->create();
    RoundPlayer::factory()->forRound($round, $player)->create();
    Guess::factory()->forRound($round, $player)->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.game-stats.index'))
        ->assertOk()
        ->assertDontSee('Titre encore secret')
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.totals.rounds', 0)
            ->where('report.totals.finds', 0)
            ->has('report.movies.played', 0));
});
