<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Room\KickSeat;
use App\Enums\GamePlayerStatus;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Enums\ScoreScope;
use App\Events\Game\SeatKicked;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Room;
use App\Models\RoundPlayer;
use App\Support\Game\SeatViewPresenter;
use App\Support\I18n\LocaleCookie;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WirePayload;
use App\Support\Scoring\Scoreboard;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Expulsion — spec 50 § 11.3, D15 du 23/09, contrat C4 I4.9 (lot L50-6)
|--------------------------------------------------------------------------
|
| L'hôte retire un siège : `left`, `left_at = kicked_at` au même instant, la
| participation d'une partie en cours passe `kicked` sans perdre ses points,
| et le jeton expulsé est refusé dans ce salon jusqu'à l'archivage. Tout est
| relu sous le verrou du salon ; les diffusions et la réévaluation de la fin
| anticipée partent APRÈS la validation, la seconde dans sa propre
| transaction : l'expulsion ne tient jamais le verrou de manche.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-09-27 16:04:05.371');
    $this->travelTo($this->now);
});

/**
 * L'expulsion par l'action, comme la route l'appelle.
 */
function kickAct(Room $room, Player $host, Player $target): void
{
    app(KickSeat::class)->handle($room, $host, $target);
}

/**
 * Un salon au lobby, son hôte, et un invité tenu par son propre jeton.
 *
 * @return array{0: Room, 1: Player, 2: PlayerToken, 3: Player, 4: PlayerToken}
 */
function kickRoom(): array
{
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room);

    return [$room, $host, $hostToken, $guest, $guestToken];
}

it("refuse le jeton d'un siège expulsé dans le même salon jusqu'à l'archivage", function (): void {
    [$room, $host, $hostToken, $guest, $guestToken] = kickRoom();

    HostGestures::kick($this, $room, $hostToken, $host, $guest->public_id)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    LobbyWrites::actAs($this, $guestToken);

    // La page du salon ne rend plus rien au jeton expulsé : 303 vers l'entrée,
    // qui dit le refus, sans formulaire.
    $this->get(route('room.show', $room))->assertRedirect(route('room.entry', $room));
    $this->get(route('room.entry', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('room/join')->where('entry', 'kicked'));

    // La prise de siège refuse AVANT tout comptage : aucun siège neuf, aucun
    // jeton frappé, le message dans la langue de la requête.
    $seats = SeatEntry::rawSeats($room);

    foreach ([Locale::French, Locale::English] as $locale) {
        $response = $this->withUnencryptedCookie(LocaleCookie::NAME, $locale->value)
            ->from(route('room.entry', $room))
            ->post(route('room.join', $room), SeatEntry::form('Revenant'));

        $response->assertRedirect(route('room.entry', $room))
            ->assertSessionHasErrors(['room' => trans('room.join.kicked', [], $locale->value)]);

        expect(SeatEntry::tokenCookies($response))->toBe([]);
    }

    expect(SeatEntry::rawSeats($room))->toBe($seats);

    // Ni lecture de l'état, ni écriture sous `seat.active`, ni départ.
    $this->getJson(route('room.state', $room))->assertForbidden();
    LobbyWrites::send($this, 'POST', route('room.leave', $room), $room, [], $guest)->assertForbidden();

    // Le temps seul ne lève rien : 23 h plus tard, le salon vit encore.
    $this->travel(23)->hours();
    $this->get(route('room.show', $room))->assertRedirect(route('room.entry', $room));
    $this->get(route('room.entry', $room))
        ->assertInertia(fn (Assert $page) => $page->where('entry', 'kicked'));

    // L'archivage efface le hash du jeton (10 § 11.1) : le refus tombe de
    // lui-même, sans que `kicked_at` soit jamais remise à NULL.
    DB::transaction(function () use ($room): void {
        Room::query()->whereKey($room->id)->update([
            'status' => RoomStatus::Archived->value,
            'room_code_active' => null,
            'archived_at' => now(),
            'host_player_id' => null,
        ]);

        Player::query()->whereBelongsTo($room)->update([
            'nickname' => null,
            'nickname_normalized' => null,
            'player_token_hash' => null,
        ]);
    });

    expect(Player::query()->heldByToken($guestToken)->exists())->toBeFalse()
        ->and($guest->refresh()->wasKicked())->toBeTrue();

    $this->get(route('room.show', $room))->assertStatus(Response::HTTP_GONE);
});

it('laisse un jeton expulsé prendre un siège dans un autre salon', function (): void {
    [$room, $host, $hostToken, $guest, $guestToken] = kickRoom();

    HostGestures::kick($this, $room, $hostToken, $host, $guest->public_id)
        ->assertStatus(Response::HTTP_SEE_OTHER);

    [$elsewhere] = HostGestures::room();

    LobbyWrites::actAs($this, $guestToken);

    $this->from(route('room.entry', $elsewhere))
        ->post(route('room.join', $elsewhere), SeatEntry::form($guest->nickname ?? 'Revenant'))
        ->assertRedirect(route('room.show', $elsewhere))
        ->assertSessionHasNoErrors();

    // Le même jeton, le même pseudo : un siège neuf, ailleurs, connecté.
    $seat = SeatEntry::seatOf($elsewhere, $guestToken);

    expect($seat)->not->toBeNull()
        ->and($seat?->wasKicked())->toBeFalse()
        ->and($seat?->connection_state)->toBe(PlayerConnectionState::Connected);

    $this->get(route('room.show', $elsewhere))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('game/lobby')->where('state.self.publicId', $seat?->public_id));

    // Le refus du premier salon, lui, tient toujours.
    $this->get(route('room.show', $room))->assertRedirect(route('room.entry', $room));
});

