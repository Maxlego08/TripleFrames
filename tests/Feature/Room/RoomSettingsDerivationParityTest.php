<?php

use App\Console\Commands\RoomDerivationsFixtureCommand;
use App\Enums\SettingPresetKey;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Settings\RoomSettingsEditor;
use App\Settings\SettingPresetCatalog;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Parité des dérivations serveur / client — spec 50 § 4.3 (lot L50-5)
|--------------------------------------------------------------------------
|
| Le retour immédiat de l'onglet Simple (`resources/js/lib/room-settings.ts`)
| redit côté client le découpage égal, le barème par défaut,
| `attemptsPerRound` par défaut et les avertissements, depuis les seules
| bornes (`RoomSettingsBounds::toClient()`). Le jeu partagé
| `tests/Fixtures/room/derivations.json` porte les valeurs du serveur ; ce
| fichier les exige du serveur, `tests/Frontend/room/room-settings.test.ts`
| (Vitest) du client. Une divergence casse l'un des deux.
|
| Le jeu est écrit par `php artisan room:derivations-fixture`, à la main,
| après un changement voulu des bornes, et son diff se relit. **Aucun test
| ne l'écrit** : un test qui le régénérerait avant de le comparer serait
| tautologique.
|
*/

/** Chemin du jeu partagé, relatif à la racine du dépôt. */
function roomDerivationsPath(): string
{
    return RoomDerivationsFixtureCommand::TARGET;
}

/**
 * Une liste d'entiers lue strictement.
 *
 * @return list<int>
 */
function roomDerivationsIntList(mixed $value, string $where): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$where} : une liste d'entiers est attendue.");
    }

    return array_map(static function (mixed $item) use ($where): int {
        if (! is_int($item)) {
            throw new RuntimeException("{$where} : une liste d'entiers est attendue.");
        }

        return $item;
    }, $value);
}

/**
 * Une liste de codes d'avertissement lue strictement.
 *
 * @return list<string>
 */
function roomDerivationsWarnings(mixed $value, string $where): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$where} : une liste de codes est attendue.");
    }

    return array_map(static function (mixed $item) use ($where): string {
        if (! is_string($item)) {
            throw new RuntimeException("{$where} : une liste de codes est attendue.");
        }

        return $item;
    }, $value);
}

/**
 * Le jeu partagé, lu STRICTEMENT — ni clé en plus ni clé en moins, des
 * entiers et des codes seulement. Lu, jamais écrit.
 *
 * @return array{
 *     bounds: array<mixed>,
 *     cases: list<array{n: int, d: int, r: int, tierDurations: list<int>, tierPoints: list<int>, attemptsPerRound: int, warnings: list<string>}>,
 *     warningCases: list<array{revealDuration: int, tierDurations: list<int>, tierPoints: list<int>, warnings: list<string>}>,
 * }
 */
function roomDerivations(): array
{
    $decoded = json_decode((string) file_get_contents(base_path(roomDerivationsPath())), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded) || array_keys($decoded) !== ['bounds', 'cases', 'warningCases']) {
        throw new RuntimeException(roomDerivationsPath().' : { bounds, cases, warningCases } est attendu.');
    }

    if (! is_array($decoded['bounds']) || ! is_array($decoded['cases']) || ! is_array($decoded['warningCases'])) {
        throw new RuntimeException(roomDerivationsPath().' : forme inattendue.');
    }

    $cases = [];

    foreach (array_values($decoded['cases']) as $index => $case) {
        $where = roomDerivationsPath()." : cas n° {$index}";

        if (! is_array($case)
            || array_keys($case) !== ['n', 'd', 'r', 'tierDurations', 'tierPoints', 'attemptsPerRound', 'warnings']
            || ! is_int($case['n']) || ! is_int($case['d']) || ! is_int($case['r'])
            || ! is_int($case['attemptsPerRound'])) {
            throw new RuntimeException("{$where} mal formé.");
        }

        $cases[] = [
            'n' => $case['n'],
            'd' => $case['d'],
            'r' => $case['r'],
            'tierDurations' => roomDerivationsIntList($case['tierDurations'], $where),
            'tierPoints' => roomDerivationsIntList($case['tierPoints'], $where),
            'attemptsPerRound' => $case['attemptsPerRound'],
            'warnings' => roomDerivationsWarnings($case['warnings'], $where),
        ];
    }

    $warningCases = [];

    foreach (array_values($decoded['warningCases']) as $index => $case) {
        $where = roomDerivationsPath()." : cas d'avertissement n° {$index}";

        if (! is_array($case)
            || array_keys($case) !== ['revealDuration', 'tierDurations', 'tierPoints', 'warnings']
            || ! is_int($case['revealDuration'])) {
            throw new RuntimeException("{$where} mal formé.");
        }

        $warningCases[] = [
            'revealDuration' => $case['revealDuration'],
            'tierDurations' => roomDerivationsIntList($case['tierDurations'], $where),
            'tierPoints' => roomDerivationsIntList($case['tierPoints'], $where),
            'warnings' => roomDerivationsWarnings($case['warnings'], $where),
        ];
    }

    if ($cases === [] || $warningCases === []) {
        throw new RuntimeException(roomDerivationsPath().' : des cas sont attendus.');
    }

    return ['bounds' => $decoded['bounds'], 'cases' => $cases, 'warningCases' => $warningCases];
}

