<?php

use App\Actions\Game\FinalizeGame;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\SettingPresetKey;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Settings\EngineConstants;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\SettingPresetCatalog;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\GameRef;
use App\Support\Room\RoomSettingsPresenter;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Page du salon `game/lobby` — spec 50 § 7.2 et § 8.1 (lot L50-4)
|--------------------------------------------------------------------------
|
| `room.show` rend UNE page, du lobby au podium : `game/lobby`, dans tout
| statut non archivé, au siège que tient le jeton. Ses props : le paquet de
| 60 (`state`), le jeton d'onglet (`seatToken`), l'état des réglages et le
| grisage des presets recalculés à chaque rendu — rechargement partiel
| compris —, les bornes par `N`, les limites de plateforme, le seuil de
| lancement et la disponibilité des éditeurs. Le code du salon n'entre
| jamais dans un titre.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();

    $this->now = CarbonImmutable::parse('2026-09-27 15:04:05.250');
    $this->travelTo($this->now);
});

/** Les props de page du § 8.1, dans l'ordre de `LobbyPageProps`. */
const LOBBY_PAGE_PROPS = [
    'room', 'state', 'seatToken', 'settings', 'bounds', 'limits', 'presets', 'launch', 'editor', 'themes', 'configs',
];

/**
 * Un salon au lobby dont l'hôte est tenu par un jeton neuf, onglet actif
 * frappé.
 *
 * @return array{0: Room, 1: Player, 2: PlayerToken}
 */
function lobbyPageRoom(?RoomSettings $settings = null): array
{
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token, $settings);

    return [$room, $host, $token];
}

/**
 * Une visite Inertia de la page du salon, comme le client l'envoie :
 * version d'assets, jeton d'onglet présenté s'il est donné, et rechargement
 * partiel des props nommées s'il y en a. Limiteur remis à neuf : le débit de
 * `game-read` est prouvé ailleurs.
 *
 * @param  list<string>  $only
 * @return TestResponse<Response>
 */
function lobbyPageVisit(TestCase $test, Room $room, ?string $seatToken, array $only = []): TestResponse
{
    Cache::flush();

    $headers = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ];

    if ($seatToken !== null) {
        $headers[EnsureActiveSeat::HEADER] = $seatToken;
    }

    if ($only !== []) {
        $headers['X-Inertia-Partial-Component'] = 'game/lobby';
        $headers['X-Inertia-Partial-Data'] = implode(',', $only);
    }

    return $test->get(route('room.show', $room), $headers);
}

/**
 * Les props propres d'une réponse de la page, sans les props partagées.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function lobbyPageOwnProps(TestResponse $response): array
{
    $page = $response->headers->get('X-Inertia') === 'true' ? $response->json() : $response->inertiaPage();

    /** @var array<string, mixed> $props */
    $props = $page['props'];

    return array_diff_key($props, array_flip([
        'name', 'auth', 'sidebarOpen', 'accountsOpen', 'frameFormat', 'realtime', 'maintenance',
        'locale', 'locales', 'translations', 'errors', 'flash',
    ]));
}

/**
 * Une valeur telle que le client la reçoit : après un aller-retour JSON.
 */
function lobbyPageJson(mixed $value): mixed
{
    return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
}