it('passe le siège expulsé en parti avec left_at et kicked_at au même instant', function (): void {
    [$room, $host, , $guest] = kickRoom();
    [$disconnected] = HostGestures::seat($room, attributes: [
        'connection_state' => PlayerConnectionState::Disconnected,
        'disconnected_at' => $this->now->subSeconds(40),
    ]);
    [$left] = HostGestures::seat($room, attributes: [
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => $this->now->subMinutes(3),
    ]);
    Room::query()->whereKey($room->id)->update(['last_activity_at' => $this->now->subHour()]);
    $holding = Player::query()->whereBelongsTo($room)->holdingSeat()->count();
    $before = HostGestures::raw('player', $guest->id);

    kickAct($room, $host, $guest);

    $kicked = $guest->refresh();
    $kickedAt = $kicked->kicked_at;

    // Le même instant serveur, à la milliseconde, pris sous le verrou.
    expect($kicked->connection_state)->toBe(PlayerConnectionState::Left)
        ->and($kicked->wasKicked())->toBeTrue()
        ->and(HostGestures::ms($kickedAt))->toBe('2026-09-27 16:04:05.371')
        ->and(HostGestures::ms($kicked->left_at))->toBe(HostGestures::ms($kickedAt))
        ->and(DB::table('player')->where('id', $guest->id)->value('kicked_at'))
        ->toBe(DB::table('player')->where('id', $guest->id)->value('left_at'));

    // Rien d'autre du siège ne change : pseudo réservé, jeton, onglet.
    $after = HostGestures::raw('player', $guest->id);

    expect(array_diff_key($after, array_flip(['connection_state', 'left_at', 'kicked_at', 'updated_at'])))
        ->toBe(array_diff_key($before, array_flip(['connection_state', 'left_at', 'kicked_at', 'updated_at'])));

    // Le siège libère sa place, et le geste est une source d'activité.
    expect(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBe($holding - 1)
        ->and($room->refresh()->last_activity_at?->equalTo($this->now->startOfSecond()))->toBeTrue();

    // Un siège déconnecté, ou déjà parti, s'expulse aussi : son `left_at`
    // devient l'instant de l'expulsion, identique à `kicked_at`.
    $this->travel(2)->seconds();
    kickAct($room, $host, $disconnected);
    kickAct($room, $host, $left);

    foreach ([$disconnected->refresh(), $left->refresh()] as $seat) {
        expect($seat->connection_state)->toBe(PlayerConnectionState::Left)
            ->and(HostGestures::ms($seat->kicked_at))->toBe('2026-09-27 16:04:07.371')
            ->and(HostGestures::ms($seat->left_at))->toBe(HostGestures::ms($seat->kicked_at));
    }

    // La vue du salon les dit retirés, et l'effectif présent les exclut.
    $seats = collect(SeatViewPresenter::lobbySeats($room->refresh()))->keyBy('publicId');

    expect($seats[$guest->public_id]['kicked'])->toBeTrue()
        ->and($seats[$guest->public_id]['connection'])->toBe('left')
        ->and($seats[$host->public_id]['kicked'])->toBeFalse();
});

it("conserve les points et marque kicked la participation d'une partie en cours", function (): void {
    [$room, $host, , $guest] = kickRoom();
    [$game, $round] = HostGestures::runningGame($room, [$host, $guest]);

    // L'invité a trouvé au palier 1 : sa bonne réponse et ses points.
    $guess = Guess::factory()->forRound($round, $guest)->create(['received_at' => Date::now()]);
    RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $guest->id)->update([
        'input_state' => RoundPlayerInputState::Locked->value,
        'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now()),
    ]);
    $guessRow = HostGestures::raw('guess', $guess->id);
    $score = Scoreboard::seatScore($game, $guest)['ownScore'];
    $participation = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $guest->id)->sole();
    $hostParticipation = HostGestures::raw('game_player', GamePlayer::query()->whereBelongsTo($game)->where('player_id', $host->id)->sole()->id);

    expect($score)->toBeGreaterThan(0);

    kickAct($room, $host, $guest);

    // L'issue figée : `kicked`, par mise à jour ciblée — aucun agrégat écrit,
    // aucune autre participation touchée.
    $participation->refresh();

    expect($participation->status)->toBe(GamePlayerStatus::Kicked)
        ->and($participation->final_score)->toBeNull()
        ->and($participation->rounds_played)->toBeNull()
        ->and(HostGestures::raw('game_player', GamePlayer::query()->whereBelongsTo($game)->where('player_id', $host->id)->sole()->id))
        ->toBe($hostParticipation);

    // Les points restent : la bonne réponse est intacte, le score et le
    // classement la comptent toujours, expulsé compris.
    $tally = collect(Scoreboard::tallies($game, ScoreScope::Own))->firstWhere('publicId', $guest->public_id);

    expect(HostGestures::raw('guess', $guess->id))->toBe($guessRow)
        ->and(Scoreboard::seatScore($game, $guest)['ownScore'])->toBe($score)
        ->and($tally?->status)->toBe(GamePlayerStatus::Kicked)
        ->and($tally?->score)->toBe($score)
        ->and($tally?->correctAnswers)->toBe(1);

    // La manche continue : l'expulsion ne touche ni son horloge ni ses paliers.
    expect($round->refresh()->ended_at)->toBeNull()
        ->and($game->refresh()->ended_at)->toBeNull();

    // Au lobby, sans partie : aucune participation n'existe, rien à marquer.
    [$lobby, $lobbyHost, , $lobbyGuest] = kickRoom();

    kickAct($lobby, $lobbyHost, $lobbyGuest);

    expect(GamePlayer::query()->where('player_id', $lobbyGuest->id)->exists())->toBeFalse()
        ->and($lobbyGuest->refresh()->wasKicked())->toBeTrue();
});

