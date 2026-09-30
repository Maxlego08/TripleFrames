<?php

use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Realtime\ChannelNames;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Autorisation des canaux — spec 60 § 10.4, contrat C7 § 2.2
|--------------------------------------------------------------------------
|
| De bout en bout, par `/broadcasting/auth` et le diffuseur Reverb (protocole
| Pusher) : garde `player` sur le `player_token`, classes de canal, pile
| `web` + `throttle:game-read`, réponse signée et membre de présence.
|
| Les tests tournent sur la diffusion `null` (phpunit.xml), dont `auth()` ne
| vérifie rien ; `routes/channels.php` inscrit ses canaux sur le pilote par
| défaut au démarrage. Chaque test installe donc le pilote `reverb` — clés de
| test, jamais un secret — et y rejoue `routes/channels.php`, comme en
| production.
|
| La garde `player` est une `RequestGuard`, qui garde son principal pour la
| durée de vie de l'application : en production une requête, ici tout le
| test. Chaque requête repart donc de gardes oubliées.
|
*/

/** Installe le diffuseur Reverb et y inscrit les canaux de `routes/channels.php`. */
function channelAuthInstallReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'channel-test-key',
        'broadcasting.connections.reverb.secret' => 'channel-test-signing-value',
        'broadcasting.connections.reverb.app_id' => 'channel-test',
        'broadcasting.connections.reverb.options' => [
            'host' => '127.0.0.1',
            'port' => 8080,
            'scheme' => 'http',
            'useTLS' => false,
        ],
    ]);

    Broadcast::purge('reverb');

    require base_path('routes/channels.php');
}

/** Un siège du salon tenu par ce jeton. */
function channelAuthSeat(Room $room, PlayerToken $token): Player
{
    return Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
    ]);
}

/**
 * Une demande d'autorisation de canal, comme Echo la poste, sous le jeton donné.
 *
 * @return TestResponse<Response>
 */
function channelAuthRequest(TestCase $test, string $channel, ?PlayerToken $token): TestResponse
{
    Auth::forgetGuards();

    // Chaque demande ne porte que SON jeton : les cookies d'une requête
    // précédente du même test sont oubliés.
    (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);

    if ($token !== null) {
        $test->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));
    }

    return $test->post(
        '/broadcasting/auth',
        ['channel_name' => $channel, 'socket_id' => '1234.5678'],
        ['Accept' => 'application/json'],
    );
}

/** Nom Pusher du canal de présence d'un salon. */
function channelAuthPresence(Room $room): string
{
    return 'presence-'.ChannelNames::room($room);
}

/** Nom Pusher du canal privé d'un siège. */
function channelAuthPrivate(Player $seat): string
{
    return 'private-'.ChannelNames::seat($seat);
}

beforeEach(fn () => channelAuthInstallReverb());

it("un invité porteur du player_token d'un siège du salon rejoint le canal de présence", function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = channelAuthSeat($room, $token);

    // La pile de `withBroadcasting()` : `web` (dont `EncryptCookies`, sans
    // lequel le jeton se lirait absent) et le limiteur de lecture du moteur.
    $route = Route::getRoutes()->match(Request::create('/broadcasting/auth', 'POST'));
    expect($route->gatherMiddleware())->toContain('web', 'throttle:game-read');

    $response = channelAuthRequest($this, channelAuthPresence($room), $token)->assertOk();

    expect($response->json('auth'))->toBeString()->toStartWith('channel-test-key:')
        ->and($response->json('channel_data'))->toBeString();

    // L'état de connexion est indifférent : la présence Reverb ne fait jamais
    // foi, un siège déconnecté ou parti (non expulsé) se réabonne.
    $seat->forceFill(['connection_state' => PlayerConnectionState::Left, 'left_at' => now()])->save();

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertOk();

    // Et son propre canal privé.
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertOk()
        ->assertJsonMissingPath('channel_data');
});

it('le membre de présence ne porte que le public_id du siège, jamais le hash du jeton', function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::English);
    $seat = channelAuthSeat($room, $token);

    $response = channelAuthRequest($this, channelAuthPresence($room), $token)->assertOk();

    $member = json_decode((string) $response->json('channel_data'), true, flags: JSON_THROW_ON_ERROR);

    expect($member)->toBe([
        'user_id' => $seat->public_id,
        'user_info' => ['publicId' => $seat->public_id],
    ]);

    $body = (string) $response->getContent();

    expect($body)->not->toContain($token->hash())
        ->not->toContain((string) $seat->active_seat_token)
        ->not->toContain((string) $seat->nickname)
        ->not->toContain('"'.$seat->id.'"');
});

