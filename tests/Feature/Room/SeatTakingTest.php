<?php

use App\Avatars\UploadedAvatars;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Rules\ValidNickname;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\GameRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Prise de siège — spec 50 § 7.3 (lot L50-3b) ; 40 § 2.1 étape 4, C4, C5
|--------------------------------------------------------------------------
|
| La séquence S1 à S10 sous le verrou du salon : reprise du siège du jeton
| avant tout comptage, effectif présent et non historique des sièges,
| unicité du pseudo sur tous les sièges du salon, partis et expulsés
| compris, jamais une 1062. Le jeton n'est frappé qu'une fois tous les
| refus écartés, puis re-signé avec l'avatar que le serveur attribue
| (D55 du 02/10).
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();

    // Les gestes de ce fichier viennent tous de la même adresse, souvent
    // sans jeton : le limiteur `room-join` (§ 17.3) n'est pas leur sujet.
    config()->set('game.room.joins_per_minute', 1000);
});

/**
 * Un salon au lobby et son hôte, connecté — la réparation d'hôte (S8) n'a
 * alors rien à faire.
 *
 * @return array{0: Room, 1: Player}
 */
function seatTakingRoom(?RoomSettings $settings = null, string $hostNickname = 'Hôte'): array
{
    $room = Room::factory()->withSettings($settings ?? RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->withNickname($hostNickname)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

/** Réglages d'un salon dont la capacité est la plus basse admise. */
function seatTakingSmallRoom(): RoomSettings
{
    return RoomSettings::fromInput(['capacity' => RoomSettingsBounds::MIN_CAPACITY]);
}

it('refuse un pseudo déjà pris dans le salon, sièges partis et expulsés compris', function (): void {
    [$room] = seatTakingRoom(hostNickname: 'Zoé');
    Player::factory()->for($room)->withNickname('Jean-Luc')->left()->create();
    Player::factory()->for($room)->withNickname('Max')->kicked()->create();
    $before = SeatEntry::rawSeats($room);

    // Même forme repliée que Zoé (tenu), Jean-Luc (parti), Max (expulsé).
    $taken = ['zoe', 'ZOÉ', 'jean luc', 'JEAN_LUC', 'max', 'M-a-x'];

    foreach ([Locale::French, Locale::English] as $locale) {
        foreach ($taken as $nickname) {
            $this->flushSession();

            $response = $this->withUnencryptedCookie('locale', $locale->value)
                ->from(route('room.entry', $room))
                ->post(route('room.join', $room), SeatEntry::form($nickname));

            $response->assertRedirect(route('room.entry', $room))
                ->assertSessionHasErrors(['nickname' => trans(ValidNickname::KEY_TAKEN, [], $locale->value)]);

            // Refusé avant la frappe : aucun `Set-Cookie` `player_token`.
            expect(SeatEntry::tokenCookies($response))->toBe([], $nickname);
        }
    }

    expect(SeatEntry::rawSeats($room))->toBe($before);

    // Le même pseudo reste libre dans un autre salon, et un pseudo libre
    // entre dans celui-ci.
    [$other] = seatTakingRoom();

    $this->flushSession();
    $this->post(route('room.join', $other), SeatEntry::form('Zoé'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    $this->flushSession();
    $this->post(route('room.join', $room), SeatEntry::form('Bob'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    expect(Player::query()->whereBelongsTo($room)->count())->toBe(4);
});

it("traduit une collision de pseudo qui atteint l'index unique au lieu de laisser fuiter une 1062", function (): void {
    [$room, $host] = seatTakingRoom();

    // Un siège concurrent, validé entre la lecture de l'unicité (S5) et
    // l'insertion (S6) : la seule voie par laquelle l'index unique tranche.
    $raced = false;

    Player::creating(function (Player $seat) use ($room, &$raced): void {
        if ($raced || $seat->room_id !== $room->id) {
            return;
        }

        $raced = true;

        Player::factory()->for($room)->withNickname('Zoé')->make()->saveQuietly();
    });

    foreach ([Locale::French, Locale::English] as $locale) {
        $raced = false;
        $this->flushSession();

        $response = $this->withUnencryptedCookie('locale', $locale->value)
            ->from(route('room.entry', $room))
            ->post(route('room.join', $room), SeatEntry::form('zoé'));

        // L'erreur de champ traduite, jamais une 1062 ni une 500.
        $response->assertRedirect(route('room.entry', $room))
            ->assertSessionHasErrors(['nickname' => trans(ValidNickname::KEY_TAKEN, [], $locale->value)]);

        expect($raced)->toBeTrue();
    }

    // Rien n'est resté : ni le siège de la requête, ni un siège à moitié écrit.
    expect(Player::query()->whereBelongsTo($room)->pluck('id')->all())->toBe([$host->id]);
});

it("ne revalide jamais le pseudo d'un siège repris", function (): void {
    $token = PlayerToken::mint(Locale::English, SeatEntry::avatar(4));
    $room = Room::factory()->create();

    // Un pseudo entré depuis dans la liste noire : un siège neuf le verrait
    // refusé, le siège existant ne l'est jamais (I5.5).
    $seat = Player::factory()->for($room)->withNickname('Admin')->create([
        'player_token_hash' => $token->hash(),
        'avatar_preset' => SeatEntry::avatar(4),
    ]);
    Room::query()->whereKey($room->id)->update(['host_player_id' => $seat->id]);

    $this->post(route('room.join', $room), SeatEntry::form('Admin'))
        ->assertSessionHasErrors(['nickname' => trans(ValidNickname::KEY_BLOCKED)]);

    $before = SeatEntry::rawSeats($room);
    $recorder = RecordingBroadcaster::install();

    LobbyWrites::actAs($this, $token);

    // Sans champ, avec le pseudo refusé, avec un pseudo mal formé : trois
    // reprises du même siège, sans aucune écriture.
    $bodies = [[], [...SeatEntry::form('Admin'), 'avatar' => SeatEntry::avatar(9)], ['nickname' => 'x', 'avatar' => 'inconnu']];

    foreach ($bodies as $body) {
        $this->flushSession();

        $response = $this->post(route('room.join', $room), $body);

        $response->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        // Le cookie glisse sous le même tid ; l'avatar n'est pas re-signé,
        // puisque rien n'écrit `avatar_preset`.
        $slid = SeatEntry::tokenFrom($response);

        expect($slid->sameIdentityAs($token))->toBeTrue()
            ->and($slid->avatar)->toBe(SeatEntry::avatar(4));
    }

    expect(SeatEntry::rawSeats($room))->toBe($before)
        ->and($recorder->sent)->toBe([]);
});

it('reprend le siège du jeton avant tout comptage de capacité', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = seatTakingRoom(seatTakingSmallRoom());
    $mine = LobbyWrites::seat($room, $token);

    // Effectif au-dessus de la capacité : le retour d'un siège parti ne
    // consomme aucune place (§ 7.3, conséquences assumées).
    Player::factory()->for($room)->create();

    expect(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBeGreaterThan($room->capacity);

    // Un nouveau venu est refusé : le salon est complet.
    $this->from(route('room.entry', $room))
        ->post(route('room.join', $room), SeatEntry::form('Nouveau'))
        ->assertSessionHasErrors(['room' => trans('room.join.full')]);

    $before = SeatEntry::rawSeats($room);

    // Le porteur du jeton reprend son siège, sans comptage — y compris un
    // siège parti, que la présence ramènera (60).
    LobbyWrites::actAs($this, $token);

    foreach ([PlayerConnectionState::Connected, PlayerConnectionState::Left] as $state) {
        Player::query()->whereKey($mine->id)->update(['connection_state' => $state->value]);
        $before = SeatEntry::rawSeats($room);

        $this->flushSession();
        $this->post(route('room.join', $room), [])
            ->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $room))
            ->assertSessionHasNoErrors();

        expect(SeatEntry::rawSeats($room))->toBe($before, $state->value);
    }

    expect($host->refresh()->connection_state)->toBe(PlayerConnectionState::Connected);
});

it("compte l'effectif présent et non l'historique des sièges", function (): void {
    [$room] = seatTakingRoom(seatTakingSmallRoom());
    Player::factory()->for($room)->left()->count(2)->create();
    Player::factory()->for($room)->kicked()->create();

    // Quatre lignes, un seul siège tenu : il reste une place.
    $this->post(route('room.join', $room), SeatEntry::form('Arrivée'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room));

    $arrival = Player::query()->where('nickname', 'Arrivée')->sole();

    // Un siège déconnecté tient toujours sa place.
    Player::query()->whereKey($arrival->id)->update(['connection_state' => PlayerConnectionState::Disconnected->value]);

    $this->flushSession();
    $this->from(route('room.entry', $room))
        ->post(route('room.join', $room), SeatEntry::form('Refusé'))
        ->assertSessionHasErrors(['room' => trans('room.join.full')]);

    // Un départ libère sa place.
    Player::query()->whereKey($arrival->id)->update(['connection_state' => PlayerConnectionState::Left->value]);

    $this->flushSession();
    $this->post(route('room.join', $room), SeatEntry::form('Suivant'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    expect(Player::query()->whereBelongsTo($room)->count())->toBe(6)
        ->and(Player::query()->whereBelongsTo($room)->holdingSeat()->count())->toBe(RoomSettingsBounds::MIN_CAPACITY)
        ->and(Player::query()->where('nickname', 'Refusé')->exists())->toBeFalse();
});

it('refuse un salon complet avec un message traduit', function (): void {
    [$room] = seatTakingRoom(seatTakingSmallRoom());
    Player::factory()->for($room)->create();
    $before = SeatEntry::rawSeats($room);

    expect(trans('room.join.full', [], Locale::French->value))
        ->not->toBe('room.join.full')
        ->not->toBe(trans('room.join.full', [], Locale::English->value));

    foreach ([Locale::French, Locale::English] as $locale) {
        $this->flushSession();

        $response = $this->withUnencryptedCookie('locale', $locale->value)
            ->from(route('room.entry', $room))
            ->post(route('room.join', $room), SeatEntry::form('Nouveau'));

        // Retour au formulaire d'entrée, erreur `room` dans la langue du
        // joueur, jamais une erreur brute ni un code.
        $response->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.entry', $room))
            ->assertSessionHasErrors(['room' => trans('room.join.full', [], $locale->value)]);

        expect(SeatEntry::tokenCookies($response))->toBe([]);
    }

    // Sans page d'origine — ni en-tête `Referer`, ni URL précédente en
    // session : le formulaire d'entrée, jamais l'accueil.
    $this->flushSession();
    $this->withoutHeader('referer')
        ->post(route('room.join', $room), SeatEntry::form('Nouveau'))
        ->assertRedirect(route('room.entry', $room));

    expect(SeatEntry::rawSeats($room))->toBe($before);
});

it("rend à l'accueil les refus d'une entrée par la carte « Rejoindre », et y reprend le siège du jeton sous son ancien pseudo", function (): void {
    // D55 du 02/10, point 6 : code et pseudo envoyés depuis l'accueil.
    [$full] = seatTakingRoom(seatTakingSmallRoom());
    Player::factory()->for($full)->create();

    $this->from(route('home'))
        ->post(route('room.join', $full), SeatEntry::form('Nouveau'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors(['room' => trans('room.join.full')]);

    // Expulsé : refusé avant tout comptage, de retour sur l'accueil.
    [$kickedRoom] = seatTakingRoom();
    $kickedToken = PlayerToken::mint(Locale::English, SeatEntry::avatar(6));
    Player::factory()->for($kickedRoom)->kicked()->create(['player_token_hash' => $kickedToken->hash()]);
    LobbyWrites::actAs($this, $kickedToken);

    $this->flushSession();
    $this->from(route('home'))
        ->post(route('room.join', $kickedRoom), SeatEntry::form('Revenant'))
        ->assertRedirect(route('home'))
        ->assertSessionHasErrors(['room' => trans('room.join.kicked')]);

    // Déjà assis : reprise du siège, le pseudo saisi est ignoré.
    [$heldRoom] = seatTakingRoom();
    $heldToken = PlayerToken::mint(Locale::English, SeatEntry::avatar(4));
    Player::factory()->for($heldRoom)->withNickname('Ancien')->create([
        'player_token_hash' => $heldToken->hash(),
        'avatar_preset' => SeatEntry::avatar(4),
    ]);
    $before = SeatEntry::rawSeats($heldRoom);
    LobbyWrites::actAs($this, $heldToken);

    $this->flushSession();
    $this->from(route('home'))
        ->post(route('room.join', $heldRoom), SeatEntry::form('Nouveau'))
        ->assertRedirect(route('room.show', $heldRoom))
        ->assertSessionHasNoErrors();

    expect(SeatEntry::rawSeats($heldRoom))->toBe($before)
        ->and(Player::query()->whereBelongsTo($heldRoom)->where('nickname', 'Nouveau')->exists())->toBeFalse();
});

it('laisse entrer dans un salon en partie et fait attendre la partie suivante quand les retardataires sont fermés', function (): void {
    $settings = RoomSettings::fromInput(['allowLateJoin' => false]);
    $room = Room::factory()->withSettings($settings)->playing()->create();
    $host = Player::factory()->for($room)->create();
    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    $game = Game::factory()->forRoom($room)->withSettings($settings)->create();
    GamePlayer::factory()->for($game)->frozenFrom($host)->create();

    $recorder = RecordingBroadcaster::install();

    $this->post(route('room.join', $room), SeatEntry::form('Retard'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    // Un siège, compté dans la capacité, sans participation : il jouera la
    // partie suivante, au « Rejouer ».
    $seat = Player::query()->where('nickname', 'Retard')->sole();

    expect($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and(GamePlayer::query()->where('player_id', $seat->id)->exists())->toBeFalse()
        ->and(GamePlayer::query()->whereBelongsTo($game)->count())->toBe(1)
        ->and($room->refresh()->status)->toBe(RoomStatus::Playing);

    // Annoncé au salon, dans la partie en cours, sans première manche.
    expect(array_column($recorder->sent, 'event'))->toBe(['seat.joined'])
        ->and($recorder->sent[0]['payload']['gameRef'])->toBe(GameRef::for($game))
        ->and($recorder->sent[0]['payload']['seat'])->toMatchArray([
            'publicId' => $seat->public_id,
            'isHost' => false,
            'firstRoundNumber' => null,
        ]);

    // Podium affiché : la partie est figée, le salon encore en partie.
    $podium = Room::factory()->withSettings($settings)->playing()->create();
    $podiumGame = Game::factory()->forRoom($podium)->withSettings($settings)->completed()->create();

    $this->flushSession();
    $this->post(route('room.join', $podium), SeatEntry::form('Retard'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertSessionHasNoErrors();

    $waiting = Player::query()->whereBelongsTo($podium)->where('nickname', 'Retard')->sole();

    expect(GamePlayer::query()->where('player_id', $waiting->id)->exists())->toBeFalse()
        ->and(GamePlayer::query()->whereBelongsTo($podiumGame)->count())->toBe(0);
});

it("attribue à la prise de siège l'avatar préféré du jeton s'il est libre, sinon le premier libre, et re-signe le jeton avec lui", function (): void {
    [$first] = seatTakingRoom();
    Player::query()->whereBelongsTo($first)->update(['avatar_preset' => SeatEntry::avatar(1)]);

    // Sans jeton : le premier prédéfini libre, quel que soit le champ
    // `avatar` envoyé — ignoré (D55 du 02/10).
    $seated = $this->post(route('room.join', $first), [...SeatEntry::form('Zoé'), 'avatar' => SeatEntry::avatar(6)])
        ->assertSessionHasNoErrors();
    $token = SeatEntry::tokenFrom($seated);

    expect($token->avatar)->toBe(SeatEntry::avatar(2))
        ->and(SeatEntry::seatOf($first, $token)?->avatar_preset)->toBe(SeatEntry::avatar(2))
        ->and(SeatEntry::seatOf($first, $token)?->avatar_kind)->toBe(AvatarKind::Preset);

    // Avec un jeton qui revendique une clé libre : cette clé, même tid, un
    // seul `Set-Cookie`.
    [$free] = seatTakingRoom();
    Player::query()->whereBelongsTo($free)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    LobbyWrites::actAs($this, $token->withAvatar(SeatEntry::avatar(11)));

    $this->flushSession();
    $resigned = SeatEntry::tokenFrom($this->post(route('room.join', $free), SeatEntry::form('Zoé')));

    expect($resigned->sameIdentityAs($token))->toBeTrue()
        ->and($resigned->avatar)->toBe(SeatEntry::avatar(11))
        ->and(SeatEntry::seatOf($free, $token)?->avatar_preset)->toBe(SeatEntry::avatar(11));

    // Clé revendiquée déjà tenue : la première clé libre ; un siège parti
    // libère la sienne.
    [$busy] = seatTakingRoom();
    Player::query()->whereBelongsTo($busy)->update(['avatar_preset' => SeatEntry::avatar(11)]);
    Player::factory()->for($busy)->create(['avatar_preset' => SeatEntry::avatar(1)]);
    Player::factory()->for($busy)->left()->create(['avatar_preset' => SeatEntry::avatar(2)]);
    LobbyWrites::actAs($this, $resigned);

    $this->flushSession();
    $fallback = SeatEntry::tokenFrom($this->post(route('room.join', $busy), SeatEntry::form('Zoé')));

    expect($fallback->avatar)->toBe(SeatEntry::avatar(2))
        ->and(SeatEntry::seatOf($busy, $token)?->avatar_preset)->toBe(SeatEntry::avatar(2));
});

it("attribue « Mon avatar » au compte dont l'image téléversée est l'avatar choisi, repli libre compris", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room] = seatTakingRoom();
    // Le prédéfini du compte est déjà tenu dans le salon.
    Player::query()->whereBelongsTo($room)->update(['avatar_preset' => SeatEntry::avatar(4)]);
    $user = User::factory()->withUploadedAvatar()->create(['avatar_preset' => SeatEntry::avatar(4)]);

    $joined = $this->actingAs($user)->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();
    $seat = Player::query()->whereBelongsTo($room)->where('nickname', 'Zoé')->sole();

    // L'image du compte, et un repli qui n'est pas celui d'un autre siège.
    expect($seat->avatar_kind)->toBe(AvatarKind::Upload)
        ->and($seat->user_id)->toBe($user->id)
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(1))
        ->and(SeatEntry::claims($joined)['avatar'])->toBe(SeatEntry::avatar(1));
});

it("attribue un prédéfini, jamais la photo du fournisseur, au compte dont l'image téléversée choisie est masquée", function (): void {
    Storage::fake(UploadedAvatars::DISK);
    [$room] = seatTakingRoom();
    Player::query()->whereBelongsTo($room)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    // Avatar choisi : l'image téléversée, masquée ; photo Google visible.
    $user = User::factory()->uploadedAvatarHidden()->create([
        'avatar_preset' => SeatEntry::avatar(9),
        'avatar_provider_path' => 'provider-photo.webp',
        'avatar_provider_hidden_at' => null,
    ]);

    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French)->withAvatar(SeatEntry::avatar(5)));

    $this->actingAs($user)->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();
    $seat = Player::query()->whereBelongsTo($room)->where('nickname', 'Zoé')->sole();

    // Spec 40 § 11.4 : `upload` seulement si l'image téléversée elle-même
    // est visible ; sinon `suggest(préféré du jeton, pris)`.
    expect($seat->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(5));
});

it("attribue un prédéfini libre au compte dont l'avatar choisi est un prédéfini, photo du fournisseur visible comprise", function (): void {
    [$room] = seatTakingRoom();
    Player::query()->whereBelongsTo($room)->update(['avatar_preset' => SeatEntry::avatar(1)]);
    $user = User::factory()->withProviderAvatar()->create();
    $user->forceFill(['avatar_kind' => AvatarKind::Preset, 'avatar_preset' => SeatEntry::avatar(9)])->save();

    LobbyWrites::actAs($this, PlayerToken::mint(Locale::French)->withAvatar(SeatEntry::avatar(5)));

    $this->actingAs($user)->post(route('room.join', $room), SeatEntry::form('Zoé'))
        ->assertSessionHasNoErrors();
    $seat = Player::query()->whereBelongsTo($room)->where('nickname', 'Zoé')->sole();

    // Seule l'image téléversée choisie est attribuée d'office, comme la
    // présélection d'avant D55 : ici, la préférence du jeton.
    expect($seat->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(5));
});

it('ne donne jamais le même prédéfini à deux sièges tenus pris à la suite', function (): void {
    [$room] = seatTakingRoom();
    Player::query()->whereBelongsTo($room)->update(['avatar_preset' => SeatEntry::avatar(1)]);

    // Trois visiteurs sans jeton, chacun frappe le sien.
    foreach (['Ana', 'Bob', 'Cid'] as $nickname) {
        $this->post(route('room.join', $room), SeatEntry::form($nickname))->assertSessionHasNoErrors();
    }

    $held = Player::query()->whereBelongsTo($room)->holdingSeat()->orderBy('id')->pluck('avatar_preset')->all();

    expect($held)->toBe([SeatEntry::avatar(1), SeatEntry::avatar(2), SeatEntry::avatar(3), SeatEntry::avatar(4)]);
});

it('écrit la langue effective de la requête sur le siège', function (): void {
    [$room] = seatTakingRoom();

    // Sans cookie de langue : la négociation `Accept-Language`.
    $negotiated = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9,en;q=0.5')
        ->post(route('room.join', $room), SeatEntry::form('Négocié'));

    expect(Player::query()->where('nickname', 'Négocié')->sole()->locale)->toBe(Locale::French)
        ->and(SeatEntry::tokenFrom($negotiated)->locale)->toBe(Locale::French);

    // Le cookie `locale` du joueur l'emporte, dans chaque langue activée.
    foreach (Locale::cases() as $locale) {
        $this->flushSession();

        $response = $this->withUnencryptedCookie('locale', $locale->value)
            ->post(route('room.join', $room), SeatEntry::form('Joueur '.$locale->value));

        $seat = Player::query()->where('nickname', 'Joueur '.$locale->value)->sole();
        $raw = DB::table('player')->where('id', $seat->id)->value('locale');

        expect($seat->locale)->toBe($locale)
            ->and($raw)->toBe($locale->value)
            ->and(SeatEntry::tokenFrom($response)->locale)->toBe($locale);
    }
});

it("limite les entrées par jeton, repli sur l'adresse", function (): void {
    config()->set('game.room.joins_per_minute', 2);

    [$room] = seatTakingRoom();

    // Sans jeton, le débit est compté par adresse (repli `ip:`, § 17.3) :
    // deux entrées refusées à la validation, puis 429 — aucun cookie
    // `player_token` n'est encore présenté.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    foreach ([false, false, true] as $throttled) {
        $this->flushSession();
        $response = $this->post(route('room.join', $room), ['nickname' => 'x']);

        $throttled
            ? $response->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
            : $response->assertRedirect();
    }

    // Une autre adresse, toujours sans jeton, garde son propre débit.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);
    $this->flushSession();
    $this->post(route('room.join', $room), ['nickname' => 'x'])->assertRedirect();

    expect(SeatEntry::rawSeats($room))->toHaveCount(1);

    $token = PlayerToken::mint(Locale::English);
    LobbyWrites::seat($room, $token);
    LobbyWrites::actAs($this, $token);

    // Le débit est compté par jeton (`room-join`, § 17.3) : au-delà, 429.
    foreach ([Response::HTTP_SEE_OTHER, Response::HTTP_SEE_OTHER, Response::HTTP_TOO_MANY_REQUESTS] as $status) {
        $this->flushSession();
        $this->post(route('room.join', $room), [])->assertStatus($status);
    }

    // Un autre jeton, de la même adresse, garde son propre débit.
    LobbyWrites::actAs($this, PlayerToken::mint(Locale::English));

    $this->flushSession();
    $this->post(route('room.join', $room), SeatEntry::form('Autre'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room));

    expect(app('router')->getRoutes()->getByName('room.join')?->gatherMiddleware())
        ->toContain('throttle:room-join')
        ->not->toContain('seat.active');
});
