<?php

use App\Actions\Game\FinalizeGame;
use App\Actions\Room\ReplayRoom;
use App\Avatars\AvatarPresetCatalog;
use App\Avatars\SeatAvatar;
use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\GameStatus;
use App\Enums\Locale;
use App\Enums\RoomStatus;
use App\Http\Middleware\EnsureActiveSeat;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Support\Game\SeatViewPresenter;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WirePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\HostGestures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Changement d'avatar en salle d'attente — D55 du 02/10 (spec 50 § 8.1,
| spec 40 § 11.4)
|--------------------------------------------------------------------------
|
| `room.avatar.update`, sous `seat.active` et `throttle:game-write` : le
| siège du demandeur change d'avatar au lobby seulement — avant le
| lancement et après « Rejouer », jamais en partie ni au podium. Une clé
| tenue par un autre siège tenu est refusée sous le verrou du salon,
| prédéfini de repli d'un siège à image de compte compris ; « Mon avatar »
| n'est offert qu'à un compte à image visible. Après la validation :
| `seat.updated` au salon et re-signature du jeton.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);

    $this->now = CarbonImmutable::parse('2026-10-02 10:15:20.500');
    $this->travelTo($this->now);
});

/**
 * Le changement par la route, depuis la page du salon, au nom du jeton
 * donné et de l'onglet actif du siège (ou de l'onglet donné).
 *
 * @return TestResponse<Response>
 */
function seatAvatarPost(TestCase $test, Room $room, PlayerToken $token, Player $seat, string $avatar, Player|string|null $tab = null): TestResponse
{
    LobbyWrites::actAs($test, $token);
    $test->flushSession();

    return LobbyWrites::send($test, 'POST', route('room.avatar.update', $room), $room, ['avatar' => $avatar], $tab ?? $seat);
}

/** Le message traduit d'une clé, dans l'une des langues activées. */
function seatAvatarMessages(string $key): array
{
    return array_map(static fn (Locale $locale): string => trans($key, [], $locale->value), Locale::cases());
}

/** L'erreur `avatar` de la session, ou `null`. */
function seatAvatarError(): ?string
{
    $errors = session('errors');

    return $errors?->getBag('default')->first('avatar') ?: null;
}

it("change l'avatar d'un siège au lobby, diffuse seat.updated après validation et re-signe le jeton sous le même tid", function (): void {
    [$room, $host, $hostToken] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    $recorder = RecordingBroadcaster::install();
    $this->travel(5)->seconds();
    $writtenAt = Date::now()->toImmutable()->startOfMillisecond();

    $response = seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(7))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors();

    $guest->refresh();

    expect($guest->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($guest->avatar_preset)->toBe(SeatEntry::avatar(7))
        ->and($room->refresh()->last_activity_at?->toDateTimeString())->toBe($writtenAt->toDateTimeString());

    // Le jeton revendique le nouvel avatar, sous le même tid (I4.5).
    $resigned = SeatEntry::tokenFrom($response);

    expect($resigned->sameIdentityAs($guestToken))->toBeTrue()
        ->and($resigned->avatar)->toBe(SeatEntry::avatar(7));

    // Un seul message, au salon, vue de lobby du siège.
    expect(array_column($recorder->sent, 'event'))->toBe(['seat.updated']);

    [$updated] = $recorder->sent;

    expect($updated['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_keys($updated['payload']))->toBe(['v', 'serverNow', 'gameRef', 'seat'])
        ->and($updated['payload']['gameRef'])->toBeNull()
        ->and($updated['payload']['seat'])->toBe(json_decode(json_encode(
            SeatViewPresenter::lobby($guest, $host->id),
            JSON_THROW_ON_ERROR,
        ), true))
        ->and($updated['payload']['seat']['avatar']['kind'])->toBe('preset')
        ->and($updated['payload']['seat']['avatar']['url'])->toEndWith(SeatEntry::avatar(7).'.webp');

    WirePayload::assertSafe($updated['payload'], $updated['event']);
    expect($updated['json'])->not->toContain((string) $guest->player_token_hash);

    // La prop du lobby, rechargée seule, suit le changement.
    LobbyWrites::actAs($this, $hostToken);

    $this->withHeaders([EnsureActiveSeat::HEADER => (string) $host->active_seat_token])
        ->get(route('room.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('avatars.taken', [SeatEntry::avatar(7)])
            ->where('avatars.current', SeatEntry::avatar(1))
            ->etc());
});

it("n'écrit rien et ne diffuse rien quand l'avatar choisi est déjà le sien", function (): void {
    [$room, $host] = HostGestures::room();
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(4)]);
    $before = HostGestures::raw('player', $guest->id);
    $roomBefore = HostGestures::raw('room', $room->id);
    $recorder = RecordingBroadcaster::install();

    seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(4))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    expect(HostGestures::raw('player', $guest->id))->toBe($before)
        ->and(HostGestures::raw('room', $room->id))->toBe($roomBefore)
        ->and($recorder->sent)->toBe([]);
});