it("refuse à l'hôte de s'expulser lui-même", function (): void {
    [$room, $host, $hostToken] = kickRoom();
    $recorder = RecordingBroadcaster::install();
    $before = HostGestures::raw('player', $host->id);
    $roomBefore = HostGestures::raw('room', $room->id);

    foreach ([Locale::French, Locale::English] as $locale) {
        // Retour à la page du salon avec l'erreur `room`, traduite — jamais un
        // 422 brut, jamais l'accueil, même sans en-tête `Referer`.
        HostGestures::kick($this->withUnencryptedCookie(LocaleCookie::NAME, $locale->value), $room, $hostToken, $host, $host->public_id)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors(['room' => trans('room.lobby.cannot_kick_self', [], $locale->value)]);
    }

    LobbyWrites::actAs($this, $hostToken);
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        EnsureActiveSeat::HEADER => (string) $host->active_seat_token,
    ])->post(route('room.players.kick', ['room' => $room, 'target' => $host->public_id]))
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasErrors('room');

    // L'action elle-même refuse, sous le champ `room`.
    expect(fn () => kickAct($room, $host, $host))->toThrow(ValidationException::class);

    try {
        kickAct($room, $host, $host);
    } catch (ValidationException $refusal) {
        expect(array_keys($refusal->errors()))->toBe(['room']);
    }

    expect(HostGestures::raw('player', $host->id))->toBe($before)
        ->and(HostGestures::raw('room', $room->id))->toBe($roomBefore)
        ->and($recorder->sent)->toBe([]);
});

