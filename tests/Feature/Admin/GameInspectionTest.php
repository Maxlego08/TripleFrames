<?php

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\GuessSource;
use App\Models\AdminAction;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Models\User;
use App\Models\WrongAnswer;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Inspection des parties et des sièges — spec 20 § 12.2, lignes 36 et 42,
| D46 du 01/10, lot L20-36
|--------------------------------------------------------------------------
|
| Administrateur seul, en lecture seule. Chaque réponse, juste ou fausse,
| se lit sur la fiche d'une partie et sur celle d'un siège ; une manche non
| révélée d'une partie en cours n'expose que son numéro et son statut
| (règle 3). Chaque consultation écrit sa lecture sensible.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * Une partie de salon à deux sièges : une manche terminée (bonne réponse de
 * Alice, deux réponses fausses de Bruno), puis une manche en cours dont le
 * film ne doit jamais sortir tant que la partie n'est pas close.
 *
 * @return array{game: Game, alice: Player, bruno: Player, round: Round, live: Round}
 */
function inspectedGame(bool $ended = false): array
{
    $room = Room::factory()->playing()->create();
    $alice = Player::factory()->for($room)->withNickname('Alice')->create();
    $bruno = Player::factory()->for($room)->withNickname('Bruno')->create();

    $game = $ended
        ? Game::factory()->forRoom($room)->completed()->create()
        : Game::factory()->forRoom($room)->create();

    foreach ([$alice, $bruno] as $seat) {
        GamePlayer::factory()->for($game)->frozenFrom($seat)->create();
    }

    $round = Round::factory()->forGame($game)->atSequence(1)
        ->forMovie(Movie::factory()->create(['title_original' => 'Harbour Lights']))
        ->completed()
        ->create();

    $live = Round::factory()->forGame($game)->atSequence(2)
        ->forMovie(Movie::factory()->create(['title_original' => 'Secret Orchard']))
        ->running()
        ->create();

    foreach ([$round, $live] as $played) {
        foreach ([1, 2, 3] as $tierIndex) {
            RoundTier::factory()->atTier($tierIndex)->create(['round_id' => $played->id]);
        }
    }

    RoundPlayer::factory()->forRound($round, $alice)->locked()->create();
    RoundPlayer::factory()->forRound($round, $bruno)->withWrongAttempts(2)->create();
    Guess::factory()->forRound($round, $alice)->create();
    WrongAnswer::factory()->forRound($round, $bruno)->create(['submitted_text' => 'Harbor Light', 'attempt_number' => 1]);
    WrongAnswer::factory()->forRound($round, $bruno)->viaChoice('Le Port des brumes')->create();

    RoundPlayer::factory()->forRound($live, $alice)->withWrongAttempts(1)->create();
    WrongAnswer::factory()->forRound($live, $alice)->create(['submitted_text' => 'Secret Orchar']);

    return ['game' => $game, 'alice' => $alice, 'bruno' => $bruno, 'round' => $round, 'live' => $live];
}

test('seul un administrateur inspecte les parties et les sièges', function (): void {
    ['game' => $game, 'alice' => $alice] = inspectedGame();
    $curator = User::factory()->curator()->create();

    foreach ([
        route('admin.games.index'),
        route('admin.games.show', ['game' => $game->id]),
        route('admin.players.index'),
        route('admin.players.show', ['player' => $alice->public_id]),
    ] as $url) {
        $this->actingAs($curator)->get($url)->assertForbidden();
    }

    expect(AdminAction::query()->count())->toBe(0);
});

