<?php

use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Alias;
use App\Models\Game;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\Player;
use App\Models\Room;
use App\Models\Round;
use App\Settings\EngineConstants;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\GameRef;
use App\Support\Realtime\WirePayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Page du salon en partie — spec 60 § 10.1, § 11.7 et § 11.8 (lot L60-14)
|--------------------------------------------------------------------------
|
| La partie multijoueur est un ÉTAT de la page `game/lobby`, jamais une page
| `game/room` (écart (m) du § 22 bis, fermé par 50 § 7.2) : `room.show` rend
| la même page du lancement au podium, en sombre, avec le paquet de
| resynchronisation (`state`), le jeton d'onglet (`seatToken`) et la prop
| partagée `realtime`. Avant la révélation, rien de la page ne nomme le film
| de la manche (règle 3) : ni titre, ni alias, ni année, ni identifiant.
|
| Vraies parties matérialisées sur de vraies variantes (fichiers du disque
| `frames` simulé, aucune image réelle), étapes jouées par les actions
| réelles à leurs instants théoriques ; les jobs de frontière sont retenus.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 16:00:00.250');
    Date::setTestNow($this->now);
});

/**
 * Un salon en partie : le siège d'un jeton, hôte, et un second siège ; la
 * partie matérialisée sur des films neufs, la manche 1 programmée après le
 * décompte de lancement.
 *
 * @return array{room: Room, game: Game, seat: Player, token: PlayerToken, round: Round, movies: list<Movie>}
 */
function roomPageGame(RoomSettings $settings): array
{
    $room = Room::factory()->playing()->create();
    $game = EngineFixtures::game($settings, room: $room);
    $token = PlayerToken::mint(Locale::French);
    $seat = EngineFixtures::seat($game, ['player_token_hash' => $token->hash()]);
    EngineFixtures::seat($game);
    Room::query()->whereKey($room->id)->update(['host_player_id' => $seat->id]);

    $movies = EngineFixtures::materialize($game);
    $round = EngineFixtures::round($game, 1);
    EngineFixtures::schedule($round, Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()));

    return ['room' => $room, 'game' => $game, 'seat' => $seat, 'token' => $token, 'round' => $round->refresh(), 'movies' => $movies];
}

/**
 * Chargement complet de la page du salon sous ce jeton, à l'instant `$at`.
 * Limiteur remis à neuf : le débit de `game-read` est prouvé ailleurs.
 *
 * @return TestResponse<Response>
 */
function roomPageVisit(TestCase $test, Room $room, PlayerToken $token, CarbonImmutable $at): TestResponse
{
    Cache::flush();
    Date::setTestNow($at);
    LobbyWrites::actAs($test, $token);

    return $test->get(route('room.show', $room))->assertOk();
}

/**
 * Les chaînes qui nomment un film : titre original, translittération, titre
 * de chaque locale et alias.
 *
 * @return list<string>
 */
function roomPageTitles(Movie $movie): array
{
    $strings = [$movie->title_original, $movie->title_original_latin];

    foreach (MovieTitle::query()->where('movie_id', $movie->id)->pluck('title') as $title) {
        $strings[] = $title;
    }

    foreach (Alias::query()->where('movie_id', $movie->id)->pluck('alias') as $alias) {
        $strings[] = $alias;
    }

    return array_values(array_filter($strings, static fn (mixed $title): bool => is_string($title) && $title !== ''));
}

/**
 * Toutes les clés d'un tableau, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $value
 * @return list<string>
 */
function roomPageKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $child) {
        $keys[] = (string) $key;

        if (is_array($child)) {
            array_push($keys, ...roomPageKeys($child));
        }
    }

    return $keys;
}