it("ne fait rien sur un second geste d'expulsion", function (): void {
    [$room, $host, $hostToken, $guest] = kickRoom();
    [$game] = HostGestures::runningGame($room, [$host, $guest]);
    $stale = Player::query()->findOrFail($guest->id);

    kickAct($room, $host, $guest);

    $firstKick = HostGestures::raw('player', $guest->id);
    $roomAfterFirst = HostGestures::raw('room', $room->id);
    $participation = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $guest->id)->sole();

    // Une réécriture de l'issue qui viendrait d'ailleurs ne doit pas être
    // écrasée par un second geste.
    GamePlayer::query()->whereKey($participation->id)->update(['status' => GamePlayerStatus::Left->value]);
    $participationRow = HostGestures::raw('game_player', $participation->id);

    $recorder = RecordingBroadcaster::install();
    $this->travel(5)->seconds();

    // L'instance reçue est antérieure au premier geste : l'état d'expulsion
    // est relu sur la ligne verrouillée, jamais sur elle.
    expect($stale->kicked_at)->toBeNull();

    kickAct($room, $host, $stale);
    HostGestures::kick($this, $room, $hostToken, $host, $guest->public_id)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    expect(HostGestures::raw('player', $guest->id))->toBe($firstKick)
        ->and(HostGestures::raw('room', $room->id))->toBe($roomAfterFirst)
        ->and(HostGestures::raw('game_player', $participation->id))->toBe($participationRow)
        ->and($recorder->sent)->toBe([]);
});

