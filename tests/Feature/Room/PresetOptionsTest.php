<?php

use App\Actions\Room\ApplyRoomPreset;
use App\Enums\InputDifficulty;
use App\Enums\SettingPresetKey;
use App\Models\Player;
use App\Models\Room;
use App\Models\Theme;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\SettingPresetCatalog;
use App\Support\Draw\PoolReporter;
use App\Support\Draw\PoolScope;
use App\Support\Room\RoomSettingsPresenter;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Carbon\CarbonImmutable;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Presets au lobby — spec 50 § 5.3, contrat C0 § 3.3
|--------------------------------------------------------------------------
|
| Appliquer un preset pré-remplit les seize champs par
| `SettingPresetCatalog::settingsFor()`. Le grisage, lui, est une AIDE
| calculée au rendu du lobby et jamais stockée : le vivier du salon pour les
| réglages du preset, par le constructeur unique de la spec 30, sur la même
| non-répétition et la même fenêtre de mémoire que la garde de lancement.
|
*/

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-09-26 14:05:13.042');
    $this->travelTo($this->now);
});

/**
 * Un salon au lobby et son hôte.
 *
 * @return array{0: Room, 1: Player}
 */
function presetRoomWithHost(?RoomSettings $settings = null): array
{
    $room = Room::factory()->withSettings($settings ?? RoomSettings::defaults())->create();
    $host = Player::factory()->for($room)->create();

    Room::query()->whereKey($room->id)->update(['host_player_id' => $host->id]);

    return [$room->refresh(), $host];
}

/**
 * L'option de grisage d'un preset, indexée par sa clé.
 *
 * @return array{key: string, grayed: bool, nearestPlayableFramesPerRound: int|null}
 */
function presetOption(Room $room, SettingPresetKey $preset, CarbonImmutable $now): array
{
    $options = array_column(RoomSettingsPresenter::presets($room, $now), null, 'key');

    return $options[$preset->value];
}

it('applique les seize champs du preset, thèmes vidés et capacité au plafond', function (SettingPresetKey $preset): void {
    $theme = Theme::factory()->published()->create();
    [$room, $host] = presetRoomWithHost(RoomSettings::fromInput([
        'themeIds' => [$theme->id],
        'roundsCount' => Bounds::MAX_ROUNDS_COUNT,
        'framesPerRound' => Bounds::MIN_FRAMES_PER_ROUND,
        RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION,
        'revealDuration' => Bounds::MIN_REVEAL_DURATION,
        'inputDifficulty' => InputDifficulty::Expert->value,
        'capacity' => Bounds::MIN_CAPACITY,
        'allowLateJoin' => true,
    ]));

    // L'effectif dépasse la capacité courante : le plafond n'est jamais refusé.
    Player::factory()->for($room)->count(Bounds::MIN_CAPACITY)->create();

    $outcome = app(ApplyRoomPreset::class)->handle($room, $host, $preset);
    $expected = SettingPresetCatalog::settingsFor($preset);
    $fresh = Room::query()->findOrFail($room->id);

    expect($outcome->isWritten())->toBeTrue()
        // Au J1, rien d'invisible n'est écrasé : aucun rapport.
        ->and($outcome->changes)->toBe([])
        // Les seize champs, et eux seuls, sont ceux du preset.
        ->and($fresh->settings->toPayload())->toBe($expected->toPayload())
        ->and($fresh->settings->themeIds)->toBe([])
        ->and($fresh->settings->capacity)->toBe(PlatformLimits::roomSeats())
        ->and($fresh->settings->allowLateJoin)->toBe(Bounds::DEFAULT_ALLOW_LATE_JOIN)
        ->and($fresh->settings->advanced)->toBeFalse()
        // Les cinq projections suivent.
        ->and($fresh->capacity)->toBe($expected->capacity)
        ->and($fresh->frames_per_round)->toBe($expected->framesPerRound)
        ->and($fresh->rounds_count)->toBe($expected->roundsCount)
        ->and($fresh->input_difficulty)->toBe($expected->inputDifficulty)
        ->and($fresh->allow_late_join)->toBe($expected->allowLateJoin);

    // Les cinq chiffres du preset sont ceux du catalogue, jamais recopiés ici.
    $input = SettingPresetCatalog::inputFor($preset);

    expect($fresh->settings->roundDuration())->toBe($input[RoomSettings::INPUT_ROUND_DURATION])
        ->and($fresh->settings->framesPerRound)->toBe($input['framesPerRound'])
        ->and($fresh->settings->inputDifficulty)->toBe($input['inputDifficulty'])
        ->and($fresh->settings->roundsCount)->toBe($input['roundsCount'])
        ->and($fresh->settings->revealDuration)->toBe($input['revealDuration']);
})->with(SettingPresetKey::cases());

