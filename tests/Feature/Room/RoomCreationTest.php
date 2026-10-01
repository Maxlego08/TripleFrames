<?php

use App\Avatars\AvatarPresetCatalog;
use App\Enums\AvatarKind;
use App\Enums\Locale;
use App\Enums\PlayerConnectionState;
use App\Enums\RoomStatus;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;
use App\Settings\RoomSettings;
use App\Support\Identity\NicknameNormalizer;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Room\RoomCode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Création du salon — spec 50 § 6.1 à § 6.4 (lot L50-3b)
|--------------------------------------------------------------------------
|
| « Créer un salon » crée le salon TOUT DE SUITE, aux réglages par défaut,
| prend le siège du créateur et le nomme hôte par l'action de transfert,
| dans une seule transaction. Le `player_token` n'est frappé que par ce
| geste, jamais par l'affichage du formulaire (C4 I4.1).
|
*/

beforeEach(function (): void {
    SeatEntry::isolateCookies();
    $this->withoutVite();
});

/**
 * La ligne `room` brute : les cinq projections, la version et la charge.
 *
 * @return array{projection: array<string, mixed>, version: int, settings: array<string, mixed>}
 */
function roomCreationRawRow(Room $room): array
{
    $row = (array) DB::table('room')->where('id', $room->id)->first();
    $settings = json_decode((string) $row['settings'], true, flags: JSON_THROW_ON_ERROR);

    return [
        'projection' => [
            'capacity' => (int) $row['capacity'],
            'framesPerRound' => (int) $row['frames_per_round'],
            'roundsCount' => (int) $row['rounds_count'],
            'inputDifficulty' => (string) $row['input_difficulty'],
            'allowLateJoin' => (bool) $row['allow_late_join'],
        ],
        'version' => (int) $row['settings_version'],
        'settings' => is_array($settings) ? $settings : [],
    ];
}

