<?php

use App\Enums\GameStatus;
use App\Events\Game\GameFinalized;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Account\PlayHistory;
use App\Support\Account\PlayHistoryCache;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| « Mes parties » et ses quatre compteurs — spec 40 § 13.4 (L40-13)
|--------------------------------------------------------------------------
|
| Requêtes sur les agrégats figés au podium (80 § 13), fenêtre glissante de
| `PlatformLimits::historyWindowMonths()`, aucun agrégat stocké. Les
| agrégats sont posés par la fabrique (`finished()`), comme `FinalizeGame`
| les écrit : la sémantique du gel est prouvée par les tests de 80.
|
*/

/**
 * Une partie gelée du compte, avec ses agrégats figés.
 *
 * @param  array{rounds?: int, correct?: int, score?: int, rank?: int|null}  $aggregates
 */
function historyGame(User $user, ?Game $game = null, array $aggregates = [], ?Player $seat = null): GamePlayer
{
    $game ??= Game::factory()->completed()->create();
    $seat ??= Player::factory()->forUser($user)->create(['room_id' => $game->room_id]);

    return GamePlayer::factory()
        ->for($game)
        ->frozenFrom($seat)
        ->finished(
            roundsPlayed: $aggregates['rounds'] ?? 10,
            correctAnswers: $aggregates['correct'] ?? 6,
            finalScore: $aggregates['score'] ?? 1_400,
            finalRank: array_key_exists('rank', $aggregates) ? $aggregates['rank'] : 1,
        )
        ->create();
}

function historySummary(User $user): array
{
    PlayHistoryCache::forget($user->id);

    return app(PlayHistory::class)->summary($user);
}

it('exclut une partie terminée il y a douze mois et un jour, et le meilleur score peut baisser', function (): void {
    $now = CarbonImmutable::parse('2026-10-07 12:00:00');
    $this->travelTo($now);
    $months = PlatformLimits::historyWindowMonths();
    $user = User::factory()->create();

    historyGame($user, Game::factory()->completed()->create(['ended_at' => $now->subMonths($months)->subDay()]), ['score' => 5_000]);
    historyGame($user, Game::factory()->completed()->create(['ended_at' => $now->subMonths($months)->addDay()]), ['score' => 3_000]);
    historyGame($user, Game::factory()->completed()->create(['ended_at' => $now->subMonth()]), ['score' => 900]);

    $summary = historySummary($user);

    expect($summary['counters']['gamesPlayed'])->toBe(2)
        ->and($summary['counters']['bestScore']['score'] ?? null)->toBe(3_000)
        ->and(app(PlayHistory::class)->page($user, 1)->total())->toBe(2);

    // Deux jours plus tard, la partie à 3 000 sort de la fenêtre : le
    // meilleur score baisse, sans qu'aucun agrégat stocké ne soit décrémenté.
    $this->travelTo($now->addDays(2));

    $later = historySummary($user);

    expect($later['counters']['gamesPlayed'])->toBe(1)
        ->and($later['counters']['bestScore']['score'] ?? null)->toBe(900);
});

it('liste une partie solo sans la compter', function (): void {
    $user = User::factory()->create();
    $solo = Game::factory()->solo()->completed()->create();
    historyGame($user, $solo, ['score' => 2_000, 'rank' => null], Player::factory()->solo()->forUser($user)->create());

    $summary = historySummary($user);
    $rows = app(PlayHistory::class)->page($user, 1)->items();

    expect($summary['counters']['gamesPlayed'])->toBe(0)
        ->and($summary['counters']['bestScore'])->toBeNull()
        ->and($summary['oldestKeptAt'])->not->toBeNull()
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['mode'])->toBe('solo')
        ->and($rows[0]['roomCode'])->toBeNull()
        ->and($rows[0]['rank'])->toBeNull();
});

it('compte une partie interrompue dès une manche close, jamais une partie à zéro manche', function (): void {
    $user = User::factory()->create();

    historyGame($user, Game::factory()->interrupted(1)->create(), ['rounds' => 1, 'correct' => 1, 'score' => 300]);
    historyGame($user, Game::factory()->interrupted(0)->create(), ['rounds' => 0, 'correct' => 0, 'score' => 0, 'rank' => null]);

    $summary = historySummary($user);
    $rows = app(PlayHistory::class)->page($user, 1)->items();

    expect($summary['counters']['gamesPlayed'])->toBe(1)
        ->and($summary['counters']['roundsPlayed'])->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe('interrupted')
        ->and($rows[0]['roundsCompleted'])->toBe(1);
});

it("n'inclut jamais une manche annulée", function (): void {
    $user = User::factory()->create();
    $game = Game::factory()->completed()->create();
    $gamePlayer = historyGame($user, $game);
    $seat = $gamePlayer->player;

    $kept = Round::factory()->forGame($game)->atSequence(1, 1)->completed()->create();
    $cancelled = Round::factory()->forGame($game)->atSequence(2, 2)->cancelled()->create();

    foreach ([$kept, $cancelled] as $round) {
        RoundPlayer::factory()->forRound($round, $seat)->create();
        Guess::factory()->forRound($round, $seat)->create();
    }

    $detail = app(PlayHistory::class)->detail($game, $gamePlayer);

    expect($detail['rounds'])->toHaveCount(1)
        ->and($detail['rounds'][0]['number'])->toBe(1)
        ->and($detail['rounds'][0]['outcome'])->toBe('found');
});

