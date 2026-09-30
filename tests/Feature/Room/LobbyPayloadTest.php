<?php

use App\Enums\Locale;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Player;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\RoomSettings;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Charge de la page du salon — spec 50 § 2.6, § 8.1 et § 12.5 ; 10 § 1.1
| (lot L50-4)
|--------------------------------------------------------------------------
|
| Ce que la page `game/lobby` envoie au navigateur, chargement complet (le
| document HTML entier, `data-page` compris), visite Inertia et
| rechargement partiel : jamais le `player_token`, ni son identifiant, ni
| son empreinte, d'aucun siège ; aucun identifiant interne de salon, de
| siège ni de thème. Les sièges voyagent par `public_id`, les thèmes par
| clé, le salon par code (règle 3, E10-11).
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
    PoolFixtures::fakeFramesDisk();
});

/**
 * Les trois formes de réponse de la page, sous le jeton donné : chargement
 * complet (document HTML), visite Inertia sous l'onglet actif, rechargement
 * partiel des réglages et des presets.
 *
 * @return array<string, string> forme → corps de la réponse
 */
function lobbyPayloadBodies(TestCase $test, Room $room, Player $seat): array
{
    $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
    $bodies = [];

    Cache::flush();
    $full = $test->get(route('room.show', $room))->assertOk();
    $bodies['chargement complet'] = (string) $full->getContent();

    $tab = (string) $seat->refresh()->active_seat_token;
    $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, EnsureActiveSeat::HEADER => $tab];

    Cache::flush();
    $bodies['visite Inertia'] = (string) $test->get(route('room.show', $room), $inertia)->assertOk()->getContent();

    Cache::flush();
    $bodies['rechargement partiel'] = (string) $test->get(route('room.show', $room), [
        ...$inertia,
        'X-Inertia-Partial-Component' => 'game/lobby',
        'X-Inertia-Partial-Data' => 'settings,presets',
    ])->assertOk()->getContent();

    return $bodies;
}

/**
 * Les props PROPRES de la page (`LobbyPageProps`), sans les props partagées
 * de `HandleInertiaRequests`, d'une réponse Inertia (JSON) ou d'un document
 * HTML (`data-page`).
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function lobbyPayloadProps(TestResponse $response): array
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
 * Toutes les clés d'une structure, à toute profondeur.
 *
 * @param  array<array-key, mixed>  $value
 * @return list<string>
 */
function lobbyPayloadKeys(array $value): array
{
    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        if (is_array($item)) {
            array_push($keys, ...lobbyPayloadKeys($item));
        }
    }

    return $keys;
}

it("n'envoie jamais au client le player_token, son tid ni son hash", function (): void {
    $token = PlayerToken::mint(Locale::French, SeatEntry::avatar(4));
    [$room, $host] = LobbyWrites::hostedRoom($token);

    // Les autres sièges du salon, dont un parti et un expulsé : leurs jetons
    // ne sortent pas davantage.
    $others = [];

    foreach ([Player::factory(), Player::factory()->left(), Player::factory()->kicked()] as $factory) {
        $other = PlayerToken::mint(Locale::English);
        $factory->for($room)->create(['player_token_hash' => $other->hash()]);
        $others[] = $other;
    }

    LobbyWrites::actAs($this, $token);

    foreach (lobbyPayloadBodies($this, $room, $host) as $label => $body) {
        foreach ([$token, ...$others] as $index => $each) {
            $claims = $each->toClaims();

            expect(str_contains($body, $each->hash()))->toBeFalse("{$label} : hash du jeton {$index}")
                ->and(str_contains($body, $claims['tid']))->toBeFalse("{$label} : tid du jeton {$index}")
                ->and(str_contains($body, json_encode($claims, JSON_THROW_ON_ERROR)))->toBeFalse("{$label} : jeton {$index}");
        }

        // Aucune clé qui nommerait le jeton, ni le nom du cookie.
        expect(str_contains($body, PlayerTokenCookie::NAME))->toBeFalse("{$label} : nom du cookie")
            ->and(str_contains($body, '"tid"'))->toBeFalse("{$label} : clé tid");
    }

    // Le jeton d'onglet des AUTRES sièges ne sort jamais : seul le sien, en
    // prop `seatToken`.
    $foreignTab = 'tab-'.str_repeat('x', 20);
    Player::query()->where('room_id', $room->id)->whereKeyNot($host->id)->update(['active_seat_token' => $foreignTab]);

    foreach (lobbyPayloadBodies($this, $room, $host) as $label => $body) {
        expect(str_contains($body, $foreignTab))->toBeFalse("{$label} : jeton d'onglet d'un autre siège");
    }
});

it("n'envoie aucun identifiant interne de salon, de siège ni de thème dans les props du lobby", function (): void {
    PoolFixtures::movies(2);
    $published = Theme::factory()->published()->create();
    $retired = Theme::factory()->unpublished()->create();
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token, RoomSettings::fromInput([
        'themeIds' => [$published->id, $retired->id],
    ]));
    Player::factory()->for($room)->withNickname('Zoé')->create();
    Player::factory()->for($room)->left()->create();

    LobbyWrites::actAs($this, $token);

    $props = lobbyPayloadProps($this->get(route('room.show', $room))->assertOk());

    // Aucune clé d'identifiant, à toute profondeur, dans toute la page :
    // `publicId` (siège) et `gameRef` (partie) sont des références opaques,
    // jamais des identifiants de base.
    $keys = array_values(array_unique(lobbyPayloadKeys($props)));
    $identifiers = array_values(array_filter(
        $keys,
        static fn (string $key): bool => preg_match('/^id$|_id$|Ids?$/', $key) === 1 && ! in_array($key, ['publicId', 'hostPublicId', 'previousHostPublicId'], true),
    ));

    expect($identifiers)->toBe([])
        ->and($keys)->not->toContain('themeIds', 'room_code_active', 'player_token_hash', 'active_seat_token', 'nickname_normalized', 'settings_version', 'draw_seed');

    // Les thèmes voyagent par clé : la sélection publiée par sa clé, la
    // retirée omise (et signalée par le rapport de vivier).
    expect($props['settings']['settings']['themeKeys'])->toBe([$published->key])
        ->and($props['settings']['pool']['themesPruned'])->toBeTrue();

    // Aucun identifiant de base en valeur, là où une clé en attendrait un :
    // ni celui du salon, ni ceux des sièges, ni ceux des thèmes, sous leur
    // forme de chaîne JSON d'identifiant.
    $json = json_encode($props, JSON_THROW_ON_ERROR);

    foreach (Player::query()->where('room_id', $room->id)->get() as $seat) {
        expect($json)->toContain('"'.$seat->public_id.'"')
            ->and($json)->not->toMatch('/"(?:id|playerId|seatId|hostId)"\s*:\s*'.$seat->id.'\b/');
    }

    expect($json)->not->toMatch('/"(?:id|roomId)"\s*:\s*'.$room->id.'\b/')
        ->and($json)->not->toMatch('/"themeIds"/')
        ->and($json)->not->toContain('"'.$retired->key.'"');
});