it('rend la page du salon en état de manche, en sombre, avec state, seatToken et realtime', function (): void {
    [
        'room' => $room, 'game' => $game, 'seat' => $seat, 'token' => $token, 'round' => $round,
    ] = roomPageGame(EngineFixtures::settings());

    // La manche 1 s'ouvre à `T₁` ; le joueur (re)charge la page une seconde
    // plus tard, avec un cookie `appearance` clair hérité d'avant D56.
    EngineFixtures::openTier($round, 1);
    $this->withUnencryptedCookie('appearance', 'light');

    $response = roomPageVisit($this, $room, $token, EngineFixtures::opensAt($round, 1)->addSecond());

    // La page du salon, jamais une page `game/room` : l'état de partie est
    // un état de `game/lobby`.
    $response->assertInertia(fn (Assert $page) => $page
        ->component('game/lobby')
        ->has('state')
        ->has('seatToken')
        ->has('realtime'));

    // Sombre comme tout le site (D56 du 02/10) : le cookie n'y change rien.
    expect(preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $html))->toBe(1)
        ->and($html[0])->not->toContain('data-appearance-forced')
        ->and(preg_match('/\sclass="[^"]*\bdark\b/', $html[0]))->toBe(1);

    $props = $response->inertiaPage()['props'];
    $state = $props['state'];

    // Le paquet porte la partie et la manche EN COURS : palier 1 ouvert, sa
    // seule URL d'image, le siège membre et participant, onglet actif.
    expect($state['gameRef'])->toBe(GameRef::for($game))
        ->and($state['status'])->toBe(GameStatus::Running->value)
        ->and($state['round']['sequenceIndex'])->toBe(1)
        ->and($state['round']['phase'])->toBe('running')
        ->and($state['round']['currentTierIndex'])->toBe(1)
        ->and(array_column($state['round']['images'], 'tierIndex'))->toBe([1])
        ->and($state['round']['reveal'])->toBeNull()
        ->and($state['self']['publicId'])->toBe($seat->public_id)
        ->and($state['self']['member'])->toBeTrue()
        ->and($state['self']['participates'])->toBeTrue()
        ->and($state['self']['seatActive'])->toBeTrue()
        ->and($state['self']['isHost'])->toBeTrue()
        ->and($state['maxAnswerLength'])->toBe($game->settings_snapshot->maxAnswerLength);

    // Le jeton d'onglet frappé au rendu (chargement complet, sans en-tête),
    // en prop seulement, jamais dans le paquet.
    expect($props['seatToken'])->toBeString()->not->toBe('')
        ->and($props['seatToken'])->toBe($seat->refresh()->active_seat_token)
        ->and(json_encode($state, JSON_THROW_ON_ERROR))->not->toContain($props['seatToken']);

    // La configuration d'Echo à l'exécution (60 § 10.5), battement et
    // échantillons d'horloge de `EngineConstants`.
    expect(array_keys($props['realtime']))->toBe(['key', 'host', 'port', 'scheme', 'heartbeatIntervalMs', 'clockSamples'])
        ->and($props['realtime']['heartbeatIntervalMs'])->toBe(EngineConstants::heartbeatIntervalMs())
        ->and($props['realtime']['clockSamples'])->toBe(EngineConstants::clockSamples());

    // Une visite qui présente le jeton actif garde la même page et le même
    // onglet : la partie se poursuit sans navigation vers une autre page.
    $again = $this->get(route('room.show', $room), [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        EnsureActiveSeat::HEADER => $props['seatToken'],
    ]);

    expect($again->json('component'))->toBe('game/lobby')
        ->and($again->json('props.seatToken'))->toBe($props['seatToken'])
        ->and($again->json('props.state.round.phase'))->toBe('running');
});