it("refuse un avatar déjà tenu par un autre siège du salon, prédéfini de repli d'une image de compte compris, sans rien écrire", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room, $host] = HostGestures::room();
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);

    // Un siège qui affiche l'image de son compte tient aussi son repli.
    $owner = User::factory()->withUploadedAvatar()->create(['avatar_preset' => SeatEntry::avatar(5)]);
    HostGestures::seat($room, 5, ['user_id' => $owner->id, 'avatar_kind' => AvatarKind::Upload, 'avatar_preset' => SeatEntry::avatar(5)]);
    // Un siège parti ne tient plus le sien.
    Player::factory()->for($room)->left()->create(['avatar_preset' => SeatEntry::avatar(6)]);

    $before = HostGestures::raw('player', $guest->id);
    $roomBefore = HostGestures::raw('room', $room->id);
    $recorder = RecordingBroadcaster::install();

    foreach ([SeatEntry::avatar(1), SeatEntry::avatar(5)] as $taken) {
        seatAvatarPost($this, $room, $guestToken, $guest, $taken)
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasErrors('avatar');

        expect(seatAvatarError())->toBeIn(seatAvatarMessages('room.lobby.avatar_taken'));
    }

    expect(HostGestures::raw('player', $guest->id))->toBe($before)
        ->and(HostGestures::raw('room', $room->id))->toBe($roomBefore)
        ->and($recorder->sent)->toBe([]);

    // La clé d'un siège parti est libre.
    seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(6))->assertSessionHasNoErrors();

    expect($guest->refresh()->avatar_preset)->toBe(SeatEntry::avatar(6));
});

it("refuse de changer d'avatar pendant une partie et au podium, et l'accepte après « Rejouer »", function (): void {
    [$room, $host] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    [$game] = HostGestures::runningGame($room, [$host->refresh(), $guest]);

    $assertRefused = function () use ($room, $guest, $guestToken): void {
        $before = HostGestures::raw('player', $guest->id);
        $recorder = RecordingBroadcaster::install();

        seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(8))
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertSessionHasErrors('avatar');

        expect(seatAvatarError())->toBeIn(seatAvatarMessages('room.refusal.not_in_lobby'))
            ->and(HostGestures::raw('player', $guest->id))->toBe($before)
            ->and($recorder->sent)->toBe([]);
    };

    // En partie.
    expect($room->refresh()->status)->toBe(RoomStatus::Playing);
    $assertRefused();

    // Au podium : la partie est figée, le salon reste `playing`.
    $this->travel(10)->minutes();
    app(FinalizeGame::class)->handle($game, GameStatus::Interrupted, Date::now()->toImmutable());

    expect($game->refresh()->ended_at)->not->toBeNull()
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing);
    $assertRefused();

    // Après « Rejouer » : de retour au lobby, le changement passe.
    expect(app(ReplayRoom::class)->handle($room, $host))->toBeNull()
        ->and($room->refresh()->status)->toBe(RoomStatus::Lobby);

    seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(8))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    expect($guest->refresh()->avatar_preset)->toBe(SeatEntry::avatar(8));
});

it("refuse une clé hors catalogue, et « Mon avatar » à un invité comme à un compte dont l'image est masquée", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);
    $before = HostGestures::raw('player', $guest->id);

    seatAvatarPost($this, $room, $guestToken, $guest, 'inconnu')->assertSessionHasErrors('avatar');
    seatAvatarPost($this, $room, $guestToken, $guest, SeatAvatar::ACCOUNT)->assertSessionHasErrors('avatar');

    $hidden = User::factory()->uploadedAvatarHidden()->create();
    $this->actingAs($hidden);
    seatAvatarPost($this, $room, $guestToken, $guest, SeatAvatar::ACCOUNT)->assertSessionHasErrors('avatar');

    expect(HostGestures::raw('player', $guest->id))->toBe($before);
});