it("crée le salon avec les réglages par défaut et fait du créateur l'hôte", function (): void {
    $response = $this->post(route('room.store'), SeatEntry::form('  Zoé   la  Brave ', SeatEntry::avatar(5)));

    $room = Room::query()->sole();
    $seat = Player::query()->sole();

    $response->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect(route('room.show', $room))
        ->assertSessionHasNoErrors();

    // Le salon : réglages par défaut, au lobby, code actif bien formé.
    expect($room->settings->equals(RoomSettings::defaults()))->toBeTrue()
        ->and($room->status)->toBe(RoomStatus::Lobby)
        ->and(RoomCode::isWellFormed($room->room_code))->toBeTrue()
        ->and($room->room_code)->toBe(RoomCode::normalize($room->room_code))
        ->and($room->room_code_active)->toBe($room->room_code)
        ->and($room->launched_at)->toBeNull()
        ->and($room->archived_at)->toBeNull()
        ->and($room->last_activity_at)->not->toBeNull();

    // Le créateur : le seul siège, l'hôte, sous le pseudo canonique saisi.
    $token = SeatEntry::tokenFrom($response);

    expect($room->host_player_id)->toBe($seat->id)
        ->and($seat->room_id)->toBe($room->id)
        ->and($seat->nickname)->toBe('Zoé la Brave')
        ->and($seat->nickname_normalized)->toBe(NicknameNormalizer::normalize('Zoé la Brave'))
        ->and($seat->avatar_kind)->toBe(AvatarKind::Preset)
        ->and($seat->avatar_preset)->toBe(SeatEntry::avatar(5))
        ->and($seat->connection_state)->toBe(PlayerConnectionState::Connected)
        ->and($seat->player_token_hash)->toBe($token->hash())
        ->and($seat->user_id)->toBeNull()
        ->and($seat->kicked_at)->toBeNull()
        ->and($seat->left_at)->toBeNull();

    // Revenu par le lien, il tient le siège hôte : le lobby, jamais l'entrée.
    LobbyWrites::actAs($this, $token);

    $this->get(route('room.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('game/lobby', shouldExist: false)
            ->where('room.code', $room->room_code)
            ->where('state.self.publicId', $seat->public_id)
            ->where('state.self.isHost', true));

    // Un compte connecté crée comme un invité, mais son siège est rattaché au
    // compte (C4 I4.10, amendé par D49 du 01/10) ; le pseudo saisi, jamais le
    // nom du compte (I5.11).
    $user = User::factory()->create(['name' => 'Nom du compte']);

    $this->flushSession();
    $this->actingAs($user)
        ->post(route('room.store'), SeatEntry::form('Invitée'))
        ->assertStatus(Response::HTTP_SEE_OTHER);

    $accountSeat = Player::query()->where('nickname', 'Invitée')->sole();

    expect($accountSeat->user_id)->toBe($user->id)
        ->and(Room::query()->whereKey($accountSeat->room_id)->value('host_player_id'))->toBe($accountSeat->id);
});

it("pose l'hôte par l'action de transfert dans la transaction de création", function (): void {
    $base = DB::transactionLevel();
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update)\b/i', $query->sql) === 1) {
            $writes[] = ['sql' => $query->sql, 'level' => $query->connection->transactionLevel()];
        }
    });

    $this->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);

    $step = static function (string $pattern) use ($writes): array {
        $matches = array_keys(array_filter($writes, static fn (array $write): bool => preg_match($pattern, $write['sql']) === 1));

        expect($matches)->toHaveCount(1, "écriture attendue une seule fois : {$pattern}");

        return ['index' => $matches[0], 'level' => $writes[$matches[0]]['level']];
    };

    $roomInsert = $step('/^\s*insert into "room"/i');
    $seatInsert = $step('/^\s*insert into "player"/i');
    $hostWrite = $step('/^\s*update "room" set "host_player_id"/i');

    // Une seule transaction de création : le salon, puis le siège (prise de
    // siège imbriquée), puis l'hôte, posé par la création elle-même, à son
    // niveau — jamais par la réparation S8 de la prise de siège.
    expect($roomInsert['level'])->toBe($base + 1)
        ->and($seatInsert['level'])->toBe($base + 2)
        ->and($hostWrite['level'])->toBe($base + 1)
        ->and($roomInsert['index'])->toBeLessThan($seatInsert['index'])
        ->and($seatInsert['index'])->toBeLessThan($hostWrite['index']);

    // Écrivain unique : dans `app/`, seule l'action de transfert écrit
    // `host_player_id` (10 § 6.2, E10-34bis).
    $writers = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (preg_match("/(?:update|forceFill|fill|insert|create)\(\s*\[[^\]]*'host_player_id'|->host_player_id\s*=(?!=)/s", $source) === 1) {
            $writers[] = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));
        }
    }

    expect($writers)->toBe(['Actions/Room/TransferHost.php']);

    // Même transaction, preuve par l'échec : si la pose de l'hôte échoue,
    // le salon et le siège disparaissent avec elle — rien n'est créé.
    DB::listen(function (QueryExecuted $query): void {
        if (preg_match('/^\s*update "room" set "host_player_id"/i', $query->sql) === 1) {
            throw new RuntimeException('Pose de l’hôte refusée par le test.');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('room.store'), SeatEntry::form('Autre')))
        ->toThrow(RuntimeException::class, 'Pose de l’hôte refusée par le test.');

    expect(Room::query()->count())->toBe(1)
        ->and(Player::query()->count())->toBe(1);
});

