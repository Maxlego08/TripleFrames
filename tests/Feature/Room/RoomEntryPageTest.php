<?php

use App\Enums\Locale;
use App\Models\Player;
use App\Models\Room;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\I18n\TranslationDomains;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Pages d'entrée et de création, page du salon sans siège — spec 50 § 6.2,
| § 7.1 et § 7.2 (lot L50-3b)
|--------------------------------------------------------------------------
|
| Entrée libre par le code ou le lien, dans la limite des sièges. Un visiteur
| sans siège est renvoyé vers la page d'entrée publique, qui suit son
| apparence et ne lui montre ni pseudo ni identifiant ; l'état `entry` dit
| le salon complet, la partie en cours ou le refus d'un expulsé.
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
});

/**
 * Un salon au lobby et son hôte.
 *
 * @return array{0: Room, 1: Player}
 */
function roomEntryRoom(?RoomSettings $settings = null): array
{
    $room = Room::factory()->withSettings($settings ?? RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

/**
 * Les props propres d'une page, sans les props partagées (`HandleInertiaRequests`).
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function roomEntryOwnProps(TestResponse $response): array
{
    $props = $response->inertiaPage()['props'];

    return array_diff_key($props, array_flip([
        'name', 'auth', 'sidebarOpen', 'accountsOpen', 'oauthProviders', 'consent',
        'frameFormat', 'realtime', 'maintenance',
        'locale', 'locales', 'translations', 'errors', 'flash',
    ]));
}

/**
 * Toutes les clés d'une structure, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $value
 * @return list<string>
 */
function roomEntryKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $item) {
        $keys[] = (string) $key;

        if (is_array($item)) {
            array_push($keys, ...roomEntryKeys($item));
        }
    }

    return $keys;
}

/**
 * La balise `<html>` de la réponse Blade.
 *
 * @param  TestResponse<Response>  $response
 */
function roomEntryHtmlTag(TestResponse $response): string
{
    expect(preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $match))->toBe(1);

    return $match[0];
}

it('redirige un visiteur sans siège vers la page d\'entrée publique', function (): void {
    [$room, $host] = roomEntryRoom();

    // Sans jeton, avec un jeton d'un autre salon, avec un jeton expulsé : la
    // page du salon renvoie vers l'entrée, sans rien frapper.
    $elsewhere = PlayerToken::mint(Locale::English);
    LobbyWrites::seat(Room::factory()->create(), $elsewhere);
    $kicked = PlayerToken::mint(Locale::English);
    Player::factory()->for($room)->kicked()->create(['player_token_hash' => $kicked->hash()]);

    foreach (['sans jeton' => null, 'autre salon' => $elsewhere, 'expulsé' => $kicked] as $label => $token) {
        if ($token !== null) {
            LobbyWrites::actAs($this, $token);
        }

        $response = $this->get(route('room.show', $room));

        $response->assertStatus(Response::HTTP_SEE_OTHER)->assertRedirect(route('room.entry', $room));
        expect(SeatEntry::tokenCookies($response))->toBe([], $label);
    }

    // La page d'entrée : `room/join`, publique.
    $this->get(route('room.entry', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('room/join')->where('entry', 'kicked')->etc());

    // Contraste : le porteur d'un siège reçoit le salon lui-même, et la page
    // d'entrée le renvoie au salon.
    $holder = PlayerToken::mint(Locale::English);
    Player::query()->whereKey($host->id)->update(['player_token_hash' => $holder->hash()]);
    LobbyWrites::actAs($this, $holder);

    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('game/lobby', shouldExist: false)
            ->where('room.code', $room->room_code)
            ->where('state.self.publicId', $host->public_id)
            ->where('state.self.seatActive', true)
            ->has('seatToken'));

    $this->get(route('room.entry', $room))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room));
});

it("n'expose aux visiteurs sans siège ni pseudo ni identifiant interne", function (): void {
    [$room, $host] = roomEntryRoom();
    $seats = [
        $host,
        Player::factory()->for($room)->withNickname('Zoé')->create(['avatar_preset' => SeatEntry::avatar(9)]),
        Player::factory()->for($room)->withNickname('Parti')->left()->create(['avatar_preset' => SeatEntry::avatar(2)]),
    ];
    Player::query()->whereKey($host->id)->update(['avatar_preset' => SeatEntry::avatar(5)]);

    $response = $this->get(route('room.entry', $room))->assertOk();
    $props = roomEntryOwnProps($response);

    // Exactement les props du § 7.2, dans cet ordre : aucun avatar, la prise
    // de siège l'attribue (D55 du 02/10).
    expect(array_keys($props))->toBe(['room', 'entry', 'nickname'])
        ->and($props['room'])->toBe(['code' => $room->room_code])
        ->and($props['entry'])->toBe('open')
        ->and($props['nickname'])->toBe(['min' => NicknameNormalizer::MIN_LENGTH, 'max' => NicknameNormalizer::MAX_LENGTH]);

    // Aucune clé d'identifiant ni d'avatar, à toute profondeur, dans toute la page.
    $page = $response->inertiaPage();
    $forbidden = ['id', 'room_id', 'host_player_id', 'publicId', 'public_id', 'player_token_hash', 'nickname_normalized', 'seatToken', 'state', 'seats', 'avatars', 'taken'];

    expect(array_values(array_intersect(roomEntryKeys($page['props']), $forbidden)))->toBe([]);

    // Ni un pseudo, ni un `public_id`, ni un hash de jeton, nulle part.
    $json = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    foreach ($seats as $seat) {
        $seat->refresh();

        expect($json)->not->toContain((string) $seat->public_id)
            ->and($json)->not->toContain((string) $seat->player_token_hash)
            ->and($json)->not->toContain('"'.$seat->nickname.'"')
            ->and($json)->not->toContain('"'.$seat->avatar_preset.'"');
    }

    // La page de création non plus : ni avatar, ni salon.
    $create = roomEntryOwnProps($this->get(route('room.create'))->assertOk());

    expect(array_keys($create))->toBe(['nickname']);
});

