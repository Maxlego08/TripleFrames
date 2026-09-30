<?php

use App\Actions\Game\ClaimSeatTab;
use App\Enums\GamePlayerStatus;
use App\Enums\Locale;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Identity\PlayerTokenManager;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\GameRef;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Siège actif et second onglet — spec 60 § 10.2 et § 12.7, contrat C7
| § 2.4, § 2.5 et § 4.9 (lot L60-4)
|--------------------------------------------------------------------------
|
| « Un player_token = un siège ; le second onglet prend la main, le premier
| passe en lecture seule. » `ClaimSeatTab` frappe le jeton d'onglet au rendu
| d'une page, `GameStateBuilder` en tire `self.seatActive`, `seat.active`
| refuse en 409 l'écriture d'un onglet supplanté.
|
| Les pages réelles naissent plus tard (`room.show` de 50, `solo.show` de
| L60-16) : la page de salon est ici une route de test qui suit à la lettre
| la séquence imposée à tout contrôleur de page (§ 12.7) — `ClaimSeatTab`
| sur l'en-tête `X-Seat-Token`, PUIS `GameStateBuilder::build()` sur le
| jeton rendu, props `state` et `seatToken`. De même, aucune écriture de jeu
| n'existe encore sous `seat.active` : des routes de test la portent, dans
| la pile réelle du groupe `web`.
|
*/

/**
 * Le jeton du joueur, posé en cookie comme le navigateur l'envoie — requêtes
 * JSON comprises (`withCredentials()`), comme le `fetch` same-origin du client.
 */
function seatTakeoverActAs(TestCase $test, PlayerToken $token): void
{
    $test->withCredentials()
        ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));
}

/** Un siège de salon tenu par ce jeton, sans onglet ayant encore pris la main. */
function seatTakeoverSeat(Room $room, PlayerToken $token): Player
{
    return Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
        'active_seat_token' => null,
    ]);
}

/**
 * La page de salon émulée et une écriture de jeu sous `seat.active`.
 *
 * `$writes` compte les écritures qui ont atteint le contrôleur.
 */
function seatTakeoverRoutes(int &$writes = 0): void
{
    config(['inertia.testing.ensure_pages_exist' => false]);

    // Séquence imposée à tout contrôleur de page de salon (§ 12.7).
    Route::middleware('web')->get('/_test/seat-takeover/r/{room}', function (Request $request, Room $room, PlayerTokenManager $tokens, ClaimSeatTab $claim): InertiaResponse {
        $seat = $tokens->seatIn($request, $room);

        abort_if($seat === null, Response::HTTP_FORBIDDEN);

        $seatToken = $claim->handle($seat, EnsureActiveSeat::presentedToken($request));

        return Inertia::render('game/lobby', [
            'state' => GameStateBuilder::build(null, $seat, Date::now()->toImmutable(), $seatToken),
            'seatToken' => $seatToken,
        ]);
    });

    Route::middleware(['web', 'seat.active', 'throttle:game-write'])
        ->post('/_test/seat-takeover/r/{room}/write', function () use (&$writes): Response {
            $writes++;

            return response()->noContent();
        });
}

/**
 * Chargement complet de la page : aucun en-tête `X-Seat-Token`.
 *
 * @return TestResponse<Response>
 */
function seatTakeoverFullLoad(TestCase $test, Room $room): TestResponse
{
    return $test->get('/_test/seat-takeover/r/'.$room->room_code)->assertOk();
}

/**
 * Visite Inertia de la page, présentant un jeton d'onglet.
 *
 * @return TestResponse<Response>
 */
function seatTakeoverVisit(TestCase $test, Room $room, ?string $seatToken): TestResponse
{
    $headers = [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
    ];

    if ($seatToken !== null) {
        $headers[EnsureActiveSeat::HEADER] = $seatToken;
    }

    return $test->get('/_test/seat-takeover/r/'.$room->room_code, $headers)->assertOk();
}

/** Le jeton d'onglet rendu en prop `seatToken`. */
function seatTakeoverProp(TestResponse $response, string $prop): mixed
{
    $page = $response->headers->get('X-Inertia') === 'true'
        ? $response->json()
        : $response->viewData('page');

    expect($page)->toBeArray();

    return data_get($page, 'props.'.$prop);
}