it('un jeton sans siège dans ce salon est refusé sur le canal de présence', function (): void {
    $room = Room::factory()->create();
    $elsewhere = Room::factory()->create();
    channelAuthSeat($room, PlayerToken::mint(Locale::French));

    // Un jeton qui tient un siège, mais dans un autre salon.
    $stranger = PlayerToken::mint(Locale::French);
    channelAuthSeat($elsewhere, $stranger);

    channelAuthRequest($this, channelAuthPresence($room), $stranger)->assertForbidden();
    channelAuthRequest($this, channelAuthPresence($elsewhere), $stranger)->assertOk();

    // Un jeton qui ne tient aucun siège, un siège solo (aucun canal en solo),
    // aucun jeton, une clé de canal forgée.
    $seatless = PlayerToken::mint(Locale::English);
    $solo = PlayerToken::mint(Locale::English);
    Player::factory()->solo()->create(['player_token_hash' => $solo->hash()]);

    channelAuthRequest($this, channelAuthPresence($room), $seatless)->assertForbidden();
    channelAuthRequest($this, channelAuthPresence($room), $solo)->assertForbidden();
    channelAuthRequest($this, channelAuthPresence($room), null)->assertForbidden();
    channelAuthRequest($this, 'presence-'.ChannelNames::ROOM_PREFIX.$room->room_code, $stranger)->assertForbidden();
});

it("le canal privé d'un siège refuse le jeton d'un autre siège", function (): void {
    $room = Room::factory()->create();
    $aliceToken = PlayerToken::mint(Locale::French);
    $bobToken = PlayerToken::mint(Locale::English);
    $alice = channelAuthSeat($room, $aliceToken);
    $bob = channelAuthSeat($room, $bobToken);

    // Connaître un `public_id` — il est diffusé au salon — n'ouvre jamais le
    // canal ciblé d'un autre siège.
    channelAuthRequest($this, channelAuthPrivate($bob), $aliceToken)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($alice), $bobToken)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($bob), null)->assertForbidden();

    channelAuthRequest($this, channelAuthPrivate($alice), $aliceToken)->assertOk();
    channelAuthRequest($this, channelAuthPrivate($bob), $bobToken)->assertOk();
});

it("un siège expulsé est refusé sur les deux canaux jusqu'à l'archivage", function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = channelAuthSeat($room, $token);

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertOk();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertOk();

    // Expulsion (D15 du 23/09) : `left`, `left_at = kicked_at`.
    $kickedAt = now();
    $seat->forceFill([
        'connection_state' => PlayerConnectionState::Left,
        'left_at' => $kickedAt,
        'kicked_at' => $kickedAt,
    ])->save();

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertForbidden();

    // Aucune réadmission : même revenu `connected`, `kicked_at` reste posée.
    $this->travel(10)->minutes();
    $seat->forceFill(['connection_state' => PlayerConnectionState::Connected, 'left_at' => null])->save();

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertForbidden();

    // Le même jeton garde ses canaux dans un autre salon.
    $other = Room::factory()->create();
    $otherSeat = channelAuthSeat($other, $token);

    channelAuthRequest($this, channelAuthPresence($other), $token)->assertOk();
    channelAuthRequest($this, channelAuthPrivate($otherSeat), $token)->assertOk();

    // À l'archivage, le hash est effacé : le refus tombe avec le salon.
    $room->forceFill(['status' => RoomStatus::Archived, 'room_code_active' => null, 'archived_at' => now()])->save();
    $seat->forceFill(['player_token_hash' => null])->save();

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertForbidden();
});

it('un salon archivé refuse toute souscription', function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = channelAuthSeat($room, $token);

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertOk();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertOk();

    // Archivé, hash encore présent : c'est `archived_at` qui refuse, pas
    // l'effacement des identifiants d'invité.
    $room->forceFill(['status' => RoomStatus::Archived, 'room_code_active' => null, 'archived_at' => now()])->save();

    expect($seat->fresh()?->player_token_hash)->toBe($token->hash());

    channelAuthRequest($this, channelAuthPresence($room), $token)->assertForbidden();
    channelAuthRequest($this, channelAuthPrivate($seat), $token)->assertForbidden();
});

it('deux salons successifs portant le même room_code ont deux clés de canal différentes', function (): void {
    $archived = Room::factory()->archived()->create(['room_code' => 'K7M2PQ']);
    $current = Room::factory()->create(['room_code' => 'K7M2PQ', 'room_code_active' => 'K7M2PQ']);

    expect(ChannelNames::roomKey($archived))->not->toBe(ChannelNames::roomKey($current))
        ->and(ChannelNames::room($current))->not->toContain('K7M2PQ')
        ->and(ChannelNames::room($archived))->not->toContain('K7M2PQ');

    // Un onglet resté abonné au salon archivé n'entend jamais le suivant : le
    // siège du salon courant n'ouvre que sa propre clé.
    $token = PlayerToken::mint(Locale::French);
    channelAuthSeat($current, $token);
    $former = PlayerToken::mint(Locale::English);
    channelAuthSeat($archived, $former);

    channelAuthRequest($this, channelAuthPresence($current), $token)->assertOk();
    channelAuthRequest($this, channelAuthPresence($archived), $token)->assertForbidden();
    channelAuthRequest($this, channelAuthPresence($current), $former)->assertForbidden();
    channelAuthRequest($this, channelAuthPresence($archived), $former)->assertForbidden();
});
