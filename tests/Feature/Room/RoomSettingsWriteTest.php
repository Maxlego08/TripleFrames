<?php

use App\Actions\Room\ApplyRoomPreset;
use App\Actions\Room\UpdateRoomSettings;
use App\Actions\Room\WriteRoomSettings;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Enums\SettingPresetKey;
use App\Events\Game\SettingsChanged;
use App\Jobs\Game\BroadcastLobbyState;
use App\Models\Player;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Identity\PlayerToken;
use App\Support\Realtime\ChannelNames;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Realtime\RecordingBroadcaster;
use Tests\Support\Room\LobbyWrites;

/*
|--------------------------------------------------------------------------
| Écriture des réglages — spec 50 § 2.5, § 2.6, § 10, contrat C0 § 4
|--------------------------------------------------------------------------
|
| Un seul écrivain (`WriteRoomSettings`) pose le value object ET ses cinq
| projections, sous le verrou du salon et au lobby seulement ; les actions de
| l'hôte (`UpdateRoomSettings`, `ApplyRoomPreset`) relisent l'autorité et le
| statut sous ce verrou, composent l'entrée, la valident, puis gardent la
| capacité contre l'effectif présent. L'état diffusé au salon est rendu en
| données par `RoomSettingsPresenter::state()`.
|
| Second temps du lot (I-1) : après validation de sa transaction, chaque
| écriture dispatche `BroadcastLobbyState` (spec 50 § 8.3), coalescé par
| salon sur la file `game` ; le rapport de changements revient à l'auteur
| seul, en flash, par les routes sous `seat.active`. Ces trois tests-là
| jouent la vraie file `database`, dépilée comme un worker : une file `sync`
| exécuterait chaque dispatch.
|
*/

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-09-26 14:05:13.042');
    $this->travelTo($this->now);
});

/**
 * Un salon au lobby et son hôte, désigné par la référence souple.
 *
 * @return array{0: Room, 1: Player}
 */
function writeRoomWithHost(?RoomSettings $settings = null): array
{
    $room = Room::factory()->withSettings($settings ?? RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

/**
 * @param  array<string, mixed>  $posted
 */
function writeUpdate(Room $room, Player $seat, array $posted): SettingsWriteOutcome
{
    return app(UpdateRoomSettings::class)->handle($room, $seat, $posted);
}

function writePreset(Room $room, Player $seat, SettingPresetKey $preset): SettingsWriteOutcome
{
    return app(ApplyRoomPreset::class)->handle($room, $seat, $preset);
}

/**
 * La ligne `room` telle qu'elle est en base, sans cast : les cinq projections
 * brutes et la charge décodée.
 *
 * @return array{projection: array<string, mixed>, settings: array<string, mixed>, version: int, lastActivityAt: string}
 */
function writeRawRow(Room $room): array
{
    $row = (array) DB::table('room')->where('id', $room->id)->first();
    $settings = json_decode((string) $row['settings'], true);

    return [
        'projection' => [
            'capacity' => (int) $row['capacity'],
            'framesPerRound' => (int) $row['frames_per_round'],
            'roundsCount' => (int) $row['rounds_count'],
            'inputDifficulty' => (string) $row['input_difficulty'],
            'allowLateJoin' => (bool) $row['allow_late_join'],
        ],
        'settings' => is_array($settings) ? $settings : [],
        'version' => (int) $row['settings_version'],
        'lastActivityAt' => (string) $row['last_activity_at'],
    ];
}

/**
 * `projection == value object` sur la ligne en base (10 § 6.2).
 */
function writeAssertProjection(Room $room): void
{
    $raw = writeRawRow($room);

    expect($raw['projection'])->toBe([
        'capacity' => $raw['settings']['capacity'],
        'framesPerRound' => $raw['settings']['framesPerRound'],
        'roundsCount' => $raw['settings']['roundsCount'],
        'inputDifficulty' => $raw['settings']['inputDifficulty'],
        'allowLateJoin' => $raw['settings']['allowLateJoin'],
    ])->and($raw['version'])->toBe(RoomSettings::VERSION)
        ->and(array_keys($raw['settings']))->toBe(RoomSettings::FIELDS);

    $fresh = Room::query()->findOrFail($room->id);

    expect($fresh->capacity)->toBe($fresh->settings->capacity)
        ->and($fresh->frames_per_round)->toBe($fresh->settings->framesPerRound)
        ->and($fresh->rounds_count)->toBe($fresh->settings->roundsCount)
        ->and($fresh->input_difficulty)->toBe($fresh->settings->inputDifficulty)
        ->and($fresh->allow_late_join)->toBe($fresh->settings->allowLateJoin);
}

/**
 * Les erreurs d'une `ValidationException` levée par `$attempt`.
 *
 * @return array<string, list<string>>
 */
function writeErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> */
        return $exception->errors();
    }

    throw new LogicException('Une ValidationException était attendue.');
}