beforeEach(function (): void {
    $this->withoutVite();
});

it('un chargement complet frappe un nouveau jeton de siège et émet seat.superseded', function (): void {
    $recorder = RecordingBroadcaster::install();
    seatTakeoverRoutes();

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);

    // Premier chargement : aucun onglet ne tenait le siège, rien à supplanter.
    $first = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');

    expect($first)->toBeString()
        ->and($seat->fresh()?->active_seat_token)->toBe($first)
        ->and($recorder->sent)->toBe([]);

    // Second chargement complet, sans en-tête : un autre onglet prend la main.
    $second = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');

    expect($second)->toBeString()->not->toBe($first)
        ->and($seat->fresh()?->active_seat_token)->toBe($second)
        ->and($recorder->sent)->toHaveCount(1);

    // Ciblé sur le canal PRIVÉ du siège, charge vide, hors partie : gameRef nul.
    $sent = $recorder->sent[0];

    expect($sent['event'])->toBe('seat.superseded')
        ->and($sent['channels'])->toBe(['private-'.ChannelNames::seat($seat)])
        ->and(array_keys($sent['payload']))->toBe(['v', 'serverNow', 'gameRef'])
        ->and($sent['payload']['gameRef'])->toBeNull()
        ->and($sent['json'])->not->toContain($first)
        ->and($sent['json'])->not->toContain($second);

    // En partie, l'événement porte la référence de la partie en cours.
    $game = Game::factory()->forRoom($room)->create();
    $third = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');

    expect($third)->not->toBe($second)
        ->and($recorder->sent)->toHaveCount(2)
        ->and($recorder->sent[1]['payload']['gameRef'])->toBe(GameRef::for($game));

    // Une transaction annulée n'écrit ni n'émet rien.
    try {
        DB::transaction(function () use ($seat): void {
            app(ClaimSeatTab::class)->handle(Player::query()->findOrFail($seat->id), null);

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect($seat->fresh()?->active_seat_token)->toBe($third)
        ->and($recorder->sent)->toHaveCount(2);

    // En solo, un nouvel onglet prend la main SANS diffusion : un siège sans
    // salon n'a pas de canal (§ 11.2), et la prise d'onglet ne lève pas.
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);
    $before = $solo->active_seat_token;

    $minted = app(ClaimSeatTab::class)->handle($solo, null);

    expect($minted)->not->toBe($before)
        ->and($solo->fresh()?->active_seat_token)->toBe($minted)
        ->and($recorder->sent)->toHaveCount(2);
});

it('une visite présentant le jeton actif ne re-frappe pas', function (): void {
    $recorder = RecordingBroadcaster::install();
    seatTakeoverRoutes();

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::English);
    $seat = seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);

    $active = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');
    $updatedAt = $seat->fresh()?->updated_at;

    Date::setTestNow(Date::now()->addSeconds(5));

    // Rechargement partiel sous l'en-tête : ni frappe, ni écriture, ni
    // diffusion — un changement de langue ne supplante pas son propre onglet.
    foreach (range(1, 3) as $visit) {
        seatTakeoverVisit($this, $room, $active);
    }

    expect($seat->fresh()?->active_seat_token)->toBe($active)
        ->and($seat->fresh()?->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and($recorder->sent)->toBe([]);

    // L'action seule, sur le jeton actif : rendu inchangé, rien d'écrit —
    // jugé sur la ligne relue sous verrou, même quand l'instance de
    // l'appelant est périmée.
    $stale = Player::query()->findOrFail($seat->id);
    $stale->setRawAttributes([...$stale->getAttributes(), 'active_seat_token' => (string) Str::ulid()], sync: true);

    expect(app(ClaimSeatTab::class)->handle(Player::query()->findOrFail($seat->id), $active))->toBe($active)
        ->and(app(ClaimSeatTab::class)->handle($stale, $active))->toBe($active)
        ->and($stale->active_seat_token)->toBe($active)
        ->and($seat->fresh()?->active_seat_token)->toBe($active)
        ->and($recorder->sent)->toBe([]);

    // Une visite qui ne présente PAS le jeton actif — un jeton inconnu, ou
    // aucun — frappe, elle : c'est un autre onglet.
    $other = seatTakeoverProp(seatTakeoverVisit($this, $room, (string) Str::ulid()), 'seatToken');

    expect($other)->not->toBe($active)
        ->and($seat->fresh()?->active_seat_token)->toBe($other)
        ->and($recorder->sent)->toHaveCount(1);
});