it('le jeu de dérivations partagé avec le client correspond au serveur', function (): void {
    $fixture = roomDerivations();

    // Les bornes que le client reçoit en prop, à l'identique : c'est d'elles
    // seules qu'il dérive.
    expect($fixture['bounds'])->toBe(RoomSettingsBounds::toClient());

    foreach ($fixture['cases'] as $case) {
        $label = "N = {$case['n']}, D = {$case['d']}, R = {$case['r']}";

        // Les dérivations du value object, une à une.
        expect(RoomSettingsBounds::defaultTierDurations($case['n'], $case['d']))->toBe($case['tierDurations'], $label)
            ->and(RoomSettingsBounds::defaultTierPoints($case['n']))->toBe($case['tierPoints'], $label)
            ->and(RoomSettingsBounds::defaultAttemptsPerRound($case['d']))->toBe($case['attemptsPerRound'], $label)
            ->and(RoomSettingsBounds::minRoundDuration($case['n']))->toBeLessThanOrEqual($case['d'], $label);

        // Le chemin de l'hôte : l'onglet Simple compose l'entrée avec les
        // réglages courants (règle D34 du 23/09), puis `fromInput()` — ce
        // que le serveur écrit réellement.
        $composed = RoomSettingsEditor::simple(RoomSettings::defaults(), [
            'framesPerRound' => $case['n'],
            RoomSettings::INPUT_ROUND_DURATION => $case['d'],
            'revealDuration' => $case['r'],
        ], []);
        $settings = RoomSettingsEditor::toSettings($composed['input']);

        expect($composed['changes'])->toBe([], $label)
            ->and($settings->framesPerRound)->toBe($case['n'], $label)
            ->and($settings->roundDuration())->toBe($case['d'], $label)
            ->and($settings->revealDuration)->toBe($case['r'], $label)
            ->and($settings->tierDurations)->toBe($case['tierDurations'], $label)
            ->and($settings->tierPoints)->toBe($case['tierPoints'], $label)
            ->and($settings->attemptsPerRound)->toBe($case['attemptsPerRound'], $label)
            ->and($settings->warnings())->toBe($case['warnings'], $label);
    }

    foreach ($fixture['warningCases'] as $case) {
        $label = json_encode($case, JSON_THROW_ON_ERROR);
        $settings = RoomSettings::fromInput([
            'framesPerRound' => count($case['tierPoints']),
            'tierDurations' => $case['tierDurations'],
            'tierPoints' => $case['tierPoints'],
            'revealDuration' => $case['revealDuration'],
        ]);

        expect($settings->tierDurations)->toBe($case['tierDurations'], $label)
            ->and($settings->tierPoints)->toBe($case['tierPoints'], $label)
            ->and($settings->warnings())->toBe($case['warnings'], $label);
    }
});