it('exclut une partie sans score du seul meilleur score', function (): void {
    $user = User::factory()->create();

    historyGame($user, null, ['rounds' => 10, 'correct' => 4, 'score' => 0]);

    $scoreless = historySummary($user);

    expect($scoreless['counters']['gamesPlayed'])->toBe(1)
        ->and($scoreless['counters']['correctAnswers'])->toBe(4)
        ->and($scoreless['counters']['bestScore'])->toBeNull();

    historyGame($user, null, ['rounds' => 10, 'correct' => 2, 'score' => 200]);

    $both = historySummary($user);

    expect($both['counters']['gamesPlayed'])->toBe(2)
        ->and($both['counters']['bestScore']['score'] ?? null)->toBe(200);
});

it('affiche un tiret sous le seuil du taux, jamais plus de cent pour cent', function (): void {
    $user = User::factory()->create();
    $min = PlatformLimits::successRateMinRounds();

    historyGame($user, null, ['rounds' => $min - 1, 'correct' => $min - 1]);

    expect(historySummary($user)['counters']['successRate'])->toBeNull();

    historyGame($user, null, ['rounds' => 1, 'correct' => 1]);

    $rate = historySummary($user)['counters']['successRate'];

    expect($rate)->toBe(1.0);

    $this->actingAs($user)
        ->get(route('history.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/history')
            ->where('summary.counters.successRate', fn (mixed $value): bool => (float) $value === 1.0)
            ->where('summary.successRateMinRounds', $min)
            ->has('games', 2)
            ->where('pagination.page', 1));
});

it("répond 404 à la partie d'un autre compte", function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $game = historyGame($owner)->game;

    $this->actingAs($intruder)
        ->get(route('history.show', $game->public_id))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->get('/settings/history/0000000000AA')
        ->assertNotFound();

    $this->actingAs($owner)
        ->get(route('history.show', $game->public_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/history-show')
            ->where('game.publicId', $game->public_id));
});

it('oublie le cache à la fin d\'une partie', function (): void {
    $user = User::factory()->create();
    $game = historyGame($user)->game;

    app(PlayHistory::class)->summary($user);

    expect(Cache::has(PlayHistoryCache::key($user->id)))->toBeTrue();

    GameFinalized::dispatch($game->id, GameStatus::Completed);

    expect(Cache::has(PlayHistoryCache::key($user->id)))->toBeFalse();

    // Une partie déjà purgée n'a plus de siège : l'écouteur ne lève pas.
    GameFinalized::dispatch(999_999, GameStatus::Interrupted);
});

it('le détail ne montre aucun pseudo d\'un tiers et porte le lien Letterboxd', function (): void {
    $user = User::factory()->create();
    $game = Game::factory()->completed()->create();
    $mine = historyGame($user, $game);
    $rival = Player::factory()->withNickname('Rivalissime')->create(['room_id' => $game->room_id]);
    GamePlayer::factory()->for($game)->frozenFrom($rival)->finished(finalRank: 2)->create();

    $known = Movie::factory()->create(['tmdb_id' => 27205]);
    $demo = Movie::factory()->demo()->create();

    $first = Round::factory()->forGame($game)->forMovie($known)->atSequence(1, 1)->completed()->create();
    $second = Round::factory()->forGame($game)->forMovie($demo)->atSequence(2, 2)->completed()->create();

    RoundPlayer::factory()->forRound($first, $mine->player)->create();
    Guess::factory()->forRound($first, $mine->player)->create();
    RoundPlayer::factory()->forRound($second, $rival)->create();
    Guess::factory()->forRound($second, $rival)->create();

    $response = $this->actingAs($user)->get(route('history.show', $game->public_id))->assertOk();

    expect($response->getContent())->not->toContain('Rivalissime');

    $response->assertInertia(fn (Assert $page) => $page
        ->component('settings/history-show')
        ->has('rounds', 2)
        ->where('rounds.0.movie.letterboxdUrl', 'https://letterboxd.com/tmdb/27205/')
        ->where('rounds.0.outcome', 'found')
        ->where('rounds.1.movie.letterboxdUrl', null)
        ->where('rounds.1.outcome', 'absent')
        ->where('rounds.1.points', null));
});

it('les compteurs ne lisent jamais guess', function (): void {
    $user = User::factory()->create();
    historyGame($user);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    historySummary($user);
    app(PlayHistory::class)->page($user, 1);

    expect($queries)->not->toBeEmpty();

    foreach ($queries as $sql) {
        expect($sql)->not->toMatch('/\bguess\b/i');
    }
});