it('une écriture présentant un jeton supplanté est refusée en 409', function (): void {
    $writes = 0;
    seatTakeoverRoutes($writes);

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);
    $url = '/_test/seat-takeover/r/'.$room->room_code.'/write';

    // Aucun onglet n'a encore pris la main : aucune requête ne tient le siège.
    $this->postJson($url, [], [EnsureActiveSeat::HEADER => (string) Str::ulid()])
        ->assertStatus(Response::HTTP_CONFLICT);

    $superseded = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');
    $active = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');

    expect($active)->not->toBe($superseded);

    // L'onglet supplanté : 409, code en données, rien n'atteint l'écriture.
    $this->postJson($url, [], [EnsureActiveSeat::HEADER => $superseded])
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => 'seat_superseded']);

    // Sans en-tête : même refus.
    $this->postJson($url)->assertStatus(Response::HTTP_CONFLICT)->assertExactJson(['code' => 'seat_superseded']);

    expect($writes)->toBe(0);

    // L'onglet actif écrit.
    $this->postJson($url, [], [EnsureActiveSeat::HEADER => $active])->assertNoContent();

    expect($writes)->toBe(1);

    // Le jeton d'onglet d'un AUTRE siège ne vaut rien ici.
    $neighbour = Player::factory()->create(['room_id' => $room->id]);

    $this->postJson($url, [], [EnsureActiveSeat::HEADER => (string) $neighbour->active_seat_token])
        ->assertStatus(Response::HTTP_CONFLICT);

    // Le code se lit dans les deux langues (`game.errors.seat_superseded`).
    foreach (Locale::cases() as $locale) {
        expect(trans('game.errors.'.EnsureActiveSeat::SUPERSEDED, locale: $locale->value))
            ->toBeString()->not->toBe('game.errors.'.EnsureActiveSeat::SUPERSEDED);
    }

    // Sans siège tenu par le jeton, ou siège expulsé : 403, jamais 409.
    seatTakeoverActAs($this, PlayerToken::mint(Locale::French));
    $this->postJson($url, [], [EnsureActiveSeat::HEADER => $active])->assertForbidden();

    seatTakeoverActAs($this, $token);
    $seat->forceFill(['kicked_at' => Date::now(), 'left_at' => Date::now()])->save();
    $this->postJson($url, [], [EnsureActiveSeat::HEADER => $active])->assertForbidden();

    expect($writes)->toBe(1);
});

it("le jeton de siège actif est un ULID applicatif et jamais l'identifiant de session", function (): void {
    seatTakeoverRoutes();

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);

    $tokens = [];

    foreach (range(1, 3) as $load) {
        $response = seatTakeoverFullLoad($this, $room);
        $seatToken = seatTakeoverProp($response, 'seatToken');

        // La requête a bien une session : le jeton n'en est pas l'identifiant.
        expect($response->baseRequest->hasSession())->toBeTrue();

        $sessionId = $response->baseRequest->session()->getId();

        expect($seatToken)->toBeString()
            ->and(Str::isUlid((string) $seatToken))->toBeTrue()
            ->and($seatToken)->not->toBe($sessionId)
            ->and(str_contains($sessionId, (string) $seatToken))->toBeFalse()
            ->and($seat->fresh()?->active_seat_token)->toBe($seatToken);

        // Jamais dans le paquet : seule la prop `seatToken` le porte.
        expect(json_encode(seatTakeoverProp($response, 'state'), JSON_THROW_ON_ERROR))->not->toContain((string) $seatToken);

        $tokens[] = $seatToken;
    }

    expect(array_unique($tokens))->toHaveCount(3);
});