it("rend le lobby au siège du jeton avec l'état des réglages, les bornes par N et les presets", function (): void {
    PoolFixtures::movies(2);
    [$room, $host, $token] = lobbyPageRoom();
    $guest = Player::factory()->for($room)->withNickname('Zoé')->create();

    LobbyWrites::actAs($this, $token);

    $response = $this->get(route('room.show', $room))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page->component('game/lobby'));

    $props = lobbyPageOwnProps($response);
    $room->refresh();

    // Exactement les props de `LobbyPageProps`, dans son ordre.
    expect(array_keys($props))->toBe(LOBBY_PAGE_PROPS)
        ->and($props['room'])->toBe(['code' => $room->room_code]);

    // Le paquet de 60, au siège du jeton : sans partie, l'hôte, les deux
    // sièges du salon.
    expect($props['state']['gameRef'])->toBeNull()
        ->and($props['state']['self']['publicId'])->toBe($host->public_id)
        ->and($props['state']['self']['isHost'])->toBeTrue()
        ->and($props['state']['self']['seatActive'])->toBeTrue()
        ->and(array_column($props['state']['seats'], 'publicId'))->toBe([$host->public_id, $guest->public_id]);

    // L'état des réglages, calculé par le présentateur de 50 à l'instant du
    // rendu : réglages sous les clés client, avertissements, vivier.
    expect($props['settings'])->toBe(lobbyPageJson(RoomSettingsPresenter::state($room, $this->now)))
        ->and($props['settings']['pool']['count'])->toBe(2)
        ->and($props['settings']['pool']['blocked'])->toBeTrue();

    // Les bornes, découpées pour chaque `N` des bornes : le retour immédiat
    // quand `N` change a les bornes du `N` visé.
    expect($props['bounds'])->toBe(lobbyPageJson(RoomSettingsBounds::toClient()))
        ->and(array_map('intval', array_keys($props['bounds']['byFramesPerRound'])))
        ->toBe(range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND));

    foreach ($props['bounds']['byFramesPerRound'] as $framesPerRound => $bounds) {
        expect($bounds['roundDuration']['min'])->toBe(RoomSettingsBounds::minRoundDuration((int) $framesPerRound));
    }

    // Les limites de plateforme (prop de page, jamais partagée), les presets
    // triés par position, le seuil de lancement, les éditeurs du J1.
    expect($props['limits'])->toBe(lobbyPageJson(PlatformLimits::current()->toArray()))
        ->and($props['presets'])->toBe(lobbyPageJson(RoomSettingsPresenter::presets($room, $this->now)))
        ->and(array_column($props['presets'], 'key'))->toBe(array_map(
            static fn (SettingPresetKey $key): string => $key->value,
            collect(SettingPresetKey::cases())
                ->sortBy(static fn (SettingPresetKey $key): int => SettingPresetCatalog::positionFor($key))
                ->values()
                ->all(),
        ))
        ->and($props['launch'])->toBe(['minConnected' => RoomSettingsBounds::MIN_CONNECTED_PLAYERS_TO_LAUNCH])
        ->and($props['editor'])->toBe(['advancedAvailable' => false, 'themeSelectorVisible' => false, 'lateJoinAvailable' => true])
        ->and($props['themes'])->toBeNull()
        ->and($props['configs'])->toBeNull();

    // Un autre siège reçoit la même page, en lecture seule : pas l'hôte.
    $guestToken = PlayerToken::mint(Locale::English);
    Player::query()->whereKey($guest->id)->update(['player_token_hash' => $guestToken->hash()]);
    LobbyWrites::actAs($this, $guestToken);

    $guestProps = lobbyPageOwnProps($this->get(route('room.show', $room))->assertOk());

    expect($guestProps['state']['self']['publicId'])->toBe($guest->public_id)
        ->and($guestProps['state']['self']['isHost'])->toBeFalse()
        ->and($guestProps['settings'])->toBe($props['settings']);
});

