<?php

use App\Actions\Room\ApplyRoomPreset;
use App\Actions\Room\UpdateRoomSettings;
use App\Actions\Room\WriteRoomSettings;
use App\Enums\InputDifficulty;
use App\Enums\PoolFault;
use App\Enums\PoolRemedyKind;
use App\Enums\RoomRefusal;
use App\Enums\RoomStatus;
use App\Enums\SettingPresetKey;
use App\Events\Game\SettingsChanged;
use App\Models\Player;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Room\SettingsWriteOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Draw\PoolFixtures;

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
| Premier temps du lot (I-1) : les routes sous `seat.active`, le dispatch de
| `BroadcastLobbyState` et leurs trois tests (diffusion anti-rebondie unique,
| délai en millisecondes, rapport rendu à l'auteur seul) arrivent au second
| temps, après le job de la spec 60 (L60-4).
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
