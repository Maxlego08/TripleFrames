<?php

use App\Actions\Game\ClaimSeatTab;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Http\Middleware\EnsureActiveSeat;
use App\Models\Game;
use App\Models\Player;
use App\Models\Room;
use App\Support\Game\GameStateBuilder;
use App\Support\Identity\PlayerIdentity;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use App\Support\Realtime\ChannelNames;
use App\Support\Realtime\WireTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\I18n\FrontSource;
use Tests\Support\Scoring\ScoringTypes;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Paquet de resynchronisation — spec 60 § 12, contrat C7 § 3
|--------------------------------------------------------------------------
|
| `GameStatePacket`, une seule forme sortie d'un seul constructeur : prop
| `state` des pages `game/*`, réponse de `room.state` et de `solo.state`.
|
| Lot L60-4 : la branche SANS partie (lobby) — canaux du salon, sièges dans
| l'ordre de 50, `self` et `seatActive`, aucune manche, classement vide — et
| `room.state` qui la sert. Les douze intitulés de partie (manche portée,
| bornes des URL, `nextTransitionAt`, `maxAnswerLength` du snapshot, QCM
| rejoué, rattrapage) arrivent avec la branche de partie, lot L60-12.
|
*/

/**
 * `room.state` sous ce jeton, en présentant éventuellement un jeton d'onglet.
 *
 * @return TestResponse<Response>
 */
function resyncPacketFetch(TestCase $test, Room $room, ?PlayerToken $token, ?string $seatToken = null): TestResponse
{
    (new ReflectionProperty($test, 'defaultCookies'))->setValue($test, []);

    if ($token !== null) {
        $test->withCredentials()
            ->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR));
    }

    return $test->getJson(
        route('room.state', ['room' => $room->room_code]),
        $seatToken === null ? [] : [EnsureActiveSeat::HEADER => $seatToken],
    );
}

it("le paquet d'un lobby porte les canaux du salon et aucune manche", function (): void {
    Date::setTestNow(CarbonImmutable::parse('2026-09-23 16:05:13.004999', 'Europe/Paris'));

    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);

    // Quatre sièges, arrivés dans le désordre de leurs identifiants : l'hôte
    // (arrivé en second), le demandeur, un siège parti et un siège expulsé —
    // tous restent dans la liste, chacun avec son état.
    $early = Player::factory()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(9)]);
    $seat = Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
        'joined_at' => Date::now()->subMinutes(5),
    ]);
    $host = Player::factory()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(7)]);
    $left = Player::factory()->left()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinutes(3)]);
    $kicked = Player::factory()->kicked()->create(['room_id' => $room->id, 'joined_at' => Date::now()->subMinute()]);
    $room->forceFill(['host_player_id' => $host->id])->save();

    // Un siège d'un autre salon n'y figure jamais.
    Player::factory()->create();

    $packet = resyncPacketFetch($this, $room, $token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json();

    // L'enveloppe, puis les champs de GameStatePacket dans l'ordre du type.
    expect(array_keys($packet))->toBe([
        'v', 'serverNow', 'gameRef', 'mode', 'channels', 'status', 'roundsCount', 'roundsCompleted',
        'framesPerRound', 'inputDifficulty', 'maxAnswerLength', 'seats', 'pause', 'round', 'self',
        'leaderboard', 'podium', 'nextTransitionAt',
    ])
        ->and($packet['v'])->toBe(1)
        ->and($packet['serverNow'])->toBe('2026-09-23T14:05:13.004Z')
        ->and($packet['serverNow'])->toMatch(WireTime::PATTERN)
        ->and($packet['gameRef'])->toBeNull()
        ->and($packet['mode'])->toBe('multiplayer');

    // Les deux canaux du salon, présents au lobby (écart (a) du § 22 bis) :
    // clé HMAC du salon, jamais le room_code ni room.id.
    expect($packet['channels'])->toBe([
        'room' => ChannelNames::room($room),
        'seat' => ChannelNames::seat($seat),
    ])
        ->and($packet['channels']['room'])->not->toContain($room->room_code)
        ->and($packet['channels']['room'])->toMatch('/^room\.[0-9a-f]{32}$/');

    // Aucune manche, aucune partie : tout ce qui décrit une partie est nul.
    foreach (['status', 'roundsCount', 'roundsCompleted', 'framesPerRound', 'inputDifficulty', 'maxAnswerLength', 'pause', 'round', 'podium', 'nextTransitionAt'] as $field) {
        expect($packet[$field])->toBeNull("[{$field}] devrait être nul au lobby.");
    }

    expect($packet['leaderboard'])->toBe(['scoreless' => false, 'roundNumber' => null, 'rows' => []]);

    // Les sièges du salon, par `joined_at` croissant (50 § 8.1), identité
    // courante (C5) et état de siège.
    expect(array_column($packet['seats'], 'publicId'))
        ->toBe([$early->public_id, $host->public_id, $seat->public_id, $left->public_id, $kicked->public_id]);

    foreach ($packet['seats'] as $view) {
        $player = Player::query()->where('public_id', $view['publicId'])->firstOrFail();

        expect($view)->toBe([
            ...PlayerIdentity::fromSeat($player)->toArray(),
            'isHost' => $player->id === $host->id,
            'connection' => $player->connection_state->value,
            'kicked' => $player->kicked_at !== null,
            'firstRoundNumber' => null,
        ]);
    }

    expect(array_column($packet['seats'], 'connection', 'publicId'))->toMatchArray([
        $left->public_id => PlayerConnectionState::Left->value,
        $kicked->public_id => PlayerConnectionState::Left->value,
    ])
        ->and(array_column($packet['seats'], 'kicked', 'publicId'))->toBe([
            $early->public_id => false,
            $host->public_id => false,
            $seat->public_id => false,
            $left->public_id => false,
            $kicked->public_id => true,
        ]);

    // Soi : aucune participation, aucun score, aucune saisie.
    expect($packet['self'])->toBe([
        'publicId' => $seat->public_id,
        'seatActive' => false,
        'isHost' => false,
        'member' => false,
        'participates' => false,
        'input' => null,
        'ownScore' => 0,
    ]);

    // L'hôte se sait hôte.
    $hostToken = PlayerToken::mint(Locale::English);
    $host->forceFill(['player_token_hash' => $hostToken->hash()])->save();

    expect(resyncPacketFetch($this, $room, $hostToken)->assertOk()->json('self.isHost'))->toBeTrue();

    // Une partie close d'un salon REVENU au lobby (« Rejouer ») ne ressuscite
    // aucune manche : le paquet reste celui du lobby.
    Game::factory()->forRoom($room)->completed()->create();

    expect(resyncPacketFetch($this, $room, $token)->assertOk()->json('round'))->toBeNull();

    // Sans siège tenu par le jeton, siège expulsé, ou salon archivé : 403.
    resyncPacketFetch($this, $room, null)->assertForbidden();
    resyncPacketFetch($this, $room, PlayerToken::mint(Locale::French))->assertForbidden();

    $kickedToken = PlayerToken::mint(Locale::French);
    $kicked->forceFill(['player_token_hash' => $kickedToken->hash()])->save();
    resyncPacketFetch($this, $room, $kickedToken)->assertForbidden();

    $room->forceFill(['room_code_active' => null, 'archived_at' => Date::now(), 'status' => 'archived'])->save();
    resyncPacketFetch($this, $room, $token)->assertForbidden();

    // En solo, sans partie : aucun canal, et soi pour seul siège.
    $solo = Player::factory()->solo()->create();
    $soloPacket = GameStateBuilder::build(null, $solo, Date::now()->toImmutable(), null);

    expect($soloPacket['mode'])->toBe('solo')
        ->and($soloPacket['channels'])->toBeNull()
        ->and(array_column($soloPacket['seats'], 'publicId'))->toBe([$solo->public_id])
        ->and($soloPacket['seats'][0]['isHost'])->toBeFalse()
        ->and($soloPacket['round'])->toBeNull();
});

