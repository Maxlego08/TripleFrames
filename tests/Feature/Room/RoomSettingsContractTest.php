<?php

use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds as Bounds;
use App\Settings\SettingPresetCatalog;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Contrat `room_settings` — contrat C0, spec 50 § 2.1, § 2.4 et § 2.6
|--------------------------------------------------------------------------
|
| Le value object est consommé par six specs : sa forme ne bouge qu'avec
| `VERSION`. Ces tests épinglent la liste close des seize champs, leurs
| défauts, la forme de leur charge utile et celle des bornes envoyées au
| client. Les bornes croisées, elles, sont prouvées combinaison par
| combinaison par `RoomSettingsMatrixTest` (spec 100).
|
*/

it('déclare un défaut pour chacun des seize champs dans l\'ordre de FIELDS', function (): void {
    expect(RoomSettings::FIELDS)->toHaveCount(16);

    $framesPerRound = Bounds::DEFAULT_FRAMES_PER_ROUND;
    $roundDuration = Bounds::DEFAULT_ROUND_DURATION;

    // Chaque défaut nomme sa source dans `RoomSettingsBounds` (spec 50 § 2.2).
    $expected = [
        'themeIds' => Bounds::defaultThemeIds(),
        'roundsCount' => Bounds::DEFAULT_ROUNDS_COUNT,
        'framesPerRound' => $framesPerRound,
        'tierDurations' => Bounds::defaultTierDurations($framesPerRound, $roundDuration),
        'tierPoints' => Bounds::defaultTierPoints($framesPerRound),
        'revealDuration' => Bounds::DEFAULT_REVEAL_DURATION,
        'inputDifficulty' => Bounds::DEFAULT_INPUT_DIFFICULTY->value,
        'capacity' => Bounds::defaultCapacity(),
        'allowLateJoin' => Bounds::DEFAULT_ALLOW_LATE_JOIN,
        'speedBonus' => Bounds::DEFAULT_SPEED_BONUS,
        'noRepeatMovies' => Bounds::DEFAULT_NO_REPEAT_MOVIES,
        'attemptsPerSecond' => Bounds::DEFAULT_ATTEMPTS_PER_SECOND,
        'attemptsPerRound' => Bounds::defaultAttemptsPerRound($roundDuration),
        'maxAnswerLength' => Bounds::DEFAULT_ANSWER_LENGTH,
        'disconnectGraceSeconds' => Bounds::DEFAULT_DISCONNECT_GRACE_SECONDS,
        'advanced' => Bounds::DEFAULT_ADVANCED,
    ];

    expect(array_keys($expected))->toBe(RoomSettings::FIELDS);
    expect(RoomSettings::defaults()->toPayload())->toBe($expected);
    expect(RoomSettings::defaults()->roundDuration())->toBe($roundDuration);

    // « Champ ajouté = défaut » : une charge vide se normalise vers les défauts,
    // et chacun des seize champs est rapporté `defaulted`, aucun autre.
    $normalized = RoomSettings::normalize([], RoomSettings::VERSION);
    $changes = $normalized['changes'];
    ksort($changes);
    $defaulted = array_fill_keys(RoomSettings::FIELDS, RoomSettings::CHANGE_DEFAULTED);
    ksort($defaulted);

    expect($changes)->toBe($defaulted);
    expect($normalized['settings']->equals(RoomSettings::defaults()))->toBeTrue();
});

it('refuse les clés graceMs, tierGraceMs, preloadLeadMs, speedBonusMaxPercent et speedBonusMaxFraction', function (string $key, int|float $value): void {
    // Une charge par ailleurs valide : la seule faute est la constante serveur postée.
    $input = [...RoomSettings::defaults()->toPayload(), $key => $value];

    $errors = RoomSettings::validate($input);

    expect(array_keys($errors))->toBe([$key]);
    expect(array_column($errors[$key], 'key'))->toBe(['validation.room_settings.unknown_field']);

    try {
        RoomSettings::fromInput($input);
        $this->fail("La clé [{$key}] aurait dû être refusée.");
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$key]);
    }
})->with([
    'graceMs' => ['graceMs', 120_000],
    'tierGraceMs' => ['tierGraceMs', 120_000],
    'preloadLeadMs' => ['preloadLeadMs', 30_000],
    'speedBonusMaxPercent' => ['speedBonusMaxPercent', 100],
    'speedBonusMaxFraction' => ['speedBonusMaxFraction', 1.0],
]);