test('la liste sépare les parties en cours de l\'historique, salon et solo compris', function (): void {
    $admin = User::factory()->admin()->create();
    $running = Game::factory()->create();
    $solo = Game::factory()->solo()->create();
    $ended = Game::factory()->completed()->create();

    $this->actingAs($admin)
        ->get(route('admin.games.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/games/index')
            ->where('filters.state', 'running')
            ->where('counts.running', 2)
            ->where('counts.ended', 1)
            ->has('games.data', 2)
            ->where('games.data.0.id', $running->id)
            ->where('games.data.1.id', $solo->id)
            ->where('games.data.1.mode', 'solo'));

    $this->actingAs($admin)
        ->get(route('admin.games.index', ['state' => 'ended']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('games.data', 1)
            ->where('games.data.0.id', $ended->id));

    $this->actingAs($admin)
        ->get(route('admin.games.index', ['mode' => 'solo']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('games.data', 1)
            ->where('games.data.0.id', $solo->id));
});

test('le filtre par code de salon est une égalité', function (): void {
    $admin = User::factory()->admin()->create();
    $game = Game::factory()->create();
    Game::factory()->create();
    $code = $game->room()->firstOrFail()->room_code;

    $this->actingAs($admin)
        ->get(route('admin.games.index', ['room' => mb_strtolower($code)]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.room', $code)
            ->has('games.data', 1)
            ->where('games.data.0.id', $game->id));

    $this->actingAs($admin)
        ->get(route('admin.games.index', ['room' => mb_substr($code, 0, 3)]))
        ->assertInertia(fn (Assert $page) => $page->has('games.data', 0));
});

test('la fiche d\'une partie montre chaque réponse juste et fausse d\'un participant', function (): void {
    ['game' => $game, 'alice' => $alice, 'bruno' => $bruno] = inspectedGame();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/games/show')
            ->where('game.id', $game->id)
            ->where('game.terminal', false)
            ->has('leaderboard', 2)
            ->where('rounds.0.disclosed', true)
            ->where('rounds.0.movie.title_original', 'Harbour Lights')
            ->has('rounds.0.tiers', 3)
            ->has('rounds.0.participants', 2)
            ->where('rounds.0.participants.0.player_id', $alice->public_id)
            ->where('rounds.0.participants.0.input_state', 'locked')
            ->where('rounds.0.participants.0.guess.lock_rank', 1)
            ->has('rounds.0.participants.0.wrong_answers', 0)
            ->where('rounds.0.participants.1.player_id', $bruno->public_id)
            ->where('rounds.0.participants.1.wrong_attempts', 2)
            ->where('rounds.0.participants.1.guess', null)
            ->has('rounds.0.participants.1.wrong_answers', 2)
            ->where('rounds.0.participants.1.wrong_answers.0.submitted_text', 'Harbor Light')
            ->where('rounds.0.participants.1.wrong_answers.0.attempt_number', 1)
            ->where('rounds.0.participants.1.wrong_answers.1.source', GuessSource::Choice->value)
            ->where('rounds.0.participants.1.wrong_answers.1.submitted_text', 'Le Port des brumes'));
});

test('une partie en cours ne sérialise rien d\'une manche non révélée', function (): void {
    ['game' => $game, 'alice' => $alice] = inspectedGame();
    $admin = User::factory()->admin()->create();

    foreach ([
        route('admin.games.show', ['game' => $game->id]),
        route('admin.players.show', ['player' => $alice->public_id]),
    ] as $url) {
        $response = $this->actingAs($admin)->get($url)->assertOk();

        // Ni le titre du film en cours, ni la réponse fausse qui s'en approche,
        // nulle part dans la page — pas même masqués côté client.
        expect($response->getContent())
            ->not->toContain('Secret Orchard')
            ->not->toContain('Secret Orchar');
    }

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rounds.1.status', 'running')
            ->where('rounds.1.disclosed', false)
            ->where('rounds.1.movie', null)
            ->where('rounds.1.tiers', [])
            ->where('rounds.1.participants', []));
});

test('une partie terminée montre toutes ses manches jouées', function (): void {
    ['game' => $game] = inspectedGame(ended: true);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.games.show', ['game' => $game->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('game.terminal', true)
            ->where('rounds.1.disclosed', true)
            ->where('rounds.1.movie.title_original', 'Secret Orchard')
            ->where('rounds.1.participants.0.wrong_answers.0.submitted_text', 'Secret Orchar'));
});

test('la fiche d\'un siège reprend ses parties et ses seules réponses', function (): void {
    ['game' => $game, 'bruno' => $bruno] = inspectedGame();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.players.show', ['player' => $bruno->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/players/show')
            ->where('player.public_id', $bruno->public_id)
            ->where('player.nickname', 'Bruno')
            ->has('games', 1)
            ->where('games.0.game.id', $game->id)
            ->where('games.0.rounds.0.participation.player_id', $bruno->public_id)
            ->has('games.0.rounds.0.participation.wrong_answers', 2)
            ->where('games.0.rounds.1.disclosed', false)
            ->where('games.0.rounds.1.participation', null));
});

test('l\'annuaire des sièges compte les invités et signale un pseudo effacé', function (): void {
    $admin = User::factory()->admin()->create();
    $erased = Player::factory()->archivedIdentity()->create();
    $solo = Player::factory()->solo()->withNickname('Solène')->create();

    $this->actingAs($admin)
        ->get(route('admin.players.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/players/index')
            ->where('counts.total', 2)
            ->where('counts.solo', 1)
            ->where('players.data.0.public_id', $solo->public_id)
            ->where('players.data.0.solo', true)
            ->where('players.data.1.public_id', $erased->public_id)
            ->where('players.data.1.erased', true));

    $this->actingAs($admin)
        ->get(route('admin.players.index', ['q' => 'Sol']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('players.data', 1)
            ->where('players.data.0.public_id', $solo->public_id));
});

test('chaque consultation écrit sa lecture sensible, sans recopier la recherche', function (): void {
    ['game' => $game, 'alice' => $alice] = inspectedGame();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.games.index', ['room' => 'ABCDEF']))->assertOk();
    $this->actingAs($admin)->get(route('admin.games.show', ['game' => $game->id]))->assertOk();
    $this->actingAs($admin)->get(route('admin.players.index', ['q' => 'Alice']))->assertOk();
    $this->actingAs($admin)->get(route('admin.players.show', ['player' => $alice->public_id]))->assertOk();

    $lines = AdminAction::query()->orderBy('id')->get();

    expect($lines->map(fn (AdminAction $line): array => [$line->action, $line->subject_type, $line->subject_id])->all())
        ->toBe([
            [AdminActionType::GamesDirectoryViewed, AdminActionSubject::Games, null],
            [AdminActionType::GameViewed, AdminActionSubject::Game, $game->id],
            [AdminActionType::PlayersDirectoryViewed, AdminActionSubject::Players, null],
            [AdminActionType::PlayerViewed, AdminActionSubject::Player, $alice->id],
        ])
        ->and($lines->every(fn (AdminAction $line): bool => $line->details === null))->toBeTrue();
});