it('le paquet rendu avec la page qui vient de frapper le jeton porte seatActive vrai', function (): void {
    seatTakeoverRoutes();

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::English);
    $seat = seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);

    // Un chargement complet n'envoie jamais `X-Seat-Token` : c'est le jeton
    // RENDU par `ClaimSeatTab`, appelée avant, qui fait l'onglet actif.
    seatTakeoverFullLoad($this, $room)->assertInertia(fn (Assert $page) => $page
        ->component('game/lobby', shouldExist: false)
        ->where('state.self.seatActive', true)
        ->where('state.self.publicId', $seat->public_id)
        ->missing('state.seatToken')
        ->has('seatToken'));

    // Le paquet construit sur le jeton présenté — aucun à un chargement
    // complet — dirait l'inverse : l'ordre ClaimSeatTab puis build compte.
    expect(GameStateBuilder::build(null, Player::query()->findOrFail($seat->id), Date::now()->toImmutable(), null)['self']['seatActive'])
        ->toBeFalse();
});

it('une visite présentant le jeton actif reçoit ce même jeton en prop seatToken', function (): void {
    seatTakeoverRoutes();

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    seatTakeoverSeat($room, $token);
    seatTakeoverActAs($this, $token);

    $active = seatTakeoverProp(seatTakeoverFullLoad($this, $room), 'seatToken');

    $visit = seatTakeoverVisit($this, $room, $active);

    expect(seatTakeoverProp($visit, 'seatToken'))->toBe($active)
        ->and(seatTakeoverProp($visit, 'state.self.seatActive'))->toBeTrue();
});

