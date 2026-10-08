<?php

use App\Actions\Identity\ClaimSeatForAccount;
use App\Actions\Room\ReplayRoom;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Events\Game\SeatUpdated;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Account\PlayHistoryCache;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Rattachement automatique d'un siège invité au compte — spec 40 § 13.2
|--------------------------------------------------------------------------
|
| D66 du 07/10 (n° 23, n° 24, n° 25) : quand une page de siège (`room.show`,
| `solo.show`) est rendue pour un compte connecté, le siège de la page tenu
| par le `player_token`, encore sans compte, lui est rattaché sans
| confirmation — ce seul siège, jamais les autres sièges du jeton ; jamais
| dans un écouteur de connexion (I4.6). Pseudo et identité gelée ne bougent
| jamais ; l'avatar bascule sur l'image du compte au lobby, ou au retour au
| lobby après une partie.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Storage::fake(UploadedAvatars::DISK);

    $this->travelTo(CarbonImmutable::parse('2026-10-07 18:30:00.250'));
});

/**
 * Un salon au lobby dont l'hôte est le siège invité d'un jeton neuf.
 *
 * @return array{0: Room, 1: Player, 2: PlayerToken}
 */
function seatClaimRoom(): array
{
    $token = PlayerToken::mint(Locale::French);
    [$room, $seat] = LobbyWrites::hostedRoom($token);

    return [$room, $seat->refresh(), $token];
}

/**
 * Le message flash d'une réponse Inertia, où qu'Inertia le range.
 *
 * @param  TestResponse<Response>  $response
 */
function seatClaimFlash(TestResponse $response): mixed
{
    $page = $response->inertiaPage();

    return data_get($page, 'flash.game_notice.message', data_get($page, 'props.flash.game_notice.message'));
}

it('rattache au compte connecté le siège invité de la page, sans second siège', function (): void {
    [$room, $seat, $token] = seatClaimRoom();
    $user = User::factory()->locale(Locale::English)->create();
    Player::query()->whereKey($seat->id)->update(['locale' => Locale::French->value]);
    $seat->refresh();
    Cache::put(PlayHistoryCache::key($user->id), ['stale' => true], 600);

    expect($seat->user_id)->toBeNull()
        ->and($seat->locale)->toBe(Locale::French);

    $nickname = $seat->nickname;

    LobbyWrites::actAs($this, $token);
    $first = $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    $seat->refresh();

    expect($seat->user_id)->toBe($user->id)
        ->and($seat->locale)->toBe(Locale::English)
        ->and($seat->nickname)->toBe($nickname)
        ->and(Player::query()->count())->toBe(1)
        ->and(SeatEntry::tokenCookies($first))->toBe([])
        ->and(seatClaimFlash($first))->toBe(__('room.seat.claimed', [], 'en'))
        ->and(Cache::has(PlayHistoryCache::key($user->id)))->toBeFalse();

    // L'avis ne revient pas : plus aucun siège à lier.
    $second = $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    expect(seatClaimFlash($second))->toBeNull()
        ->and(Player::query()->count())->toBe(1);
});

it('ne rattache jamais un siège dans un écouteur de connexion', function (): void {
    [$room, $seat, $token] = seatClaimRoom();
    $user = User::factory()->create();

    LobbyWrites::actAs($this, $token);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->assertAuthenticatedAs($user);

    // La connexion ne lit ni n'écrit le jeton : le siège reste invité.
    expect($seat->refresh()->user_id)->toBeNull();

    // La page de siège suivante le rattache, sans nouveau siège.
    $this->get(route('room.show', $room))->assertOk();

    expect($seat->refresh()->user_id)->toBe($user->id)
        ->and(Player::query()->count())->toBe(1);
});