it("donne « Mon avatar » au siège d'un compte à image visible, avec un repli libre, et le reflète dans la prop du lobby", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room, $host] = HostGestures::room();
    // Le prédéfini du compte est tenu par l'hôte : le repli en prend un libre.
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(3)]);
    $user = User::factory()->withUploadedAvatar()->create(['avatar_preset' => SeatEntry::avatar(3)]);
    [$seat, $token] = HostGestures::seat($room, 10, ['user_id' => $user->id, 'avatar_preset' => SeatEntry::avatar(9)]);
    $this->actingAs($user);

    // La prop du lobby offre « Mon avatar » et donne le choix courant.
    LobbyWrites::actAs($this, $token);

    $this->withHeaders([EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])
        ->get(route('room.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('avatars.options', AvatarPresetCatalog::options())
            ->where('avatars.taken', [SeatEntry::avatar(3)])
            ->where('avatars.current', SeatEntry::avatar(9))
            ->where('avatars.account.url', UploadedAvatars::url((string) $user->avatar_upload_path))
            ->etc());

    seatAvatarPost($this, $room, $token, $seat, SeatAvatar::ACCOUNT)
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    $seat->refresh();

    // Repli `suggest(users.avatar_preset ?? préféré, pris)` (spec 40
    // § 11.4) : le prédéfini du compte est tenu, donc la première clé libre.
    expect($seat->avatar_kind)->toBe(AvatarKind::Upload)
        ->and($seat->avatar_preset)->not->toBe(SeatEntry::avatar(3))
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(1));

    $this->withHeaders([EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])
        ->get(route('room.show', $room))
        ->assertInertia(fn (Assert $page) => $page->where('avatars.current', SeatAvatar::ACCOUNT)->etc());

    // Choisi de nouveau : inchangé, rien ne part.
    $recorder = RecordingBroadcaster::install();
    seatAvatarPost($this, $room, $token, $seat, SeatAvatar::ACCOUNT)->assertSessionHasNoErrors();

    expect($recorder->sent)->toBe([]);
});

it("ne propose ni n'accepte « Mon avatar » d'un compte connecté qui n'est pas celui du siège", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room] = HostGestures::room();
    $owner = User::factory()->withUploadedAvatar()->create();
    $other = User::factory()->withUploadedAvatar()->create();
    // Siège pris en invité (user_id nul), puis siège pris sous un autre compte.
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);
    [$owned, $ownedToken] = HostGestures::seat($room, 11, ['user_id' => $owner->id, 'avatar_preset' => SeatEntry::avatar(4)]);
    // Le compte connecté tient déjà un siège de ce salon : le rattachement
    // automatique (spec 40 § 13.2, D66 du 07/10) ne lui lie donc pas le siège
    // invité au rendu, qui reste un siège d'un autre que lui.
    HostGestures::seat($room, 12, ['user_id' => $other->id, 'avatar_preset' => SeatEntry::avatar(6)]);
    $guestBefore = HostGestures::raw('player', $guest->id);
    $ownedBefore = HostGestures::raw('player', $owned->id);

    // L'affichage lit `player.user_id` : « Mon avatar » n'y aurait aucun
    // effet (invité connecté après coup) ou montrerait l'image d'un autre
    // compte (même jeton, autre compte).
    foreach ([[$guest, $guestToken], [$owned, $ownedToken]] as [$seat, $token]) {
        $this->actingAs($other);
        LobbyWrites::actAs($this, $token);

        $this->withHeaders([EnsureActiveSeat::HEADER => (string) $seat->active_seat_token])
            ->get(route('room.show', $room))
            ->assertInertia(fn (Assert $page) => $page->where('avatars.account', null)->etc());

        seatAvatarPost($this, $room, $token, $seat, SeatAvatar::ACCOUNT)->assertSessionHasErrors('avatar');
    }

    expect(HostGestures::raw('player', $guest->id))->toBe($guestBefore)
        ->and(HostGestures::raw('player', $owned->id))->toBe($ownedBefore);
});

it('refuse un onglet supplanté (409), un visiteur sans siège et un siège expulsé (403)', function (): void {
    [$room] = HostGestures::room();
    [$guest, $guestToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(2)]);
    $before = HostGestures::raw('player', $guest->id);

    seatAvatarPost($this, $room, $guestToken, $guest, SeatEntry::avatar(8), (string) Str::ulid())
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(['code' => EnsureActiveSeat::SUPERSEDED]);

    seatAvatarPost($this, $room, PlayerToken::mint(Locale::French), $guest, SeatEntry::avatar(8))->assertForbidden();

    [$kicked, $kickedToken] = HostGestures::seat($room, 10, ['avatar_preset' => SeatEntry::avatar(3), 'kicked_at' => Date::now(), 'left_at' => Date::now(), 'connection_state' => 'left']);
    seatAvatarPost($this, $room, $kickedToken, $kicked, SeatEntry::avatar(8))->assertForbidden();

    expect(HostGestures::raw('player', $guest->id))->toBe($before)
        ->and($kicked->refresh()->avatar_preset)->toBe(SeatEntry::avatar(3));
});