it('produit une charge utile dont les clés sont exactement FIELDS, en camelCase et dans l\'ordre', function (): void {
    $instances = [
        'défauts' => RoomSettings::defaults(),
        'entrée de l\'hôte' => RoomSettings::fromInput([
            'framesPerRound' => Bounds::MAX_FRAMES_PER_ROUND,
            RoomSettings::INPUT_ROUND_DURATION => Bounds::MAX_ROUND_DURATION,
        ]),
    ];

    foreach (SettingPresetKey::cases() as $preset) {
        $instances['preset '.$preset->value] = SettingPresetCatalog::settingsFor($preset);
    }

    foreach ($instances as $label => $settings) {
        $payload = $settings->toPayload();

        expect(array_keys($payload))->toBe(RoomSettings::FIELDS, $label);

        foreach (array_keys($payload) as $key) {
            expect($key)->toMatch('/^[a-z]+(?:[A-Z][a-z]+)*$/', $label);
        }

        // Le JSON persisté par le cast garde exactement le même ordre.
        $decoded = json_decode($settings->toJson(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($decoded))->toBe(RoomSettings::FIELDS, $label);
    }
});

it('garde VERSION à 1 tant que FIELDS est inchangé', function (): void {
    // Instantané écrit à la main : ajouter, retirer, renommer ou réordonner un
    // champ fait échouer ce test, qui ne repasse qu'avec un pas d'`upgrade()` et
    // `VERSION` incrémentée.
    expect(RoomSettings::FIELDS)->toBe([
        'themeIds',
        'roundsCount',
        'framesPerRound',
        'tierDurations',
        'tierPoints',
        'revealDuration',
        'inputDifficulty',
        'capacity',
        'allowLateJoin',
        'speedBonus',
        'noRepeatMovies',
        'attemptsPerSecond',
        'attemptsPerRound',
        'maxAnswerLength',
        'disconnectGraceSeconds',
        'advanced',
    ]);

    expect(RoomSettings::VERSION)->toBe(1);
});

it('expose au client les bornes de chaque N, sans chaîne et en entiers', function (): void {
    $client = Bounds::toClient();

    expect(array_keys($client))->toBe(['byFramesPerRound', 'derivation', 'warningThresholds']);

    // Un jeu de bornes pour chaque N, pour que le client réagisse sans aller-retour quand N change.
    expect(array_keys($client['byFramesPerRound']))
        ->toBe(range(Bounds::MIN_FRAMES_PER_ROUND, Bounds::MAX_FRAMES_PER_ROUND));

    foreach ($client['byFramesPerRound'] as $framesPerRound => $bounds) {
        expect($bounds)->toBe(Bounds::toArray($framesPerRound));
        expect(array_keys($bounds))->toBe([
            'roundsCount',
            'framesPerRound',
            'roundDuration',
            'tierDuration',
            'tierPoints',
            'revealDuration',
            'capacity',
            'attemptsPerSecond',
            'attemptsPerRound',
            'maxAnswerLength',
            'disconnectGraceSeconds',
        ]);

        // Borne croisée 1, recalculée ici à la main : D ≥ 5 s × N.
        expect($bounds['roundDuration']['min'])
            ->toBe(max(Bounds::MIN_ROUND_DURATION, Bounds::MIN_TIER_DURATION * $framesPerRound));

        foreach ($bounds as $field => $bound) {
            expect(array_keys($bound))->toBe(['min', 'max'], (string) $field);
            expect($bound['min'])->toBeLessThanOrEqual($bound['max'], (string) $field);
        }
    }

    expect($client['derivation'])->toBe([
        'tierPointsUnit' => Bounds::TIER_POINTS_UNIT,
        'attemptsPerRoundSecondsPerAttempt' => Bounds::ATTEMPTS_PER_ROUND_SECONDS_PER_ATTEMPT,
        'attemptsPerRoundSoftCap' => Bounds::ATTEMPTS_PER_ROUND_SOFT_CAP,
    ]);

    expect($client['warningThresholds'])->toBe([
        'recommendedMinRevealDuration' => Bounds::RECOMMENDED_MIN_REVEAL_DURATION,
        'longRoundWarningDuration' => Bounds::LONG_ROUND_WARNING_DURATION,
    ]);

    array_walk_recursive($client, static function (mixed $leaf): void {
        expect($leaf)->toBeInt();
    });

    // Indexé par N : un objet JSON aux clés d'entier, jamais une liste.
    $json = json_encode($client, JSON_THROW_ON_ERROR);

    expect($json)->toContain('"byFramesPerRound":{"'.Bounds::MIN_FRAMES_PER_ROUND.'":');
});