it('émet seat.updated au salon et seat.kicked au seul siège expulsé, après validation', function (): void {
    [$room, $host, , $guest] = kickRoom();
    [$other] = HostGestures::seat($room);
    $recorder = RecordingBroadcaster::install();

    // Transaction de l'appelant annulée : rien n'est écrit, rien ne part.
    DB::beginTransaction();
    kickAct($room, $host, $guest);
    expect($recorder->sent)->toBe([]);
    DB::rollBack();

    expect($guest->refresh()->wasKicked())->toBeFalse()
        ->and($recorder->attempts)->toBe(0);

    // Validée : rien avant le COMMIT, les deux messages après.
    DB::transaction(function () use ($room, $host, $guest, $recorder): void {
        kickAct($room, $host, $guest);

        expect($recorder->sent)->toBe([]);
    });

    expect(array_column($recorder->sent, 'event'))->toBe(['seat.updated', 'seat.kicked']);

    [$updated, $kicked] = $recorder->sent;

    // Au salon : le siège, retiré, dans sa vue du lobby.
    expect($updated['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_keys($updated['payload']))->toBe(['v', 'serverNow', 'gameRef', 'seat'])
        ->and($updated['payload']['gameRef'])->toBeNull()
        ->and($updated['payload']['seat'])->toBe(json_decode(json_encode(
            SeatViewPresenter::lobby($guest->refresh(), $host->id),
            JSON_THROW_ON_ERROR,
        ), true))
        ->and($updated['payload']['seat']['kicked'])->toBeTrue()
        ->and($updated['payload']['seat']['connection'])->toBe('left')
        ->and($updated['payload']['seat']['isHost'])->toBeFalse();

    // Au seul siège expulsé : son canal privé, charge vide hors enveloppe.
    expect($kicked['channels'])->toBe(['private-'.ChannelNames::seat($guest)])
        ->and(array_keys($kicked['payload']))->toBe(['v', 'serverNow', 'gameRef'])
        ->and($kicked['channels'])->not->toContain('private-'.ChannelNames::seat($other))
        ->and($kicked['channels'])->not->toContain('private-'.ChannelNames::seat($host));

    foreach ($recorder->sent as $sent) {
        WirePayload::assertSafe($sent['payload'], $sent['event']);
        expect($sent['json'])->not->toContain('kicked_at')
            ->and($sent['json'])->not->toContain((string) $guest->player_token_hash);
    }

    // En partie : la vue du siège est celle de sa participation, identité
    // gelée, et l'enveloppe porte la partie.
    [$playing, $playingHost, , $player] = kickRoom();
    [$game] = HostGestures::runningGame($playing, [$playingHost, $player]);
    Player::query()->whereKey($player->id)->update(['nickname' => 'Renommé', 'nickname_normalized' => 'renomme']);
    $recorder->sent = [];

    kickAct($playing, $playingHost, $player->refresh());

    $inGame = collect($recorder->sent)->firstWhere('event', 'seat.updated');

    expect($inGame['payload']['gameRef'])->not->toBeNull()
        ->and($inGame['payload']['seat']['nickname'])->not->toBe('Renommé')
        ->and($inGame['payload']['seat']['kicked'])->toBeTrue()
        ->and(collect($recorder->sent)->firstWhere('event', 'seat.kicked')['payload']['gameRef'])
        ->toBe($inGame['payload']['gameRef'])
        ->and($game->refresh()->ended_at)->toBeNull();
});

it("expulse un siège tiers sous seat.active sans exiger que le jeton de l'hôte tienne la cible", function (): void {
    // La route : `{target}`, jamais `{player}` — que `seat.active` lirait
    // comme le siège du demandeur —, sous `seat.active` puis `game-write`,
    // avant la liaison du salon.
    app(HttpKernel::class);
    $router = app('router');
    $route = $router->getRoutes()->getByName('room.players.kick');

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('r/{room}/players/{target}/kick')
        ->and($route?->methods())->toContain('POST')
        ->and($route?->parameterNames())->toBe(['room', 'target'])
        ->and($route?->gatherMiddleware())->toContain('web', 'seat.active', 'throttle:game-write');

    if ($route !== null) {
        $stack = $router->gatherRouteMiddleware($route);
        $at = static fn (string $middleware): int|false => array_search($middleware, $stack, true);

        expect($at(EnsureActiveSeat::class))->toBeLessThan($at(ThrottleRequests::class.':game-write'))
            ->and($at(ThrottleRequests::class.':game-write'))->toBeLessThan($at(SubstituteBindings::class));
    }

    [$room, $host, $hostToken, $guest, $guestToken] = kickRoom();
    [$third] = HostGestures::seat($room);

    // Le jeton de l'hôte ne tient pas la cible : c'est le salon qui résout
    // le demandeur, et la cible se désigne par son seul `public_id`.
    expect($guest->player_token_hash)->not->toBe($hostToken->hash());

    // Un non-hôte, même tenant son propre siège : 403, rien d'écrit.
    HostGestures::kick($this, $room, $guestToken, $guest, $third->public_id)->assertForbidden();
    // Un onglet supplanté de l'hôte : 409 en données.
    LobbyWrites::actAs($this, $hostToken);
    LobbyWrites::send($this, 'POST', route('room.players.kick', ['room' => $room, 'target' => $third->public_id]), $room, [], (string) Str::ulid())
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => EnsureActiveSeat::SUPERSEDED]);
    // Un visiteur sans siège : 403.
    HostGestures::kick($this, $room, PlayerToken::mint(Locale::French), $host, $third->public_id)->assertForbidden();

    // Une cible hors de ce salon, ou inconnue : 404.
    [$elsewhere] = HostGestures::room();
    [$foreign] = HostGestures::seat($elsewhere);
    HostGestures::kick($this, $room, $hostToken, $host, $foreign->public_id)->assertNotFound();
    HostGestures::kick($this, $room, $hostToken, $host, 'ZZZZZZZZZZZZ')->assertNotFound();

    expect($third->refresh()->wasKicked())->toBeFalse()
        ->and($foreign->refresh()->wasKicked())->toBeFalse();

    // L'hôte : retour au salon, cible retirée.
    HostGestures::kick($this, $room, $hostToken, $host, $third->public_id)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    expect($third->refresh()->wasKicked())->toBeTrue()
        ->and($host->refresh()->wasKicked())->toBeFalse()
        ->and($guest->refresh()->wasKicked())->toBeFalse();

    // Sans `Referer` : la page du salon, jamais l'accueil.
    LobbyWrites::actAs($this, $hostToken);
    $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        EnsureActiveSeat::HEADER => (string) $host->active_seat_token,
    ])->post(route('room.players.kick', ['room' => $room, 'target' => $guest->public_id]))
        ->assertRedirect(route('room.show', $room));

    expect($guest->refresh()->wasKicked())->toBeTrue();

    // L'autorité est relue sous le verrou : un hôte déchu entre-temps est
    // refusé par l'action elle-même.
    [$third2] = HostGestures::seat($room);
    Room::query()->whereKey($room->id)->update(['host_player_id' => $third2->id]);

    expect(fn () => kickAct($room, $host, $third2))->toThrow(AuthorizationException::class)
        ->and($third2->refresh()->wasKicked())->toBeFalse();
});