/**
 * `$count` sièges de plus tenus dans le salon (ni partis, ni expulsés).
 */
function writeSeats(Room $room, int $count): void
{
    Player::factory()->for($room)->count($count)->create();
}

it('garde les cinq projections égales au value object après chaque écriture', function (): void {
    // Création : le modèle n'est pas encore persisté et naît au lobby.
    $created = new Room;
    $created->room_code = 'K7M2PQ';
    $created->room_code_active = 'K7M2PQ';

    DB::transaction(fn () => app(WriteRoomSettings::class)->handle($created, RoomSettings::defaults(), $this->now));

    expect($created->exists)->toBeTrue()
        ->and($created->status)->toBe(RoomStatus::Lobby);
    writeAssertProjection($created);

    [$room, $host] = writeRoomWithHost();

    $posts = [
        ['framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND],
        ['roundsCount' => Bounds::MAX_ROUNDS_COUNT],
        ['inputDifficulty' => InputDifficulty::Easy->value],
        ['capacity' => Bounds::MIN_CAPACITY],
        ['allowLateJoin' => true],
        [
            'framesPerRound' => Bounds::MAX_FRAMES_PER_ROUND,
            RoomSettings::INPUT_ROUND_DURATION => Bounds::minRoundDuration(Bounds::MAX_FRAMES_PER_ROUND),
        ],
        ['capacity' => PlatformLimits::roomSeats(), 'allowLateJoin' => false, 'inputDifficulty' => InputDifficulty::Expert->value],
    ];

    foreach ($posts as $index => $posted) {
        $this->travelTo($this->now->addSeconds($index + 1));

        $outcome = writeUpdate($room, $host, $posted);

        expect($outcome->isWritten())->toBeTrue();
        writeAssertProjection($room);

        $fresh = Room::query()->findOrFail($room->id);

        foreach ($posted as $field => $value) {
            if ($field !== RoomSettings::INPUT_ROUND_DURATION) {
                expect($fresh->settings->toPayload()[$field])->toBe($value);
            }
        }

        // Toute écriture de réglages est une source d'activité (spec 50 § 16.1).
        expect($fresh->last_activity_at->format('Y-m-d H:i:s'))
            ->toBe($this->now->addSeconds($index + 1)->format('Y-m-d H:i:s'));
    }

    // Des clés de thème postées : l'action lit les thèmes publiés et les traduit
    // en identifiants ; une clé dépubliée est refusée sous `themeKeys`.
    $theme = Theme::factory()->published()->create();
    $withdrawn = Theme::factory()->unpublished()->create();

    expect(writeUpdate($room, $host, ['themeKeys' => [$theme->key]])->isWritten())->toBeTrue()
        ->and(Room::query()->findOrFail($room->id)->settings->themeIds)->toBe([$theme->id]);
    writeAssertProjection($room);

    $before = writeRawRow($room);

    expect(array_keys(writeErrors(fn () => writeUpdate($room, $host, ['themeKeys' => [$withdrawn->key]]))))
        ->toBe(['themeKeys'])
        ->and(writeRawRow($room))->toBe($before);

    foreach (SettingPresetKey::cases() as $preset) {
        expect(writePreset($room, $host, $preset)->isWritten())->toBeTrue();
        writeAssertProjection($room);
    }
});

it('refuse toute écriture de réglages hors du statut lobby', function (): void {
    [$room, $host] = writeRoomWithHost();

    foreach ([RoomStatus::Playing, RoomStatus::Archived] as $status) {
        Room::query()->whereKey($room->id)->update(['status' => $status->value]);
        $before = writeRawRow($room);

        expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->refusal)->toBe(RoomRefusal::NotInLobby)
            ->and(writePreset($room, $host, SettingPresetKey::Fast)->refusal)->toBe(RoomRefusal::NotInLobby)
            ->and(writeRawRow($room))->toBe($before);

        // L'écrivain unique lève de lui-même hors du lobby : jamais une écriture silencieuse.
        $locked = Room::query()->findOrFail($room->id);

        expect(fn () => DB::transaction(
            fn () => app(WriteRoomSettings::class)->handle($locked, RoomSettings::defaults(), $this->now),
        ))->toThrow(LogicException::class);
    }

    Room::query()->whereKey($room->id)->update(['status' => RoomStatus::Lobby->value]);
    $lobby = Room::query()->findOrFail($room->id);
    $writer = app(WriteRoomSettings::class);

    // Depuis une version antérieure, ou avec une autre modification en cours
    // sur l'instance : autant d'erreurs de code.
    expect(fn () => DB::transaction(fn () => $writer->handle(
        $lobby,
        RoomSettings::fromStorage(RoomSettings::defaults()->toPayload(), RoomSettings::VERSION - 1),
        $this->now,
    )))->toThrow(LogicException::class, 'version');

    $lobby->host_player_id = null;

    expect(fn () => DB::transaction(fn () => $writer->handle($lobby, RoomSettings::defaults(), $this->now)))
        ->toThrow(LogicException::class, 'host_player_id');

    // L'hôte est relu sous le verrou : un autre siège, ou un siège d'un autre salon, est refusé.
    $guest = Player::factory()->for($room)->create();
    $elsewhere = Player::factory()->create();
    $before = writeRawRow($room);

    expect(writeUpdate($room, $guest, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->refusal)->toBe(RoomRefusal::NotHost)
        ->and(writePreset($room, $guest, SettingPresetKey::Fast)->refusal)->toBe(RoomRefusal::NotHost)
        ->and(writeUpdate($room, $elsewhere, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->refusal)->toBe(RoomRefusal::NotHost)
        ->and(writeRawRow($room))->toBe($before);

    // Le rôle transféré après la lecture du siège : c'est la ligne verrouillée qui fait foi.
    Room::query()->whereKey($room->id)->update(['host_player_id' => $guest->id]);

    expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->refusal)->toBe(RoomRefusal::NotHost)
        ->and(writeUpdate($room, $guest, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue();

    // Hors transaction : `RefreshDatabase` enveloppe chaque test dans une
    // transaction, dont on sort le temps de l'assertion avant de la rouvrir
    // pour le nettoyage de fin de test (patron d'`AdminActionTypeTest`). Dernier
    // geste du test : la sortie annule tout ce qu'il a écrit.
    $clean = Room::query()->findOrFail($room->id);
    $connection = DB::connection();
    $level = $connection->transactionLevel();

    for ($i = 0; $i < $level; $i++) {
        $connection->rollBack();
    }

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(fn () => $writer->handle($clean, RoomSettings::defaults(), $this->now))
            ->toThrow(LogicException::class, 'transaction');
    } finally {
        for ($i = 0; $i < $level; $i++) {
            $connection->beginTransaction();
        }
    }
});

it('refuse une capacité sous le nombre de sièges tenus', function (): void {
    app()->setLocale('fr');
    [$room, $host] = writeRoomWithHost();
    $holding = 4;

    writeSeats($room, $holding - 1);
    Player::factory()->for($room)->left()->count(2)->create();
    Player::factory()->for($room)->kicked()->create();

    // L'effectif du salon seulement : les sièges tenus ailleurs ne comptent pas.
    Player::factory()->for(Room::factory())->count(PlatformLimits::roomSeats())->create();

    $before = writeRawRow($room);
    $errors = writeErrors(fn () => writeUpdate($room, $host, ['capacity' => $holding - 1]));

    expect($errors)->toBe([
        'capacity' => [__('validation.room_settings.capacity_below_headcount', ['count' => $holding])],
    ])->and(writeRawRow($room))->toBe($before);

    // L'effectif présent seulement : les partis et l'expulsé ne comptent pas.
    expect(writeUpdate($room, $host, ['capacity' => $holding])->isWritten())->toBeTrue()
        ->and(Room::query()->findOrFail($room->id)->capacity)->toBe($holding);

    // Rien n'est écrit quand la garde refuse, même avec d'autres champs valides.
    $before = writeRawRow($room);

    expect(array_keys(writeErrors(fn () => writeUpdate($room, $host, [
        'capacity' => $holding - 1,
        'roundsCount' => Bounds::MIN_ROUNDS_COUNT,
    ]))))->toBe(['capacity'])
        ->and(writeRawRow($room))->toBe($before);
});

it('accepte une écriture qui ne baisse pas la capacité quand l\'effectif la dépasse', function (): void {
    $settings = RoomSettings::fromInput(['capacity' => Bounds::MIN_CAPACITY]);
    [$room, $host] = writeRoomWithHost($settings);
    $holding = Bounds::MIN_CAPACITY + 1;

    // Retour d'un siège parti : l'effectif dépasse la capacité (spec 50 § 7.3).
    writeSeats($room, $holding - 1);

    expect(Player::query()->where('room_id', $room->id)->holdingSeat()->count())->toBeGreaterThan($room->capacity);

    // Un champ que l'hôte n'a pas touché ne bloque pas l'écriture, remède de vivier compris.
    expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue()
        ->and(writeUpdate($room, $host, ['framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND])->isWritten())->toBeTrue()
        // La même capacité, reposée, n'est pas une baisse.
        ->and(writeUpdate($room, $host, ['capacity' => Bounds::MIN_CAPACITY])->isWritten())->toBeTrue()
        ->and(Room::query()->findOrFail($room->id)->capacity)->toBe(Bounds::MIN_CAPACITY);

    // Réglable à la hausse jusqu'à l'effectif ; seule une baisse sous l'effectif est refusée.
    expect(writeUpdate($room, $host, ['capacity' => $holding])->isWritten())->toBeTrue()
        ->and(array_keys(writeErrors(fn () => writeUpdate($room, $host, ['capacity' => Bounds::MIN_CAPACITY]))))
        ->toBe(['capacity']);

    // Un preset pose le plafond, que la garde ne refuse jamais.
    expect(writePreset($room, $host, SettingPresetKey::Discovery)->isWritten())->toBeTrue();

    $fresh = Room::query()->findOrFail($room->id);

    expect($fresh->settings->roundsCount)->toBe(SettingPresetCatalog::settingsFor(SettingPresetKey::Discovery)->roundsCount)
        ->and($fresh->capacity)->toBe(PlatformLimits::roomSeats());
});

it('ramène une capacité devenue supérieure à roomSeats sans refuser l\'écriture', function (): void {
    [$room, $host] = writeRoomWithHost();
    $seats = PlatformLimits::roomSeats();
    $lowered = $seats - 4;
    $holding = $seats - 2;

    expect($room->settings->capacity)->toBe($seats)
        ->and($lowered)->toBeGreaterThanOrEqual(Bounds::MIN_CAPACITY);

    writeSeats($room, $holding - 1);
    platformLimitsConfigure(['room_seats' => $lowered]);

    $outcome = writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT]);

    expect($outcome->isWritten())->toBeTrue()
        ->and($outcome->changes)->toBe(['capacity' => RoomSettings::CHANGE_CLAMPED]);

    $fresh = Room::query()->findOrFail($room->id);

    expect($fresh->capacity)->toBe($lowered)
        ->and($fresh->settings->roundsCount)->toBe(Bounds::MIN_ROUNDS_COUNT)
        // Aucun joueur n'est expulsé par un changement de capacité.
        ->and(Player::query()->where('room_id', $room->id)->holdingSeat()->count())->toBe($holding);
    writeAssertProjection($room);

    // Au plafond, plus rien à ramener ; l'effectif reste au-dessus, sans refus.
    expect(writeUpdate($room, $host, ['revealDuration' => Bounds::MAX_REVEAL_DURATION])->changes)->toBe([]);

    // Une capacité POSTÉE au-dessus du plafond reste un refus du value object.
    expect(array_keys(writeErrors(fn () => writeUpdate($room, $host, ['capacity' => $seats]))))->toBe(['capacity']);

    // Un preset pose le plafond abaissé, jamais refusé.
    platformLimitsConfigure(['room_seats' => $seats]);
    expect(writeUpdate($room, $host, ['capacity' => $seats])->isWritten())->toBeTrue();
    platformLimitsConfigure(['room_seats' => $lowered]);

    expect(writePreset($room, $host, SettingPresetKey::Classic)->isWritten())->toBeTrue()
        ->and(Room::query()->findOrFail($room->id)->capacity)->toBe($lowered);
});

it('ne laisse au J1 aucune ligne room avec advanced vrai ni un champ avancé hors de son défaut', function (): void {
    [$room, $host] = writeRoomWithHost();

    // Une ligne périmée qui porterait `advanced = true` : la première écriture
    // de l'onglet Simple la ramène à false.
    [$stale, $staleHost] = writeRoomWithHost(RoomSettings::fromInput(['advanced' => true]));

    expect(writeUpdate($stale, $staleHost, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue();

    $accepted = [
        ['framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND],
        [RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION],
        ['framesPerRound' => Bounds::MAX_FRAMES_PER_ROUND],
        [RoomSettings::INPUT_ROUND_DURATION => Bounds::minRoundDuration(Bounds::MAX_FRAMES_PER_ROUND) + 1],
        ['revealDuration' => Bounds::MIN_REVEAL_DURATION, 'inputDifficulty' => InputDifficulty::Easy->value],
        ['capacity' => Bounds::MIN_CAPACITY, 'allowLateJoin' => true, 'advanced' => false],
        ['themeKeys' => []],
    ];

    $refused = [
        ['advanced' => true],
        ['tierPoints' => array_fill(0, Bounds::MAX_FRAMES_PER_ROUND, Bounds::MAX_TIER_POINTS)],
        ['tierDurations' => [Bounds::MIN_TIER_DURATION, Bounds::MIN_TIER_DURATION]],
        ['speedBonus' => false],
        ['noRepeatMovies' => false],
        ['attemptsPerSecond' => Bounds::MAX_ATTEMPTS_PER_SECOND],
        ['attemptsPerRound' => Bounds::MAX_ATTEMPTS_PER_ROUND],
        ['maxAnswerLength' => Bounds::MAX_ANSWER_LENGTH],
        ['disconnectGraceSeconds' => Bounds::MAX_DISCONNECT_GRACE_SECONDS],
        ['advanced' => true, 'speedBonus' => false, 'roundsCount' => Bounds::MIN_ROUNDS_COUNT],
    ];

    foreach ($accepted as $posted) {
        expect(writeUpdate($room, $host, $posted)->isWritten())->toBeTrue();
    }

    foreach ($refused as $posted) {
        $before = writeRawRow($room);

        writeErrors(fn () => writeUpdate($room, $host, $posted));

        expect(writeRawRow($room))->toBe($before);
    }

    foreach (SettingPresetKey::cases() as $preset) {
        expect(writePreset($room, $host, $preset)->isWritten())->toBeTrue();
    }

    expect(Room::query()->count())->toBeGreaterThanOrEqual(2);

    foreach (Room::query()->get() as $row) {
        $settings = $row->settings;
        $duration = $settings->roundDuration();

        expect($settings->advanced)->toBeFalse()
            ->and($settings->speedBonus)->toBe(Bounds::DEFAULT_SPEED_BONUS)
            ->and($settings->noRepeatMovies)->toBe(Bounds::DEFAULT_NO_REPEAT_MOVIES)
            ->and($settings->attemptsPerSecond)->toBe(Bounds::DEFAULT_ATTEMPTS_PER_SECOND)
            ->and($settings->maxAnswerLength)->toBe(Bounds::DEFAULT_ANSWER_LENGTH)
            ->and($settings->disconnectGraceSeconds)->toBe(Bounds::DEFAULT_DISCONNECT_GRACE_SECONDS)
            ->and($settings->tierDurations)->toBe(Bounds::defaultTierDurations($settings->framesPerRound, $duration))
            ->and($settings->tierPoints)->toBe(Bounds::defaultTierPoints($settings->framesPerRound))
            ->and($settings->attemptsPerRound)->toBe(Bounds::defaultAttemptsPerRound($duration));
    }
});

it('diffuse l\'état des réglages en données, sans identifiant interne ni chaîne traduite', function (): void {
    PoolFixtures::fakeFramesDisk();
    $roundsCount = Bounds::MIN_ROUNDS_COUNT;
    $theme = Theme::factory()->published()->create();
    $withdrawn = Theme::factory()->unpublished()->create();
    $movies = PoolFixtures::movies($roundsCount);
    PoolFixtures::member($movies[0], $theme);

    // Thèmes, dont un dépublié ; révélation courte : un vivier bloqué par les
    // thèmes, un thème élagué et un avertissement, tous en données.
    [$room] = writeRoomWithHost(RoomSettings::fromInput([
        'themeIds' => [$withdrawn->id, $theme->id],
        'roundsCount' => $roundsCount,
        'revealDuration' => Bounds::MIN_REVEAL_DURATION,
    ]));

    $payloads = [];

    foreach (['fr', 'en'] as $locale) {
        app()->setLocale($locale);
        // Le constructeur de l'événement refuse toute clé d'identifiant interne,
        // à toute profondeur (WirePayload::assertSafe()).
        $event = new SettingsChanged($room, null, RoomSettingsPresenter::state($room, $this->now));
        $payloads[$locale] = $event->payload();
    }

    // Le même contenu pour tous, quelle que soit la langue de la requête.
    expect($payloads['fr'])->toBe($payloads['en']);

    // Et c'est exactement ce que le job émet, tel que le fil le porte, dans
    // l'une ou l'autre langue : la charge réelle de `BroadcastLobbyState`
    // porte les clés de thème, jamais les ids, et omet le thème dépublié.
    $wire = json_decode(json_encode($payloads['fr'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    foreach (['fr', 'en'] as $locale) {
        $recorder = RecordingBroadcaster::install();
        app()->setLocale($locale);
        (new BroadcastLobbyState($room->id))->handle();

        expect($recorder->sent)->toHaveCount(1)
            ->and($recorder->sent[0]['event'])->toBe('settings.changed')
            ->and(array_diff_key($recorder->sent[0]['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe($wire);
    }

    $payload = $payloads['fr'];
    $client = RoomSettings::FIELDS;
    $client[array_search('themeIds', $client, true)] = RoomSettingsEditor::THEME_KEYS;

    expect(array_keys($payload))->toBe(['settings', 'warnings', 'pool'])
        ->and(array_keys($payload['settings']))->toBe($client)
        // Clés de thème seulement, dépublié omis, jamais un identifiant.
        ->and($payload['settings']['themeKeys'])->toBe([$theme->key])
        ->and($payload['warnings'])->toBe([RoomSettings::WARNING_SHORT_REVEAL])
        ->and($payload['pool']['blocked'])->toBeTrue()
        ->and($payload['pool']['causes'])->toBe([PoolFault::ThemeKeys->value])
        ->and($payload['pool']['themesPruned'])->toBeTrue();

    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('themeIds')->not->toContain('"id"');

    // Chaque chaîne de la charge est un code ou une clé de thème, jamais un texte.
    $codes = [
        $theme->key,
        ...array_map(static fn (InputDifficulty $case): string => $case->value, InputDifficulty::cases()),
        RoomSettings::WARNING_SHORT_REVEAL, RoomSettings::WARNING_LONG_ROUND,
        RoomSettings::WARNING_NON_DECREASING_POINTS, RoomSettings::WARNING_ALL_TIERS_ZERO,
        ...array_map(static fn (PoolFault $case): string => $case->value, PoolFault::cases()),
        ...array_map(static fn (PoolRemedyKind $case): string => $case->value, PoolRemedyKind::cases()),
    ];

    array_walk_recursive($payload, function (mixed $value) use ($codes): void {
        if (is_string($value)) {
            expect($codes)->toContain($value);
        }
    });

    // Au J1, le remède `disable_no_repeat` est retiré ; le nouveau salon reste proposé.
    [$replayed] = writeRoomWithHost(RoomSettings::fromInput(['roundsCount' => $roundsCount]));
    PoolFixtures::round(PoolFixtures::game($replayed), $movies[1], $this->now->subDay());

    $state = RoomSettingsPresenter::state($replayed, $this->now);

    expect($state['pool']['causes'])->toBe([PoolFault::NoRepeatMovies->value])
        ->and(array_column($state['pool']['remedies'], 'kind'))->toBe([PoolRemedyKind::OpenNewRoom->value]);

    new SettingsChanged($replayed, null, $state);
});

it('dispatche une seule diffusion anti-rebondie par salon après validation, sur la file game', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token);
    [$other, $otherHost] = writeRoomWithHost();
    LobbyWrites::actAs($this, $token);
    $defaults = RoomSettings::defaults();

    // Pendant la transaction de l'appelant, rien n'entre en file ; annulée,
    // elle ne dispatche rien et ne garde pas le verrou d'unicité.
    try {
        DB::transaction(function () use ($room, $host): void {
            expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue()
                ->and(DB::table('jobs')->count())->toBe(0);

            throw new RuntimeException('annulée');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->count())->toBe(0);

    // Validée : la diffusion entre en file au commit, pas avant.
    DB::transaction(function () use ($room, $host): void {
        expect(writePreset($room, $host, SettingPresetKey::Classic)->isWritten())->toBeTrue()
            ->and(DB::table('jobs')->count())->toBe(0);
    });

    expect(LobbyWrites::queuedBroadcasts())->toHaveCount(1);

    // Une rafale par les routes — un curseur glissé, un preset, un dernier
    // geste — pendant la fenêtre : aucun dispatch de plus pour ce salon.
    $burst = [
        ['PATCH', 'room.settings.update', ['roundsCount' => $defaults->roundsCount + 1]],
        ['PATCH', 'room.settings.update', ['roundsCount' => $defaults->roundsCount + 2]],
        ['POST', 'room.settings.preset', ['preset' => SettingPresetKey::Fast->value]],
        ['PATCH', 'room.settings.update', ['roundsCount' => Bounds::MAX_ROUNDS_COUNT]],
    ];

    foreach ($burst as [$method, $route, $body]) {
        LobbyWrites::send($this, $method, route($route, $room), $room, $body, $host)
            ->assertRedirect(LobbyWrites::lobbyUrl($room))
            ->assertSessionHasNoErrors();
    }

    // Un autre salon n'est jamais coalescé avec le premier.
    expect(writeUpdate($other, $otherHost, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue();

    // Un salon sans diffusion en attente : aucun refus ne dispatche —
    // onglet supplanté, entrée invalide, non-hôte, hors du lobby.
    [$quiet, $quietHost] = LobbyWrites::hostedRoom($token);
    $update = route('room.settings.update', $quiet);

    LobbyWrites::send($this, 'PATCH', $update, $quiet, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], (string) Str::ulid())
        ->assertStatus(Response::HTTP_CONFLICT);
    LobbyWrites::send($this, 'PATCH', $update, $quiet, ['tierPoints' => Bounds::defaultTierPoints($defaults->framesPerRound)], $quietHost)
        ->assertSessionHasErrors('tierPoints');

    Room::query()->whereKey($quiet->id)->update(['host_player_id' => Player::factory()->for($quiet)->create()->id]);

    LobbyWrites::send($this, 'PATCH', $update, $quiet, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $quietHost)
        ->assertForbidden();

    Room::query()->whereKey($quiet->id)->update(['host_player_id' => $quietHost->id, 'status' => RoomStatus::Playing->value]);

    LobbyWrites::send($this, 'PATCH', $update, $quiet, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT], $quietHost)
        ->assertStatus(Response::HTTP_SEE_OTHER);
    LobbyWrites::send($this, 'POST', route('room.settings.preset', $quiet), $quiet, ['preset' => SettingPresetKey::Fast->value], $quietHost)
        ->assertStatus(Response::HTTP_SEE_OTHER);

    // Un job par salon écrit, tous sur la file `game`, jamais sur `default` ;
    // rien n'est encore parti.
    $queued = LobbyWrites::queuedBroadcasts();

    expect(array_map(static fn (array $entry): int => $entry['job']->roomId, $queued))->toBe([$room->id, $other->id])
        ->and(array_column($queued, 'queue'))->toBe(['game', 'game'])
        ->and(DB::table('jobs')->count())->toBe(2)
        ->and(LobbyWrites::workGameQueue())->toBe(0)
        ->and($recorder->sent)->toBe([]);

    // La fenêtre passée : un seul settings.changed par salon, portant le
    // DERNIER état de la rafale.
    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(LobbyWrites::workGameQueue())->toBe(2)
        ->and($recorder->sent)->toHaveCount(2);

    $mine = array_values(array_filter(
        $recorder->sent,
        static fn (array $sent): bool => $sent['channels'] === ['presence-'.ChannelNames::room($room)],
    ));

    expect($mine)->toHaveCount(1)
        ->and($mine[0]['event'])->toBe('settings.changed')
        ->and($mine[0]['payload']['settings']['roundsCount'])->toBe(Bounds::MAX_ROUNDS_COUNT)
        ->and($mine[0]['payload']['settings']['framesPerRound'])
        ->toBe(SettingPresetCatalog::settingsFor(SettingPresetKey::Fast)->framesPerRound);

    // File vide : une écriture A en attente, puis une écriture B dont la
    // transaction n'est validée qu'APRÈS que le worker a traité A. Le verrou
    // d'unicité n'est pris qu'au commit de B : A, déjà traité, l'a relâché, et
    // B entre en file pour diffuser son propre état (écart E86-6).
    expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MIN_ROUNDS_COUNT])->isWritten())->toBeTrue();

    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    DB::transaction(function () use ($room, $host): void {
        expect(writeUpdate($room, $host, ['roundsCount' => Bounds::MAX_ROUNDS_COUNT])->isWritten())->toBeTrue()
            ->and(LobbyWrites::workGameQueue())->toBe(1);
    });

    $pending = LobbyWrites::queuedBroadcasts();

    expect($pending)->toHaveCount(1)
        ->and($pending[0]['job']->roomId)->toBe($room->id)
        ->and($pending[0]['queue'])->toBe('game');
});

it('programme la diffusion anti-rebondie en millisecondes arrondies à la seconde supérieure, jamais en secondes', function (): void {
    config(['queue.default' => 'database']);
    $token = PlayerToken::mint(Locale::French);
    LobbyWrites::actAs($this, $token);
    $default = PlatformLimits::DEFAULT_LOBBY_BROADCAST_DEBOUNCE_MS;
    $max = PlatformLimits::MAX_LOBBY_BROADCAST_DEBOUNCE_MS;

    // [instant de l'écriture, anti-rebond configuré, route, disponibilité attendue]
    $cases = [
        // Défaut : 13,342 s → 14 s, jamais 13 s (troncature) ni 5 min (secondes).
        ['2026-09-26 14:05:13.042', $default, 'room.settings.update', '2026-09-26 14:05:14'],
        // La somme franchit la seconde : 14,258 s → 15 s.
        ['2026-09-26 14:05:13.958', $default, 'room.settings.preset', '2026-09-26 14:05:15'],
        // Déjà sur une seconde entière : rien à arrondir, fenêtre exacte.
        ['2026-09-26 14:05:20.000', $max, 'room.settings.update', '2026-09-26 14:05:21'],
        ['2026-09-26 14:05:20.500', $max, 'room.settings.preset', '2026-09-26 14:05:22'],
    ];

    foreach ($cases as [$at, $debounceMs, $route, $expected]) {
        DB::table('jobs')->delete();
        platformLimitsConfigure(['lobby_broadcast_debounce_ms' => $debounceMs]);
        $now = CarbonImmutable::parse($at);
        $this->travelTo($now);
        [$room, $host] = LobbyWrites::hostedRoom($token);
        $preset = $route === 'room.settings.preset';

        LobbyWrites::send(
            $this,
            $preset ? 'POST' : 'PATCH',
            route($route, $room),
            $room,
            $preset ? ['preset' => SettingPresetKey::Classic->value] : ['roundsCount' => Bounds::MIN_ROUNDS_COUNT],
            $host,
        )->assertSessionHasNoErrors();

        $queued = LobbyWrites::queuedBroadcasts();
        $availableAt = CarbonImmutable::parse($expected);

        expect($queued)->toHaveCount(1);

        // Un INSTANT, jamais un entier que Laravel lirait en secondes.
        $delay = $queued[0]['job']->delay;

        expect($delay)->toBeInstanceOf(DateTimeInterface::class)
            ->and($delay instanceof DateTimeInterface ? $delay->format('Y-m-d H:i:s.u') : null)
            ->toBe($now->addMilliseconds($debounceMs)->ceilSecond()->format('Y-m-d H:i:s.u'))
            ->and($queued[0]['availableAt'])->toBe($availableAt->getTimestamp());

        // Fenêtre effective dans [anti-rebond, anti-rebond + 1 s[ : jamais
        // plus courte que l'anti-rebond.
        $windowMs = $availableAt->getTimestampMs() - $now->getTimestampMs();

        expect($windowMs)->toBeGreaterThanOrEqual($debounceMs)
            ->and($windowMs)->toBeLessThan($debounceMs + 1000);

        // Rien ne part avant l'instant, tout part à l'instant.
        $this->travelTo($availableAt->subMillisecond());
        expect(LobbyWrites::workGameQueue())->toBe(0);

        $this->travelTo($availableAt);
        expect(LobbyWrites::workGameQueue())->toBe(1);
    }
});

it('rend le rapport de changements à l\'auteur seul, jamais au salon', function (): void {
    config(['queue.default' => 'database']);
    $recorder = RecordingBroadcaster::install();
    $framesPerRound = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $custom = array_fill(0, $framesPerRound, Bounds::MAX_TIER_POINTS);
    $token = PlayerToken::mint(Locale::French);
    [$room, $host] = LobbyWrites::hostedRoom($token, RoomSettings::fromInput([
        'framesPerRound' => $framesPerRound,
        'tierPoints' => $custom,
    ]));
    $guestToken = PlayerToken::mint(Locale::English);
    $guest = LobbyWrites::seat($room, $guestToken);
    LobbyWrites::actAs($this, $token);

    // N change : le barème personnalisé est remplacé, et l'auteur le lit dans
    // SA réponse, en flash, sous les clés client.
    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, [
        'framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND,
    ], $host)
        ->assertRedirect(LobbyWrites::lobbyUrl($room))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('settingsChanges', ['tierPoints' => RoomSettings::CHANGE_RESET]);

    expect(Room::query()->findOrFail($room->id)->settings->tierPoints)
        ->toBe(Bounds::defaultTierPoints(Bounds::MIN_FRAMES_PER_ROUND));

    // Rien ne part au salon avec l'écriture elle-même.
    expect($recorder->sent)->toBe([]);

    // Le salon reçoit l'ÉTAT, relu par le job, et rien d'autre : ni le
    // rapport, ni un code de changement.
    $this->travel(PlatformLimits::lobbyBroadcastDebounceMs() + 1000)->milliseconds();

    expect(LobbyWrites::workGameQueue())->toBe(1)
        ->and($recorder->sent)->toHaveCount(1);

    $sent = $recorder->sent[0];
    $state = RoomSettingsPresenter::state($room->refresh(), Date::now()->toImmutable());
    $wire = json_decode((string) json_encode($state, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($sent['event'])->toBe('settings.changed')
        ->and($sent['channels'])->toBe(['presence-'.ChannelNames::room($room)])
        ->and(array_diff_key($sent['payload'], array_flip(['v', 'serverNow', 'gameRef'])))->toBe($wire)
        ->and($sent['json'])->not->toContain('settingsChanges')
        ->and($sent['json'])->not->toContain('"changes"');

    foreach ([RoomSettings::CHANGE_RESET, RoomSettings::CHANGE_EQUALIZED, RoomSettings::CHANGE_CLAMPED] as $code) {
        expect($sent['json'])->not->toContain('"'.$code.'"');
    }

    // Un preset : rapport vide au J1, rendu quand même à l'auteur, qui sait
    // ainsi que rien d'invisible n'a changé.
    LobbyWrites::send($this, 'POST', route('room.settings.preset', $room), $room, [
        'preset' => SettingPresetKey::Hardcore->value,
    ], $host)
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('settingsChanges', []);

    // Un autre siège du salon : aucun rapport dans sa session, et aucun geste
    // de réglage pour lui.
    $this->flushSession();
    LobbyWrites::actAs($this, $guestToken);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, [
        'framesPerRound' => Bounds::DEFAULT_FRAMES_PER_ROUND,
    ], $guest)
        ->assertForbidden()
        ->assertInertiaFlashMissing('settingsChanges');

    // Un refus de validation n'en porte pas davantage : l'auteur lit ses
    // erreurs de champ, jamais un rapport.
    $this->flushSession();
    LobbyWrites::actAs($this, $token);

    LobbyWrites::send($this, 'PATCH', route('room.settings.update', $room), $room, [
        'tierPoints' => $custom,
    ], $host)
        ->assertSessionHasErrors('tierPoints')
        ->assertInertiaFlashMissing('settingsChanges');
});