it('seat.active résout le siège et la partie courante depuis les paramètres bruts, avant la liaison implicite', function (): void {
    // Ce que le limiteur — qui passe APRÈS seat.active et AVANT la liaison —
    // voit de la requête : le paramètre brut et le siège déjà résolu.
    $seen = [];

    RateLimiter::for('seat-active-probe', function (Request $request) use (&$seen): Limit {
        $route = $request->route();
        $parameter = $route instanceof Illuminate\Routing\Route
            ? ($route->hasParameter('player') ? $route->parameter('player') : ($route->hasParameter('room') ? $route->parameter('room') : null))
            : null;

        $seen[] = [
            'parameter' => get_debug_type($parameter),
            'seat' => EnsureActiveSeat::seat($request)?->public_id,
        ];

        return Limit::none();
    });

    $probe = static fn (Request $request, ?object $bound = null): JsonResponse => response()->json([
        'bound' => $bound === null ? null : $bound::class,
        'seat' => EnsureActiveSeat::seat($request)?->public_id,
        'game' => ($game = EnsureActiveSeat::game($request)) === null ? null : GameRef::for($game),
    ]);

    $declared = ['web', 'seat.active', 'throttle:seat-active-probe'];
    Route::middleware($declared)->post('/_test/seat-active/r/{room}', fn (Request $request, Room $room) => $probe($request, $room));
    Route::middleware($declared)->post('/_test/seat-active/p/{player}', fn (Request $request, Player $player) => $probe($request, $player));
    Route::middleware($declared)->post('/_test/seat-active/solo', fn (Request $request) => $probe($request));

    // La pile réellement exécutée : SetLocale, seat.active, le limiteur, PUIS
    // la liaison implicite (rang du § 10.2, 70 § 8). Le noyau HTTP inscrit
    // ses groupes et sa liste de priorité dans le routeur à sa construction.
    app(HttpKernel::class);
    $route = app('router')->getRoutes()->match(Request::create('/_test/seat-active/r/ABCDEF', 'POST'));
    $stack = app('router')->gatherRouteMiddleware($route);
    $at = static fn (string $middleware): int|false => array_search($middleware, $stack, true);

    expect($at(SetLocale::class))->toBeInt()
        ->and($at(SetLocale::class))->toBeLessThan($at(EnsureActiveSeat::class))
        ->and($at(EnsureActiveSeat::class))->toBeLessThan($at(ThrottleRequests::class.':seat-active-probe'))
        ->and($at(ThrottleRequests::class.':seat-active-probe'))->toBeLessThan($at(SubstituteBindings::class));

    // Un salon en partie : une partie close plus ancienne, une en cours.
    $room = Room::factory()->playing()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create(['room_id' => $room->id, 'player_token_hash' => $token->hash()]);
    Game::factory()->forRoom($room)->completed()->create(['started_at' => Date::now()->subHour()]);
    $current = Game::factory()->forRoom($room)->create(['started_at' => Date::now()->subMinute()]);
    seatTakeoverActAs($this, $token);
    $headers = [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token];

    // `{room}` brut, casse comprise : le code est normalisé comme à la liaison.
    $this->postJson('/_test/seat-active/r/'.strtolower($room->room_code), [], $headers)
        ->assertOk()
        ->assertExactJson(['bound' => Room::class, 'seat' => $seat->public_id, 'game' => GameRef::for($current)]);

    expect($seen[0])->toBe(['parameter' => 'string', 'seat' => $seat->public_id]);

    // `{player}` brut : le `public_id` désigne un siège que le jeton doit tenir.
    $this->postJson('/_test/seat-active/p/'.$seat->public_id, [], $headers)
        ->assertOk()
        ->assertExactJson(['bound' => Player::class, 'seat' => $seat->public_id, 'game' => GameRef::for($current)]);

    expect($seen[1])->toBe(['parameter' => 'string', 'seat' => $seat->public_id]);

    // Le `public_id` d'un autre siège ne donne aucun droit.
    $neighbour = Player::factory()->create(['room_id' => $room->id]);

    $this->postJson('/_test/seat-active/p/'.$neighbour->public_id, [], [EnsureActiveSeat::HEADER => (string) $neighbour->active_seat_token])
        ->assertForbidden();

    // Un salon revenu au lobby après une partie close (« Rejouer ») : siège
    // résolu, aucune partie courante — une partie close ne l'est jamais.
    $lobby = Room::factory()->create();
    $lobbySeat = Player::factory()->create(['room_id' => $lobby->id, 'player_token_hash' => $token->hash()]);
    Game::factory()->forRoom($lobby)->completed()->create();

    $this->postJson('/_test/seat-active/r/'.$lobby->room_code, [], [EnsureActiveSeat::HEADER => (string) $lobbySeat->active_seat_token])
        ->assertOk()
        ->assertJsonPath('seat', $lobbySeat->public_id)
        ->assertJsonPath('game', null);

    // Solo : le siège sans salon du jeton, et sa partie solo EN COURS, jamais
    // une partie close plus ancienne.
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);
    $closed = Game::factory()->solo()->completed()->create(['started_at' => Date::now()->subHour()]);
    $running = Game::factory()->solo()->create(['started_at' => Date::now()->subMinute()]);

    foreach ([$closed, $running] as $game) {
        GamePlayer::factory()->for($game)->frozenFrom($solo)->create(['status' => GamePlayerStatus::Playing]);
    }

    // Un siège solo parti du même jeton (état que le solo n'atteint jamais,
    // § 13.3) n'est pas repris, même plus récent.
    Player::factory()->solo()->left()->create(['player_token_hash' => $token->hash()]);

    $this->postJson('/_test/seat-active/solo', [], [EnsureActiveSeat::HEADER => (string) $solo->active_seat_token])
        ->assertOk()
        ->assertExactJson(['bound' => null, 'seat' => $solo->public_id, 'game' => GameRef::for($running)]);

    // Un siège expulsé, désigné par son `public_id` : aucun siège.
    $lobbySeat->forceFill(['kicked_at' => Date::now(), 'left_at' => Date::now()])->save();

    $this->postJson('/_test/seat-active/p/'.$lobbySeat->public_id, [], [EnsureActiveSeat::HEADER => (string) $lobbySeat->active_seat_token])
        ->assertForbidden();

    // Un salon archivé : aucun siège.
    $room->forceFill(['room_code_active' => null, 'archived_at' => Date::now()])->save();

    $this->postJson('/_test/seat-active/r/'.$room->room_code, [], $headers)->assertForbidden();
    $this->postJson('/_test/seat-active/p/'.$seat->public_id, [], $headers)->assertForbidden();
});