it("ne rattache qu'un siège, celui de la page, jamais les autres sièges du jeton", function (): void {
    [$room, $seat, $token] = seatClaimRoom();
    [$other] = LobbyWrites::hostedRoom($token);
    $otherSeat = SeatEntry::seatOf($other, $token);
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);
    $user = User::factory()->create();

    LobbyWrites::actAs($this, $token);
    $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    expect($seat->refresh()->user_id)->toBe($user->id)
        ->and($otherSeat?->refresh()->user_id)->toBeNull()
        ->and($solo->refresh()->user_id)->toBeNull();
});

it("ne rattache ni un siège d'un salon archivé, ni un siège expulsé, ni un siège déjà lié à un autre compte", function (): void {
    $claim = app(ClaimSeatForAccount::class);
    $user = User::factory()->create();

    // Salon archivé : la page répond 410, et l'action ne lie rien, même si
    // le hash du jeton n'était pas encore effacé.
    $archived = Room::factory()->archived()->create();
    $archivedSeat = Player::factory()->for($archived)->create();
    expect($claim->handle($archivedSeat, $user))->toBeFalse()
        ->and($archivedSeat->refresh()->user_id)->toBeNull();

    // Siège dont le hash a été effacé (archivage, siège solo effacé).
    $erased = Player::factory()->solo()->create(['player_token_hash' => null]);
    expect($claim->handle($erased, $user))->toBeFalse();

    // Siège expulsé : la page renvoie à l'entrée, l'action ne lie rien.
    [$room, , $token] = seatClaimRoom();
    $kicked = Player::factory()->for($room)->kicked()->create();
    expect($claim->handle($kicked, $user))->toBeFalse()
        ->and($kicked->refresh()->user_id)->toBeNull();

    // Siège déjà lié à un autre compte : jamais écrasé.
    $owner = User::factory()->create();
    Player::query()->where('player_token_hash', $token->hash())->update(['user_id' => $owner->id]);

    LobbyWrites::actAs($this, $token);
    $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    expect(SeatEntry::seatOf($room, $token)?->user_id)->toBe($owner->id);

    // Compte anonymisé : rien.
    $anonymized = User::factory()->anonymized()->create();
    $fresh = Player::factory()->for($room)->create();
    expect($claim->handle($fresh, $anonymized))->toBeFalse();
});

it('ne rattache pas un second siège du même compte dans un salon', function (): void {
    [$room, $seat, $token] = seatClaimRoom();
    $user = User::factory()->create();
    Player::factory()->for($room)->forUser($user)->create();

    LobbyWrites::actAs($this, $token);
    $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    expect($seat->refresh()->user_id)->toBeNull()
        ->and(Player::query()->where('user_id', $user->id)->count())->toBe(1);
});

it("n'ouvre aucune transaction pour un siège que le compte ne peut pas rattacher", function (): void {
    [$room, $seat] = seatClaimRoom();
    $user = User::factory()->create();
    Player::factory()->for($room)->forUser($user)->create();

    // Le second siège d'un compte déjà assis, ou un siège expulsé, échoue
    // pour de bon : aucun verrou de salon pris à chaque rendu.
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
        $transactions++;
    });

    expect(app(ClaimSeatForAccount::class)->handle($seat, $user))->toBeFalse();

    $kicked = Player::factory()->for($room)->create(['kicked_at' => now()]);

    expect(app(ClaimSeatForAccount::class)->handle($kicked->refresh(), User::factory()->create()))->toBeFalse()
        ->and($transactions)->toBe(0)
        ->and($seat->refresh()->user_id)->toBeNull()
        ->and($kicked->refresh()->user_id)->toBeNull();
});