it("annonce le salon complet, la partie en cours et le refus d'un expulsé", function (): void {
    $small = RoomSettings::fromInput(['capacity' => RoomSettingsBounds::MIN_CAPACITY]);

    $entryOf = function (Room $room, ?PlayerToken $token = null, ?Locale $locale = null) {
        app()->forgetInstance(TranslationDomains::class);

        if ($token !== null) {
            LobbyWrites::actAs($this, $token);
        }

        if ($locale !== null) {
            $this->withUnencryptedCookie('locale', $locale->value);
        }

        return $this->get(route('room.entry', $room))->assertOk();
    };

    // Au lobby, une place : le formulaire seul.
    [$open] = roomEntryRoom($small);

    $entryOf($open)->assertInertia(fn (Assert $page) => $page->component('room/join')->where('entry', 'open')->etc());

    // Complet : effectif présent = capacité.
    [$full] = roomEntryRoom($small);
    Player::factory()->for($full)->create();

    $entryOf($full)->assertInertia(fn (Assert $page) => $page->where('entry', 'full')->etc());

    // Partie en cours, podium compris : le formulaire, et l'attente.
    $playing = Room::factory()->playing()->create();

    $entryOf($playing)->assertInertia(fn (Assert $page) => $page->where('entry', 'in_progress')->etc());

    // Complet l'emporte sur la partie en cours.
    $playingFull = Room::factory()->withSettings($small)->playing()->create();
    Player::factory()->for($playingFull)->count(RoomSettingsBounds::MIN_CAPACITY)->create();

    $entryOf($playingFull)->assertInertia(fn (Assert $page) => $page->where('entry', 'full')->etc());

    // Expulsé : le refus l'emporte sur tout, salon complet compris.
    $kicked = PlayerToken::mint(Locale::French);
    Player::factory()->for($full)->kicked()->create(['player_token_hash' => $kicked->hash()]);

    // Le texte de chaque état voyage, traduit, dans le domaine `room`.
    foreach ([Locale::French, Locale::English] as $locale) {
        $response = $entryOf($full, $kicked, $locale);

        $response->assertInertia(fn (Assert $page) => $page->where('entry', 'kicked')->etc());

        $translations = $response->inertiaProps('translations');

        foreach (['room.join.kicked', 'room.join.full', 'room.join.in_progress', 'room.join.title', 'room.join.submit', 'legal.terms_notice'] as $key) {
            expect($translations[$key] ?? null)->toBe(trans($key, [], $locale->value), $key);
        }
    }

    // Le refus d'un expulsé à l'envoi : l'erreur `room`, traduite, sans
    // siège ni jeton, jamais une 1062.
    foreach ([Locale::French, Locale::English] as $locale) {
        $this->flushSession();

        $response = $this->withUnencryptedCookie('locale', $locale->value)
            ->from(route('room.entry', $full))
            ->post(route('room.join', $full), SeatEntry::form('Revenant'));

        $response->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.entry', $full))
            ->assertSessionHasErrors(['room' => trans('room.join.kicked', [], $locale->value)]);

        expect(SeatEntry::tokenCookies($response))->toBe([]);
    }

    expect(Player::query()->where('nickname', 'Revenant')->exists())->toBeFalse();

    // Le même jeton expulsé entre librement dans un autre salon.
    $this->flushSession();
    $this->post(route('room.join', $open), SeatEntry::form('Revenant'))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $open));

    expect(SeatEntry::seatOf($open, $kicked))->not->toBeNull();
});

it('redirige sans erreur vers la page de salon expiré quand on rejoint un salon archivé', function (): void {
    $archived = Room::factory()->archived()->create();

    // Un envoi, valide ou non : 303 vers la page du salon, sans erreur,
    // sans siège, sans jeton.
    foreach ([SeatEntry::form(), ['nickname' => 'x']] as $body) {
        $this->flushSession();

        $response = $this->from(route('room.entry', $archived))->post(route('room.join', $archived), $body);

        $response->assertStatus(Response::HTTP_SEE_OTHER)
            ->assertRedirect(route('room.show', $archived))
            ->assertSessionHasNoErrors();

        expect(SeatEntry::tokenCookies($response))->toBe([]);
    }

    expect(Player::query()->count())->toBe(0);

    // La page d'entrée aussi mène au salon…
    $this->get(route('room.entry', $archived))
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $archived));

    // … qui rend « salon expiré », en 410, jamais une 404.
    $this->get(route('room.show', $archived))
        ->assertStatus(Response::HTTP_GONE)
        ->assertInertia(fn (Assert $page) => $page->component('game/room-expired'));
});

it('rend les pages d\'entrée et de création en sombre, quel que soit le cookie d\'apparence', function (): void {
    [$room] = roomEntryRoom();

    // Tout le site est sombre (D56 du 02/10) : les deux pages d'entrée le
    // sont aussi, sans attribut de forçage, et un cookie `appearance` hérité
    // d'avant la décision n'y change rien.
    foreach (['room.create' => route('room.create'), 'room.entry' => route('room.entry', $room)] as $name => $url) {
        foreach (['light', 'system', 'dark'] as $appearance) {
            $tag = roomEntryHtmlTag($this->withUnencryptedCookie('appearance', $appearance)->get($url)->assertOk());

            expect($tag)->not->toContain('data-appearance-forced', "{$name} ({$appearance})")
                ->and(preg_match('/\sclass="[^"]*\bdark\b/', $tag))->toBe(1, "{$name} ({$appearance})");
        }
    }
});