it('ne sérialise jamais le film de la manche dans les props de la page', function (): void {
    // Expert : aucune proposition de QCM, seule exception de la règle 3.
    $settings = RoomSettings::fromInput([
        'framesPerRound' => RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
        'inputDifficulty' => 'expert',
    ]);
    ['room' => $room, 'game' => $game, 'token' => $token, 'round' => $round, 'movies' => $movies] = roomPageGame($settings);

    // Des titres reconnaissables, pour chaque film du tirage : titre
    // original, translittération, titres FR et EN, un alias.
    foreach ($movies as $index => $movie) {
        $movie->forceFill([
            'title_original' => "Zqxoriginal{$index} Obliquus",
            'title_original_latin' => "Zqxlatin{$index} Obliquus",
        ])->save();
        MovieTitle::query()->where('movie_id', $movie->id)->where('locale', Locale::French->value)
            ->update(['title' => "Zqxtitre{$index} Oblique"]);
        MovieTitle::query()->where('movie_id', $movie->id)->where('locale', Locale::English->value)
            ->update(['title' => "Zqxtitle{$index} Oblique"]);
        Alias::query()->where('movie_id', $movie->id)->delete();
        Alias::factory()->create(['movie_id' => $movie->id, 'locale' => Locale::French->value, 'alias' => "Zqxalias{$index}"]);
    }

    $current = $movies[0];
    $titles = roomPageTitles($current->refresh());
    $others = array_merge(...array_map(
        static fn (Movie $movie): array => roomPageTitles($movie->refresh()),
        array_slice($movies, 1),
    ));

    expect($titles)->toHaveCount(5)->and($others)->not->toBe([]);

    // Ce que rien de la page ne nomme avant `revealStartsAt` : le film de la
    // manche, ni aucun film du tirage.
    $assertSilent = function (TestResponse $response, string $when) use ($titles, $others): void {
        $page = $response->inertiaPage();
        $json = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html = (string) $response->getContent();

        foreach ([...$titles, ...$others] as $title) {
            expect($json)->not->toContain($title, "{$when} : « {$title} » dans les props")
                ->and($html)->not->toContain($title, "{$when} : « {$title} » dans le document");
        }

        $state = $page['props']['state'];

        // Ni identifiant interne, ni objet, ni année, ni film, ni titre, et
        // aucune révélation dans le paquet.
        WirePayload::assertSafe($state, "room.show ({$when})");

        expect(array_intersect(roomPageKeys($state), ['movie', 'movieId', 'year', 'titles', 'originalTitle', 'originalTitleLatin', 'originalLanguage', 'finders']))
            ->toBe([], "{$when} : clé de film dans le paquet")
            ->and($state['round']['reveal'] ?? null)->toBeNull();
    };

    // Décompte de lancement : manche programmée, aucune image servable.
    $assertSilent(roomPageVisit($this, $room, $token, Date::now()->toImmutable()), 'décompte');

    // Palier 1, puis dernier palier : la manche court.
    EngineFixtures::openTier($round, 1);
    $assertSilent(roomPageVisit($this, $room, $token, EngineFixtures::opensAt($round, 1)->addSecond()), 'palier 1');

    foreach (range(2, $game->frames_per_round) as $tierIndex) {
        EngineFixtures::openTier($round, $tierIndex);
    }

    $last = EngineFixtures::opensAt($round, $game->frames_per_round)->addSecond();
    $assertSilent(roomPageVisit($this, $room, $token, $last), 'dernier palier');

    // Grâce finale : manche close à `D`, titres pas encore émis.
    EngineFixtures::close($round);
    $closed = roomPageVisit($this, $room, $token, EngineFixtures::durationEnd($round)->addMilliseconds(intdiv($game->tier_grace_ms, 2)));

    expect($closed->inertiaPage()['props']['state']['round']['phase'])->toBe('closed');
    $assertSilent($closed, 'grâce finale');

    // Contraste — la détection voit bien un titre : à la révélation, le
    // paquet porte les titres du film de la manche, et d'aucun autre.
    EngineFixtures::reveal($round);
    $revealedAt = $round->refresh()->ended_at?->addMilliseconds($game->tier_grace_ms) ?? throw new LogicException('manche non close');
    $revealed = roomPageVisit($this, $room, $token, $revealedAt->addMillisecond());
    $json = json_encode($revealed->inertiaPage(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    expect($revealed->inertiaPage()['props']['state']['round']['phase'])->toBe('revealing')
        ->and($json)->toContain('Zqxtitre0 Oblique')
        ->and($json)->toContain('Zqxtitle0 Oblique')
        ->and($json)->not->toContain('Zqxalias0');

    foreach ($others as $title) {
        expect($json)->not->toContain($title);
    }
});