it('grise un preset dont le vivier du salon est insuffisant et propose le N jouable le plus proche', function (): void {
    PoolFixtures::fakeFramesDisk();

    // Catalogue en passe 1 seulement (niveaux 1, 3 et 5) : jouable jusqu'au N
    // par défaut, jamais au-delà (spec 50 § 5.2). Assez d'œuvres pour le plus
    // gourmand des presets.
    $passOne = FrameLevelCoverage::nominal(Bounds::DEFAULT_FRAMES_PER_ROUND);
    $roundsCounts = array_map(
        static fn (SettingPresetKey $key): int => SettingPresetCatalog::settingsFor($key)->roundsCount,
        SettingPresetKey::cases(),
    );
    PoolFixtures::movies(max($roundsCounts), $passOne);

    // Les thèmes du salon ne comptent pas : un preset les remet à vide.
    $theme = Theme::factory()->published()->create();
    [$room] = presetRoomWithHost(RoomSettings::fromInput(['themeIds' => [$theme->id]]));

    expect(RoomSettingsPresenter::state($room, $this->now)['pool']['blocked'])->toBeTrue();

    $options = RoomSettingsPresenter::presets($room, $this->now);

    // Triés par position, sans aucun identifiant ni texte.
    $keys = SettingPresetKey::cases();
    usort($keys, static fn (SettingPresetKey $a, SettingPresetKey $b): int => SettingPresetCatalog::positionFor($a) <=> SettingPresetCatalog::positionFor($b));

    expect(array_column($options, 'key'))->toBe(array_map(static fn (SettingPresetKey $key): string => $key->value, $keys))
        ->and(array_map('array_keys', $options))->each->toBe(['key', 'grayed', 'nearestPlayableFramesPerRound']);

    foreach (SettingPresetKey::cases() as $preset) {
        $settings = SettingPresetCatalog::settingsFor($preset);
        $option = presetOption($room, $preset, $this->now);

        if ($settings->framesPerRound <= count($passOne)) {
            expect($option)->toBe(['key' => $preset->value, 'grayed' => false, 'nearestPlayableFramesPerRound' => null]);

            continue;
        }

        // Hardcore au J1 : grisé, « jouable à N images par manche ».
        expect($option)->toBe([
            'key' => $preset->value,
            'grayed' => true,
            'nearestPlayableFramesPerRound' => count($passOne),
        ]);
    }

    expect(presetOption($room, SettingPresetKey::Hardcore, $this->now)['grayed'])->toBeTrue();

    // Une aide seulement : le preset grisé reste applicable, et le lobby montre
    // alors le blocage du vivier (§ 5.3) ; seule la garde de lancement tranche.
    [$other, $host] = presetRoomWithHost();

    expect(app(ApplyRoomPreset::class)->handle($other, $host, SettingPresetKey::Hardcore)->isWritten())->toBeTrue();

    $state = RoomSettingsPresenter::state(Room::query()->findOrFail($other->id), $this->now);

    expect($state['pool']['blocked'])->toBeTrue()
        ->and($state['pool']['nearestPlayableFramesPerRound'])->toBe(count($passOne));
});

it('calcule le grisage avec la non-répétition et la fenêtre du salon', function (): void {
    PoolFixtures::fakeFramesDisk();
    $preset = SettingPresetKey::Discovery;
    $settings = SettingPresetCatalog::settingsFor($preset);
    $movies = PoolFixtures::movies($settings->roundsCount, FrameLevelCoverage::nominal($settings->framesPerRound));

    // Un salon neuf a une mémoire vide : le catalogue suffit tout juste.
    [$fresh] = presetRoomWithHost();

    expect(presetOption($fresh, $preset, $this->now)['grayed'])->toBeFalse();

    // Un film joué hier par CE salon manque : grisé, et aucun N plus bas ne le
    // rattrape — c'est la non-répétition qui bloque, pas N.
    [$played] = presetRoomWithHost();
    PoolFixtures::round(PoolFixtures::game($played), $movies[0], $this->now->subDay());

    expect(presetOption($played, $preset, $this->now))->toBe([
        'key' => $preset->value,
        'grayed' => true,
        'nearestPlayableFramesPerRound' => null,
    ])->and(presetOption($fresh, $preset, $this->now)['grayed'])->toBeFalse();

    // Même verdict que la garde de lancement : même constructeur, même instant.
    $guard = app(PoolReporter::class)->report(PoolScope::forRoom($played, $settings, $this->now), $settings->roundsCount);

    expect($guard->blocked())->toBeTrue();

    // Hors de la fenêtre en jours : le film est rejouable.
    [$old] = presetRoomWithHost();
    PoolFixtures::round(PoolFixtures::game($old), $movies[0], $this->now->subDays(PlatformLimits::roomMemoryWindowDays() + 1));

    expect(presetOption($old, $preset, $this->now)['grayed'])->toBeFalse();

    // Une manche programmée dont T₁ n'est pas atteint n'est pas jouée.
    [$scheduled] = presetRoomWithHost();
    PoolFixtures::round(PoolFixtures::game($scheduled), $movies[0], $this->now->addMinute());

    expect(presetOption($scheduled, $preset, $this->now)['grayed'])->toBeFalse();

    // Fenêtre en manches : la manche la plus récente, sur un film hors du vivier
    // de ce N, ferme la fenêtre et rend jouable le film plus ancien.
    [$windowed] = presetRoomWithHost();
    $game = PoolFixtures::game($windowed);
    $outsider = PoolFixtures::movie(FrameLevelCoverage::nominal(Bounds::MIN_FRAMES_PER_ROUND));

    expect(count(FrameLevelCoverage::nominal(Bounds::MIN_FRAMES_PER_ROUND)))->toBeLessThan($settings->framesPerRound);

    PoolFixtures::round($game, $movies[0], $this->now->subDays(2));
    PoolFixtures::round($game, $outsider, $this->now->subDay());

    expect(presetOption($windowed, $preset, $this->now)['grayed'])->toBeTrue();

    platformLimitsConfigure(['room_memory_window_rounds' => 1]);

    expect(presetOption($windowed, $preset, $this->now)['grayed'])->toBeFalse()
        ->and(presetOption($played, $preset, $this->now)['grayed'])->toBeTrue();
});