it('ne réécrit jamais une participation figée par un gel concurrent', function (): void {
    [$room, $host, , $guest] = kickRoom();
    [$game, $round] = HostGestures::runningGame($room, [$host, $guest]);
    $participation = GamePlayer::query()->whereBelongsTo($game)->where('player_id', $guest->id)->sole();
    $endedAt = EngineFixtures::durationEnd($round);
    $baseLevel = DB::transactionLevel();
    $frozen = false;

    // Le gel se valide PENDANT l'expulsion, après le verrou du salon et
    // l'écriture du siège, avant la relecture de la partie : c'est cette
    // relecture, sous le verrou de `game`, qui doit le voir.
    DB::beforeExecuting(function (string $query) use (&$frozen, $baseLevel, $game, $endedAt): void {
        if ($frozen || DB::transactionLevel() <= $baseLevel || ! str_starts_with($query, 'update "player"')) {
            return;
        }

        $frozen = true;
        app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, $endedAt);
    });

    kickAct($room, $host, $guest);

    expect($frozen)->toBeTrue()
        ->and($game->refresh()->ended_at)->not->toBeNull()
        ->and($guest->refresh()->wasKicked())->toBeTrue();

    // L'issue figée par le gel n'est jamais réécrite après coup.
    $participation->refresh();

    expect($participation->status)->toBe(GamePlayerStatus::Playing)
        ->and($participation->final_score)->not->toBeNull();

    // Partie déjà figée avant le geste (podium affiché) : aucune écriture sur
    // `game_player`, le siège est retiré quand même.
    [$podium, $podiumHost, , $podiumGuest] = kickRoom();
    [$ended, $endedRound] = HostGestures::runningGame($podium, [$podiumHost, $podiumGuest]);
    app(FinalizeGame::class)->handle($ended, GameStatus::Interrupted, EngineFixtures::durationEnd($endedRound));
    $endedParticipation = GamePlayer::query()->whereBelongsTo($ended)->where('player_id', $podiumGuest->id)->sole();
    $row = HostGestures::raw('game_player', $endedParticipation->id);

    kickAct($podium, $podiumHost, $podiumGuest);

    expect(HostGestures::raw('game_player', $endedParticipation->id))->toBe($row)
        ->and($podiumGuest->refresh()->wasKicked())->toBeTrue();
});