it("laisse intacts le pseudo et l'identité gelée de la partie en cours", function (): void {
    $user = User::factory()->withUploadedAvatar()->create();
    $room = Room::factory()->playing()->create();
    $seat = Player::factory()->for($room)->create();
    $game = Game::factory()->forRoom($room)->create();
    $participation = GamePlayer::factory()->for($game)->frozenFrom($seat)->create();
    $frozen = $participation->only(['display_nickname', 'display_avatar_kind', 'display_avatar_preset']);
    $before = $seat->only(['nickname', 'nickname_normalized', 'avatar_kind', 'avatar_preset']);

    expect(app(ClaimSeatForAccount::class)->handle($seat, $user))->toBeTrue();

    $seat->refresh();

    expect($seat->user_id)->toBe($user->id)
        ->and($seat->only(['nickname', 'nickname_normalized', 'avatar_kind', 'avatar_preset']))->toBe($before)
        ->and($participation->refresh()->only(['display_nickname', 'display_avatar_kind', 'display_avatar_preset']))->toBe($frozen)
        // La bascule attend le retour au lobby.
        ->and(Cache::has(ClaimSeatForAccount::pendingKey($seat->id)))->toBeTrue();
});

it("bascule l'avatar sur l'image du compte au lobby, et au retour au lobby après une partie", function (): void {
    Event::fake([SeatUpdated::class]);

    // Au lobby : tout de suite, dans la même requête, `seat.updated` diffusé.
    [$room, $seat, $token] = seatClaimRoom();
    $user = User::factory()->withUploadedAvatar()->create();

    LobbyWrites::actAs($this, $token);
    $this->actingAs($user)->get(route('room.show', $room))->assertOk();

    expect($seat->refresh()->avatar_kind)->toBe(AvatarKind::Upload)
        ->and($seat->avatar_preset)->not->toBeNull();
    Event::assertDispatchedTimes(SeatUpdated::class, 1);

    // En partie : aucune bascule, puis « Rejouer » l'applique.
    $second = User::factory()->withUploadedAvatar()->create();
    $playingToken = PlayerToken::mint(Locale::French);
    [$playing, $host] = LobbyWrites::hostedRoom($playingToken);
    Room::query()->whereKey($playing->id)->update(['status' => RoomStatus::Playing->value, 'launched_at' => now()]);
    Game::factory()->forRoom($playing)->completed()->create(['started_at' => now()->subMinutes(5)]);
    $presetBefore = $host->refresh()->avatar_kind;

    expect(app(ClaimSeatForAccount::class)->handle($host, $second))->toBeTrue()
        ->and($host->refresh()->avatar_kind)->toBe($presetBefore);

    expect(app(ReplayRoom::class)->handle($playing->refresh(), $host))->toBeNull();

    expect($host->refresh()->avatar_kind)->toBe(AvatarKind::Upload)
        ->and(Cache::has(ClaimSeatForAccount::pendingKey($host->id)))->toBeFalse();
    Event::assertDispatchedTimes(SeatUpdated::class, 2);
});

it('rattache un siège solo et sa partie apparaît dans l\'historique', function (): void {
    $token = PlayerToken::mint(Locale::French);
    $solo = Player::factory()->solo()->create(['player_token_hash' => $token->hash()]);
    $avatar = $solo->only(['avatar_kind', 'avatar_preset']);
    $user = User::factory()->withUploadedAvatar()->create();

    LobbyWrites::actAs($this, $token);
    $this->actingAs($user)->get(route('solo.show'))->assertOk();

    $solo->refresh();

    // Solo : rattaché, sans bascule d'avatar (D55 : aucun choix en solo).
    expect($solo->user_id)->toBe($user->id)
        ->and($solo->only(['avatar_kind', 'avatar_preset']))->toBe($avatar)
        ->and(Cache::has(ClaimSeatForAccount::pendingKey($solo->id)))->toBeFalse();

    // Sa partie se lit par le seul lien joueur-compte (`player_user_idx`).
    $game = Game::factory()->solo()->completed()->create();
    GamePlayer::factory()->for($game)->frozenFrom($solo)->create();

    $games = GamePlayer::query()
        ->whereHas('player', static fn ($query) => $query->where('user_id', $user->id))
        ->pluck('game_id')
        ->all();

    expect($games)->toBe([$game->id]);
});