it("n'émet qu'un seul host.changed à la création", function (): void {
    $recorder = RecordingBroadcaster::install();

    $this->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);

    $room = Room::query()->sole();
    $seat = Player::query()->sole();

    expect(array_column($recorder->sent, 'event'))->toBe(['seat.joined', 'host.changed']);

    [$joined, $changed] = $recorder->sent;

    // `host.changed` : le créateur, sans hôte précédent, au canal du salon.
    expect($changed['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($changed['payload'])->toMatchArray([
            'gameRef' => null,
            'hostPublicId' => $seat->public_id,
            'previousHostPublicId' => null,
        ]);

    // `seat.joined` décrit l'état validé : le créateur y est déjà l'hôte.
    expect($joined['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and($joined['payload']['gameRef'])->toBeNull()
        ->and($joined['payload']['seat'])->toMatchArray([
            'publicId' => $seat->public_id,
            'nickname' => SeatEntry::NICKNAME,
            'isHost' => true,
            'connection' => PlayerConnectionState::Connected->value,
            'kicked' => false,
            'firstRoundNumber' => null,
        ]);

    // Une création refusée à la validation n'émet rien.
    $this->post(route('room.store'), SeatEntry::form('x'));

    expect($recorder->sent)->toHaveCount(2);
});

it("frappe un player_token à la création et jamais à l'affichage du formulaire", function (): void {
    // L'affichage : aucun jeton frappé, présélection sans préférence.
    $form = $this->get(route('room.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('room/create')
            ->where('avatars.options', AvatarPresetCatalog::options())
            ->where('avatars.taken', [])
            ->where('avatars.suggested', SeatEntry::avatar(1))
            ->where('nickname', ['min' => NicknameNormalizer::MIN_LENGTH, 'max' => NicknameNormalizer::MAX_LENGTH])
            ->etc());

    expect(SeatEntry::tokenCookies($form))->toBe([]);

    // Un envoi refusé à la validation ne frappe rien non plus.
    $refused = $this->post(route('room.store'), SeatEntry::form('x'));

    $refused->assertSessionHasErrors('nickname');
    expect(SeatEntry::tokenCookies($refused))->toBe([])
        ->and(Room::query()->count())->toBe(0);

    // La création frappe un seul jeton, dont le SHA-256 du tid est le siège,
    // revendiquant la langue de la requête et l'avatar choisi.
    $created = $this->withUnencryptedCookie('locale', Locale::French->value)
        ->post(route('room.store'), SeatEntry::form(avatar: SeatEntry::avatar(7)));

    $token = SeatEntry::tokenFrom($created);

    expect(Player::query()->sole()->player_token_hash)->toBe($token->hash())
        ->and($token->locale)->toBe(Locale::French)
        ->and($token->avatar)->toBe(SeatEntry::avatar(7));

    // Le formulaire suivant LIT ce jeton pour présélectionner son avatar,
    // sans en frapper ni en reposer aucun.
    LobbyWrites::actAs($this, $token);

    $again = $this->get(route('room.create'))
        ->assertInertia(fn (Assert $page) => $page->where('avatars.suggested', SeatEntry::avatar(7))->etc());

    expect(SeatEntry::tokenCookies($again))->toBe([]);

    // Une seconde création sous ce jeton garde le même tid : un jeton = un
    // siège par salon, jamais un jeton par salon.
    $second = $this->post(route('room.store'), SeatEntry::form('Autre', SeatEntry::avatar(8)));
    $resigned = SeatEntry::tokenFrom($second);

    expect($resigned->sameIdentityAs($token))->toBeTrue()
        ->and($resigned->avatar)->toBe(SeatEntry::avatar(8))
        ->and(Player::query()->where('player_token_hash', $token->hash())->count())->toBe(2);
});

it('écrit les cinq projections égales au value object dès la création', function (): void {
    $this->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);

    $room = Room::query()->sole();
    $raw = roomCreationRawRow($room);
    $defaults = RoomSettings::defaults();

    expect($raw['projection'])->toBe([
        'capacity' => $raw['settings']['capacity'],
        'framesPerRound' => $raw['settings']['framesPerRound'],
        'roundsCount' => $raw['settings']['roundsCount'],
        'inputDifficulty' => $raw['settings']['inputDifficulty'],
        'allowLateJoin' => $raw['settings']['allowLateJoin'],
    ])
        ->and($raw['projection'])->toBe([
            'capacity' => $defaults->capacity,
            'framesPerRound' => $defaults->framesPerRound,
            'roundsCount' => $defaults->roundsCount,
            'inputDifficulty' => $defaults->inputDifficulty->value,
            'allowLateJoin' => $defaults->allowLateJoin,
        ])
        ->and($raw['version'])->toBe(RoomSettings::VERSION)
        ->and(array_keys($raw['settings']))->toBe(RoomSettings::FIELDS)
        ->and(RoomSettings::fromStorage($raw['settings'], $raw['version'])->equals($defaults))->toBeTrue();
});

it('limite le nombre de salons créés par adresse', function (): void {
    config()->set('game.room.creates_per_hour', 2);

    $from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip]);

    $from('203.0.113.7')->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);
    $from('203.0.113.7')->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);

    // Au-delà, 429 : aucun salon, aucun jeton frappé — et un autre jeton ne
    // contourne rien, la clé est l'adresse.
    LobbyWrites::actAs($this, PlayerToken::mint(Locale::English));

    $refused = $from('203.0.113.7')->post(route('room.store'), SeatEntry::form());

    $refused->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
    expect(SeatEntry::tokenCookies($refused))->toBe([])
        ->and(Room::query()->count())->toBe(2);

    // Une autre adresse garde son propre débit.
    $from('198.51.100.4')->post(route('room.store'), SeatEntry::form())->assertStatus(Response::HTTP_SEE_OTHER);

    expect(Room::query()->count())->toBe(3);

    // La route porte bien ce limiteur nommé, et lui seul.
    expect(app('router')->getRoutes()->getByName('room.store')?->gatherMiddleware())
        ->toContain('throttle:room-create')
        ->not->toContain('throttle:game-write');
});