it("répare l'hôte au rendu quand le siège hôte n'est plus une cible valide", function (): void {
    [$room, $host, $token] = lobbyPageRoom();
    $recorder = RecordingBroadcaster::install();

    // L'hôte est parti ; le plus ancien siège connecté restant prend le rôle.
    Player::query()->whereKey($host->id)->update(['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()]);
    $older = Player::factory()->for($room)->create(['joined_at' => Date::now()->subMinutes(2)]);
    $younger = Player::factory()->for($room)->create(['joined_at' => Date::now()->subMinute()]);
    $viewerToken = PlayerToken::mint(Locale::French);
    Player::query()->whereKey($younger->id)->update(['player_token_hash' => $viewerToken->hash()]);

    LobbyWrites::actAs($this, $viewerToken);

    $props = lobbyPageOwnProps($this->get(route('room.show', $room))->assertOk());

    expect($room->refresh()->host_player_id)->toBe($older->id)
        ->and(collect($props['state']['seats'])->firstWhere('isHost', true)['publicId'] ?? null)->toBe($older->public_id)
        ->and($props['state']['self']['isHost'])->toBeFalse()
        ->and(array_column($recorder->sent, 'event'))->toContain('host.changed');

    // Hôte valide : aucun transfert, aucune écriture.
    $recorder->sent = [];
    $this->get(route('room.show', $room))->assertOk();

    expect($room->refresh()->host_player_id)->toBe($older->id)
        ->and(array_column($recorder->sent, 'event'))->not->toContain('host.changed');

    // Course : l'hôte part, puis un autre geste répare le rôle entre la
    // lecture sans verrou et le verrou du salon. Le critère est relu sous le
    // verrou : le rendu ne transfère pas une seconde fois.
    Player::query()->whereKey($older->id)->update(['connection_state' => PlayerConnectionState::Left, 'left_at' => Date::now()]);
    $recorder->sent = [];
    $baseLevel = DB::transactionLevel();
    $raced = false;

    DB::beforeExecuting(function (string $query) use (&$raced, $baseLevel, $room, $younger): void {
        if ($raced || DB::transactionLevel() <= $baseLevel || ! str_starts_with($query, 'select * from "room"')) {
            return;
        }

        $raced = true;
        DB::table('room')->where('id', $room->id)->update(['host_player_id' => $younger->id]);
    });

    $this->get(route('room.show', $room))->assertOk();

    expect($raced)->toBeTrue()
        ->and($room->refresh()->host_player_id)->toBe($younger->id)
        ->and(array_column($recorder->sent, 'event'))->not->toContain('host.changed');
});

it("frappe un jeton d'onglet au rendu et le passe en prop seatToken", function (): void {
    [$room, $host, $token] = lobbyPageRoom();
    Player::query()->whereKey($host->id)->update(['active_seat_token' => null]);

    LobbyWrites::actAs($this, $token);

    // Chargement complet, sans en-tête : un jeton est frappé, écrit sur le
    // siège et rendu en prop — jamais dans le paquet.
    $first = lobbyPageOwnProps($this->get(route('room.show', $room))->assertOk());

    expect($first['seatToken'])->toBeString()->not->toBe('')
        ->and($host->refresh()->active_seat_token)->toBe($first['seatToken'])
        ->and(json_encode($first['state'], JSON_THROW_ON_ERROR))->not->toContain($first['seatToken'])
        ->and($first['state']['self']['seatActive'])->toBeTrue();

    // Visite présentant le jeton actif : rien n'est frappé.
    $same = lobbyPageOwnProps(lobbyPageVisit($this, $room, $first['seatToken'])->assertOk());

    expect($same['seatToken'])->toBe($first['seatToken'])
        ->and($host->refresh()->active_seat_token)->toBe($first['seatToken']);

    // Un second chargement complet (autre onglet) prend la main.
    $second = lobbyPageOwnProps($this->get(route('room.show', $room))->assertOk());

    expect($second['seatToken'])->not->toBe($first['seatToken'])
        ->and($host->refresh()->active_seat_token)->toBe($second['seatToken'])
        ->and($second['state']['self']['seatActive'])->toBeTrue();
});

it('recalcule le vivier à chaque rendu du lobby', function (): void {
    $settings = RoomSettings::fromInput(['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT]);
    PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1);
    [$room, $host, $token] = lobbyPageRoom($settings);

    LobbyWrites::actAs($this, $token);

    $before = lobbyPageOwnProps(lobbyPageVisit($this, $room, $host->active_seat_token)->assertOk());

    expect($before['settings']['pool']['count'])->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT - 1)
        ->and($before['settings']['pool']['blocked'])->toBeTrue();

    // Un film publié entre deux rendus : aucun événement de catalogue n'est
    // poussé au lobby (§ 9.1), le rendu suivant le compte.
    PoolFixtures::movie();

    $after = lobbyPageOwnProps(lobbyPageVisit($this, $room, $host->active_seat_token)->assertOk());

    expect($after['settings']['pool']['count'])->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT)
        ->and($after['settings']['pool']['blocked'])->toBeFalse()
        ->and($after['settings'])->toBe(lobbyPageJson(RoomSettingsPresenter::state($room->refresh(), $this->now)))
        ->and($after['presets'])->toBe(lobbyPageJson(RoomSettingsPresenter::presets($room, $this->now)));
});

it('rend game/lobby au siège du jeton quand le salon est en partie, podium compris, sans changer de page, avec un state qui porte la partie', function (): void {
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $room = Room::factory()->playing()->create();
    $game = EngineFixtures::game(EngineFixtures::settings(), room: $room);
    $token = PlayerToken::mint(Locale::French);
    $seat = EngineFixtures::seat($game, [
        'player_token_hash' => $token->hash(),
        'active_seat_token' => (string) Str::ulid(),
    ]);
    Room::query()->whereKey($room->id)->update(['host_player_id' => $seat->id]);
    EngineFixtures::materialize($game);
    EngineFixtures::schedule(
        EngineFixtures::round($game, 1),
        Date::now()->toImmutable()->addMilliseconds(EngineConstants::launchCountdownMs()),
    );

    LobbyWrites::actAs($this, $token);

    // Partie en cours : la même page, le paquet porte la partie.
    $running = $this->get(route('room.show', $room))->assertOk();
    $running->assertInertia(fn (Assert $page) => $page->component('game/lobby'));
    $props = lobbyPageOwnProps($running);

    expect(array_keys($props))->toBe(LOBBY_PAGE_PROPS)
        ->and($props['state']['gameRef'])->toBe(GameRef::for($game))
        ->and($props['state']['status'])->toBe(GameStatus::Running->value)
        ->and($props['state']['roundsCount'])->toBe($game->rounds_count)
        ->and($props['state']['round']['roundNumber'])->toBe(1)
        ->and($props['state']['self']['member'])->toBeTrue()
        ->and($props['state'])->toBe(lobbyPageJson(GameStateBuilder::build(
            $game->refresh(),
            $seat->refresh(),
            $this->now,
            $props['seatToken'],
        )));

    // Podium (partie gelée, salon toujours `playing` jusqu'au « Rejouer ») :
    // la même page, le paquet porte le podium.
    $frozenAt = $this->now->addSecond();
    $this->travelTo($frozenAt);
    app(FinalizeGame::class)->handle($game->refresh(), GameStatus::Interrupted, $frozenAt);

    $podium = lobbyPageVisit($this, $room, $props['seatToken'])->assertOk();
    $podiumProps = lobbyPageOwnProps($podium);

    expect($podium->json('component'))->toBe('game/lobby')
        ->and($room->refresh()->status->value)->toBe('playing')
        ->and($podiumProps['state']['gameRef'])->toBe(GameRef::for($game))
        ->and($podiumProps['state']['podium'])->toBe(lobbyPageJson(Scoreboard::podium($game->refresh())))
        ->and($podiumProps['seatToken'])->toBe($props['seatToken']);

    // Un siège entré pendant la partie, sans participation : la même page,
    // la partie du salon, et aucun statut de membre.
    $waitingToken = PlayerToken::mint(Locale::English);
    $waiting = Player::factory()->for($room)->create(['player_token_hash' => $waitingToken->hash()]);
    LobbyWrites::actAs($this, $waitingToken);

    $waitingProps = lobbyPageOwnProps(tap($this->get(route('room.show', $room))->assertOk())
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby')->etc()));

    expect($waitingProps['state']['gameRef'])->toBe(GameRef::for($game))
        ->and($waitingProps['state']['self']['publicId'])->toBe($waiting->public_id)
        ->and($waitingProps['state']['self']['member'])->toBeFalse()
        ->and(array_column($waitingProps['state']['seats'], 'publicId'))->not->toContain($waiting->public_id);
});

it('un rechargement partiel de settings et presets sous X-Seat-Token recalcule le vivier sans frapper de nouveau jeton d\'onglet', function (): void {
    $recorder = RecordingBroadcaster::install();
    $settings = RoomSettings::fromInput(['roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT]);
    [$room, $host, $token] = lobbyPageRoom($settings);
    $active = (string) $host->active_seat_token;

    LobbyWrites::actAs($this, $token);

    $first = lobbyPageVisit($this, $room, $active, ['settings', 'presets'])->assertOk();

    // Les deux props demandées, et elles seules : ni paquet ni jeton d'onglet.
    expect(array_keys(lobbyPageOwnProps($first)))->toBe(['settings', 'presets'])
        ->and(lobbyPageOwnProps($first)['settings']['pool']['count'])->toBe(0);

    // Le catalogue bouge ; le rechargement suivant recalcule vivier et grisage.
    PoolFixtures::movies(RoomSettingsBounds::MIN_ROUNDS_COUNT);

    $second = lobbyPageOwnProps(lobbyPageVisit($this, $room, $active, ['settings', 'presets'])->assertOk());

    expect($second['settings']['pool']['count'])->toBe(RoomSettingsBounds::MIN_ROUNDS_COUNT)
        ->and($second['settings']['pool']['blocked'])->toBeFalse()
        ->and($second['settings'])->toBe(lobbyPageJson(RoomSettingsPresenter::state($room->refresh(), $this->now)))
        ->and($second['presets'])->toBe(lobbyPageJson(RoomSettingsPresenter::presets($room, $this->now)));

    // Aucun jeton frappé, aucun onglet supplanté.
    expect($host->refresh()->active_seat_token)->toBe($active)
        ->and(array_column($recorder->sent, 'event'))->not->toContain('seat.superseded');

    // Contraste : le même rechargement SANS l'en-tête supplanterait son
    // propre onglet — d'où l'en-tête sur toute requête du lobby (§ 8.2).
    lobbyPageVisit($this, $room, null, ['settings', 'presets'])->assertOk();

    expect($host->refresh()->active_seat_token)->not->toBe($active)
        ->and(array_column($recorder->sent, 'event'))->toContain('seat.superseded');
});

it('ne met jamais le code du salon dans le titre de la page', function (): void {
    [$room, $host, $token] = lobbyPageRoom();
    LobbyWrites::actAs($this, $token);

    $html = (string) $this->get(route('room.show', $room))->assertOk()->getContent();

    // Le titre rendu par Blade, et aucune balise Open Graph ni meta, ne
    // portent le code.
    expect(preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $title))->toBe(1)
        ->and(mb_strtoupper($title[1]))->not->toContain((string) $room->room_code);

    preg_match_all('#<meta\b[^>]*>#i', $html, $metas);

    foreach ($metas[0] as $meta) {
        expect(mb_strtoupper($meta))->not->toContain((string) $room->room_code);
    }

    // Le titre que pose la page : la seule clé `room.lobby.title`, sans
    // placeholder, identique pour tous les salons.
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/pages/game/lobby.tsx')));

    expect(preg_match_all('/<Head\b[^>]*>/', $source, $heads))->toBe(1)
        ->and($heads[0][0])->toBe("<Head title={t('room.lobby.title')} />");

    foreach (Locale::cases() as $locale) {
        $text = trans('room.lobby.title', [], $locale->value);

        expect($text)->toBeString()->not->toBe('room.lobby.title')
            ->and($text)->not->toContain(':');
    }

    // Aucune prop ne s'appelle `title` : le code ne voyage qu'en `room.code`.
    $json = json_encode(lobbyPageOwnProps($this->get(route('room.show', $room))), JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('"title"')
        ->and(substr_count($json, '"'.$room->room_code.'"'))->toBe(1);
});