it("réévalue la fin anticipée de la manche en cours après validation, sans tenir le verrou de manche dans la transaction d'expulsion", function (): void {
    [$room, $host, , $guest] = kickRoom();
    [$game, $round] = HostGestures::runningGame($room, [$host, $guest]);

    // L'hôte a trouvé : sa saisie est close. L'invité, encore ouvert, est le
    // seul qui retient la manche.
    RoundPlayer::query()->where('round_id', $round->id)->where('player_id', $host->id)->update([
        'input_state' => RoundPlayerInputState::Locked->value,
        'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now()),
    ]);

    $kickAt = Date::now()->toImmutable()->addMilliseconds(2_431);
    $this->travelTo($kickAt);

    // Le moteur réévalue avec l'instant ÉCRIT du départ, jamais l'heure
    // d'exécution : l'horloge avance entre la validation et la réévaluation.
    Event::listen(SeatKicked::class, fn () => $this->travelTo($kickAt->addMilliseconds(1_500)));

    // Trace : requêtes et transactions, dans l'ordre.
    $trace = [];
    Event::listen(TransactionBeginning::class, function () use (&$trace): void {
        $trace[] = ['begin', DB::transactionLevel()];
    });
    Event::listen(TransactionCommitted::class, function () use (&$trace): void {
        $trace[] = ['commit', DB::transactionLevel()];
    });
    DB::listen(function (QueryExecuted $query) use (&$trace): void {
        $trace[] = ['query', $query->connection->transactionLevel(), $query->sql];
    });

    kickAct($room, $host, $guest);

    // La manche s'est close, à l'instant écrit de l'expulsion.
    $kickedAt = $guest->refresh()->kicked_at;

    expect(HostGestures::ms($kickedAt))->toBe(HostGestures::ms($kickAt))
        ->and(HostGestures::ms($round->refresh()->ended_at))->toBe(HostGestures::ms($kickedAt));

    // La transaction qui écrit le siège ne lit ni n'écrit jamais `round` ; la
    // manche est reprise dans une transaction ouverte APRÈS sa validation.
    $base = collect($trace)->first(fn (array $entry): bool => $entry[0] === 'begin')[1] - 1;
    $segments = [];
    $current = null;

    foreach ($trace as $entry) {
        if ($entry[0] === 'begin' && $entry[1] === $base + 1) {
            $current = count($segments);
            $segments[$current] = [];
        } elseif ($entry[0] === 'query' && $current !== null && $entry[1] > $base) {
            $segments[$current][] = $entry[2];
        } elseif ($entry[0] === 'commit' && $entry[1] === $base) {
            $current = null;
        }
    }

    $touchesRound = static fn (string $sql): bool => (bool) preg_match('/(from|update|into) "round"/', $sql);
    $kickSegment = collect($segments)->search(
        static fn (array $queries): bool => collect($queries)->contains(static fn (string $sql): bool => str_starts_with($sql, 'update "player"')),
    );

    expect($kickSegment)->toBeInt()
        ->and(collect($segments[$kickSegment])->contains($touchesRound))->toBeFalse();

    $later = collect($segments)->filter(static fn (array $queries, int $index): bool => $index > $kickSegment);

    expect($later->contains(static fn (array $queries): bool => collect($queries)->contains(
        static fn (string $sql): bool => str_starts_with($sql, 'select * from "round" where "round"."id" = ?'),
    )))->toBeTrue()
        ->and($later->contains(static fn (array $queries): bool => collect($queries)->contains(
            static fn (string $sql): bool => str_starts_with($sql, 'update "round"'),
        )))->toBeTrue();

    // Transaction annulée : aucune réévaluation.
    [$other, $otherHost, , $otherGuest] = kickRoom();
    [, $otherRound] = HostGestures::runningGame($other, [$otherHost, $otherGuest]);
    RoundPlayer::query()->where('round_id', $otherRound->id)->where('player_id', $otherHost->id)->update([
        'input_state' => RoundPlayerInputState::Locked->value,
        'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now()),
    ]);

    DB::beginTransaction();
    kickAct($other, $otherHost, $otherGuest);
    DB::rollBack();

    expect($otherRound->refresh()->ended_at)->toBeNull()
        ->and($otherGuest->refresh()->wasKicked())->toBeFalse();

    // Un siège sans ligne dans la manche en cours (entré pendant la partie) :
    // rien à réévaluer, la manche continue.
    [$late] = HostGestures::seat($other);
    kickAct($other, $otherHost, $late);

    expect($otherRound->refresh()->ended_at)->toBeNull()
        ->and($otherRound->status)->toBe(RoundStatus::Running)
        ->and($late->refresh()->wasKicked())->toBeTrue()
        ->and(Game::query()->where('room_id', $other->id)->value('ended_at'))->toBeNull();
});