it('couvre chaque N des bornes, chaque reste de D et les deux côtés de chaque seuil', function (): void {
    $fixture = roomDerivations();
    $cases = collect($fixture['cases']);

    // Chaque N des bornes, et pour chacun chaque reste de D modulo N depuis
    // son minimum effectif, les deux bornes de D et la durée de chaque
    // preset de ce N.
    expect($cases->pluck('n')->unique()->sort()->values()->all())
        ->toBe(range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND));

    for ($frames = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $frames <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $frames++) {
        $durations = $cases->where('n', $frames)->pluck('d')->all();
        $min = RoomSettingsBounds::minRoundDuration($frames);

        expect($durations)->toContain(...range($min, $min + $frames))
            ->toContain(RoomSettingsBounds::MAX_ROUND_DURATION);
    }

    foreach (SettingPresetKey::cases() as $key) {
        $preset = SettingPresetCatalog::settingsFor($key);

        expect($cases->contains(
            static fn (array $case): bool => $case['n'] === $preset->framesPerRound && $case['d'] === $preset->roundDuration(),
        ))->toBeTrue($key->value);
    }

    // Plafond de `attemptsPerRound` : atteint et non atteint.
    expect($cases->pluck('attemptsPerRound')->all())
        ->toContain(RoomSettingsBounds::ATTEMPTS_PER_ROUND_SOFT_CAP)
        ->toContain(RoomSettingsBounds::ATTEMPTS_PER_ROUND_SOFT_CAP - 1);

    // Manche longue et révélation courte : de part et d'autre du seuil,
    // seuil compris.
    expect($cases->pluck('d')->all())
        ->toContain(RoomSettingsBounds::LONG_ROUND_WARNING_DURATION)
        ->toContain(RoomSettingsBounds::LONG_ROUND_WARNING_DURATION + 1)
        ->and($cases->pluck('r')->all())
        ->toContain(RoomSettingsBounds::RECOMMENDED_MIN_REVEAL_DURATION)
        ->toContain(RoomSettingsBounds::RECOMMENDED_MIN_REVEAL_DURATION - 1);

    // Les quatre avertissements, chacun levé et chacun absent, et un cas qui
    // les lève tous, dans l'ordre du serveur.
    $all = [
        RoomSettings::WARNING_SHORT_REVEAL,
        RoomSettings::WARNING_LONG_ROUND,
        RoomSettings::WARNING_NON_DECREASING_POINTS,
        RoomSettings::WARNING_ALL_TIERS_ZERO,
    ];
    $raised = collect([...$fixture['cases'], ...$fixture['warningCases']])->pluck('warnings');

    foreach ($all as $code) {
        expect($raised->contains(static fn (array $codes): bool => in_array($code, $codes, true)))->toBeTrue($code)
            ->and($raised->contains(static fn (array $codes): bool => ! in_array($code, $codes, true)))->toBeTrue($code);
    }

    expect($raised->contains(static fn (array $codes): bool => $codes === $all))->toBeTrue();
});

it('rend exactement le fichier versionné par room:derivations-fixture --check, sans l’écrire', function (): void {
    $path = base_path(roomDerivationsPath());
    $before = md5_file($path);

    // Le fichier versionné est la sortie de la commande, octet pour octet :
    // un rendu qui aurait dérivé (bornes changées sans relancer la commande)
    // échoue ici avant la comparaison champ par champ.
    expect(RoomDerivationsFixtureCommand::render())->toBe((string) file_get_contents($path));

    $this->artisan('room:derivations-fixture', ['--check' => true])
        ->assertSuccessful();

    expect(md5_file($path))->toBe($before);
});

it('n’enregistre room:derivations-fixture qu’hors de la production', function (): void {
    expect(Artisan::all())->toHaveKey('room:derivations-fixture');

    $command = new RoomDerivationsFixtureCommand;
    $command->setLaravel(app());
    $environment = app()->environment();

    try {
        app()->instance('env', 'production');

        expect($command->isEnabled())->toBeFalse();

        app()->instance('env', 'local');

        expect($command->isEnabled())->toBeTrue();
    } finally {
        app()->instance('env', $environment);
    }
});