it("l'onglet supplanté reçoit seatActive faux", function (): void {
    $room = Room::factory()->create();
    $token = PlayerToken::mint(Locale::French);
    $seat = Player::factory()->create([
        'room_id' => $room->id,
        'player_token_hash' => $token->hash(),
        'active_seat_token' => null,
    ]);

    $claim = app(ClaimSeatTab::class);
    $first = $claim->handle($seat, null);
    $second = $claim->handle(Player::query()->findOrFail($seat->id), null);

    expect($second)->not->toBe($first);

    // L'onglet supplanté lit `seatActive: false` — `room.state` n'est pas sous
    // `seat.active` : c'est ainsi qu'il l'apprend, puis passe en lecture seule.
    resyncPacketFetch($this, $room, $token, $first)->assertOk()->assertJsonPath('self.seatActive', false);

    // L'onglet qui a pris la main, lui, le tient ; sans en-tête, personne.
    resyncPacketFetch($this, $room, $token, $second)->assertOk()->assertJsonPath('self.seatActive', true);
    resyncPacketFetch($this, $room, $token)->assertOk()->assertJsonPath('self.seatActive', false);

    // Le jeton d'onglet ne voyage jamais dans le paquet.
    $json = (string) resyncPacketFetch($this, $room, $token, $second)->getContent();

    expect($json)->not->toContain($first)
        ->and($json)->not->toContain($second);
});

it('la forme du paquet sans partie suit GameStatePacket, SelfState et SeatView de game-wire.ts', function (): void {
    $source = FrontSource::withoutComments((string) file_get_contents(resource_path('js/types/game-wire.ts')));
    $declarations = ScoringTypes::declarations($source);

    foreach (['SeatView', 'RoundTimeline', 'RoundState', 'SelfState', 'GameStatePacket', 'RealtimeConfig'] as $name) {
        expect($declarations)->toHaveKey($name);
    }

    // Les types des autres contrats sont importés, jamais redéclarés (R-27).
    foreach (['PlayerIdentity', 'SeatInputView', 'TierWindow', 'RoundFinder', 'Leaderboard', 'Podium'] as $imported) {
        expect($declarations)->not->toHaveKey($imported);
    }

    expect($source)->toContain("from '@/types/player'")
        ->toContain("from '@/types/answers'")
        ->toContain("from '@/types/scoring'");

    $fields = static fn (string $name): array => ScoringTypes::objectFields($declarations[$name])[0];

    $room = Room::factory()->create();
    $seat = Player::factory()->create(['room_id' => $room->id]);
    $packet = GameStateBuilder::build(null, $seat, Date::now()->toImmutable(), null);

    expect(array_keys($packet))->toBe([...$fields('WireEnvelope'), ...$fields('GameStatePacket')])
        ->and(array_keys($packet['self']))->toBe($fields('SelfState'))
        ->and(array_keys($packet['seats'][0]))->toBe([
            ...array_keys(PlayerIdentity::fromSeat($seat)->toArray()),
            ...$fields('SeatView'),
        ]);

    // `RoundState` prolonge `RoundTimeline` ; les champs de `RoundTimeline`
    // sont ceux de sa charge serveur (60 § 11.5).
    expect($declarations['RoundState'])->toContain('extends RoundTimeline')
        ->and($fields('RoundTimeline'))->toBe([
            'sequenceIndex', 'roundNumber', 'roundsCount', 'startsAt', 'durationMs', 'tiers', 'choicesAtTierIndex',
        ]);
});
