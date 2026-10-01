<?php

use App\Enums\FrameLevel;
use App\Models\MovieProjection;
use App\Settings\RoomSettingsBounds;
use App\ValueObjects\Catalog\FrameLevelCoverage;

/*
|--------------------------------------------------------------------------
| Couverture de niveaux — spec 30 § 2, lot L30-1, contrat C1
|--------------------------------------------------------------------------
|
| La répartition nominale `N` → niveaux n'est ÉCRITE que dans
| `FrameLevelCoverage` (garde : `DrawBoundaryTest`). Ce fichier est son seul
| oracle et la restate donc en clair, comme `00` § Le jeu en une manche la
| donne : un test qui relirait `nominal()` pour fixer son attendu ne
| prouverait rien.
|
| Les propriétés générales (équivalence avec `supportsFramesPerRound`,
| monotonie, départage) sont vérifiées sur TOUS les masques de l'échelle et
| tous les `N` des bornes : 32 masques × 4 `N`, un balayage exhaustif qui
| coûte moins qu'un échantillon à justifier.
|
*/

/**
 * Répartition nominale de `00` § Le jeu en une manche, en niveaux entiers.
 *
 * @return array<int, list<int>>
 */
function frameLevelCoverageNominalTable(): array
{
    return [
        2 => [1, 5],
        3 => [1, 3, 5],
        4 => [1, 2, 4, 5],
        5 => [1, 2, 3, 4, 5],
    ];
}

/**
 * Plages de niveaux par palier (D45 du 01/10), en niveaux entiers.
 *
 * @return array<int, list<list<int>>>
 */
function frameLevelCoverageBandsTable(): array
{
    return [
        2 => [[1, 2], [4, 5]],
        3 => [[1, 2], [2, 3, 4], [4, 5]],
        4 => [[1], [2, 3], [4], [5]],
        5 => [[1], [2], [3], [4], [5]],
    ];
}

/**
 * Les séquences strictement croissantes de `N` niveaux du masque qui placent
 * chaque palier dans sa plage, en ordre lexicographique — oracle écrit sans la
 * classe testée.
 *
 * @return list<list<int>>
 */
function frameLevelCoverageInBands(int $framesPerRound, int $mask): array
{
    $bands = frameLevelCoverageBandsTable()[$framesPerRound];
    $sequences = [[]];

    foreach ($bands as $band) {
        $next = [];

        foreach ($sequences as $prefix) {
            foreach ($band as $level) {
                if (($mask & (1 << ($level - 1))) !== 0 && ($prefix === [] || $level > end($prefix))) {
                    $next[] = [...$prefix, $level];
                }
            }
        }

        $sequences = $next;
    }

    usort($sequences, static fn (array $a, array $b): int => $a <=> $b);

    return $sequences;
}

/**
 * Tous les `N` des bornes du salon.
 *
 * @return list<int>
 */
function frameLevelCoverageFramesPerRound(): array
{
    return range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND);
}

/**
 * Tous les masques de l'échelle, de 0 au masque complet.
 *
 * @return list<int>
 */
function frameLevelCoverageMasks(): array
{
    return range(0, FrameLevelCoverage::maskOf(FrameLevel::cases()));
}

/**
 * Masque d'une liste de niveaux entiers, calculé sans passer par la classe
 * testée : `1 << (niveau − 1)`, la disposition de `movie_projection.levels_mask`.
 *
 * @param  list<int>  $levels
 */
function frameLevelCoverageMask(array $levels): int
{
    return array_reduce($levels, static fn (int $mask, int $level): int => $mask | (1 << ($level - 1)), 0);
}

/**
 * Nombre de niveaux distincts d'un masque, compté sans passer par la classe
 * testée.
 */
function frameLevelCoveragePopcount(int $mask): int
{
    return substr_count(decbin($mask), '1');
}

/**
 * @param  list<FrameLevel>|null  $levels
 * @return list<int>|null
 */
function frameLevelCoverageValues(?array $levels): ?array
{
    return $levels === null ? null : array_map(static fn (FrameLevel $level): int => $level->value, $levels);
}

it('la répartition nominale est celle de 00 pour chaque N dans les bornes', function () {
    $table = frameLevelCoverageNominalTable();

    // La table couvre exactement les bornes : un N ajouté aux bornes sans
    // répartition, ou une répartition orpheline, fait échouer ce test.
    expect(array_keys($table))->toBe(frameLevelCoverageFramesPerRound());

    foreach ($table as $framesPerRound => $levels) {
        $nominal = FrameLevelCoverage::nominal($framesPerRound);

        expect($nominal)->toHaveCount($framesPerRound)
            ->and(array_is_list($nominal))->toBeTrue()
            ->and(frameLevelCoverageValues($nominal))->toBe($levels);
    }
});

it('nominal refuse un N hors des bornes de RoomSettingsBounds', function (int $framesPerRound) {
    expect(fn () => FrameLevelCoverage::nominal($framesPerRound))->toThrow(InvalidArgumentException::class);
})->with([
    'sous la borne basse' => [RoomSettingsBounds::MIN_FRAMES_PER_ROUND - 1],
    'au-dessus de la borne haute' => [RoomSettingsBounds::MAX_FRAMES_PER_ROUND + 1],
    'zéro' => [0],
    'négatif' => [-1],
    'entier maximal' => [PHP_INT_MAX],
]);

it('select rend la répartition nominale quand la banque la couvre', function () {
    $fullMask = FrameLevelCoverage::maskOf(FrameLevel::cases());

    foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
        $nominal = FrameLevelCoverage::nominal($framesPerRound);
        $nominalMask = FrameLevelCoverage::maskOf($nominal);

        // Banque complète : `nominal(N) == select(N, 31)`.
        expect(FrameLevelCoverage::select($framesPerRound, $fullMask))->toBe($nominal);

        // Toute banque qui contient les niveaux nominaux, quels que soient ses
        // niveaux en plus.
        foreach (frameLevelCoverageMasks() as $mask) {
            if (($mask & $nominalMask) !== $nominalMask) {
                continue;
            }

            expect(FrameLevelCoverage::select($framesPerRound, $mask))->toBe($nominal)
                ->and(FrameLevelCoverage::usesFallback($framesPerRound, $mask))->toBeFalse();
        }
    }
});

it('select se replie sur les niveaux disponibles les plus proches sans doublon', function (int $framesPerRound, array $bank, ?array $expected) {
    $selected = FrameLevelCoverage::select($framesPerRound, frameLevelCoverageMask($bank));

    expect(frameLevelCoverageValues($selected))->toBe($expected);

    if ($selected === null) {
        return;
    }

    $values = frameLevelCoverageValues($selected);

    // N niveaux distincts, croissants, tous présents dans la banque.
    expect($selected)->toHaveCount($framesPerRound)
        ->and(array_unique($values))->toBe($values)
        ->and($values)->toBe(collect($values)->sort()->values()->all())
        ->and(array_diff($values, $bank))->toBe([]);
})->with([
    // Table de cas normative, spec 30 § 2.3.
    'N=3 banque 1,2,3,4,5 : nominal' => [3, [1, 2, 3, 4, 5], [1, 3, 5]],
    'N=3 banque 1,2,4,5 : égalité, le plus cryptique gagne' => [3, [1, 2, 4, 5], [1, 2, 5]],
    'N=3 banque 1,2,3,5 : nominal' => [3, [1, 2, 3, 5], [1, 3, 5]],
    'N=3 banque 2,3,4 : le palier 1 montre un niveau 2' => [3, [2, 3, 4], [2, 3, 4]],
    'N=2 banque 1,3,5 : nominal' => [2, [1, 3, 5], [1, 5]],
    'N=2 banque 1,2,3' => [2, [1, 2, 3], [1, 3]],
    'N=2 banque 2,3' => [2, [2, 3], [2, 3]],
    'N=4 banque 1,2,3,4,5 : nominal' => [4, [1, 2, 3, 4, 5], [1, 2, 4, 5]],
    'N=4 banque 1,2,3,5 : un seul niveau de passe 2 suffit' => [4, [1, 2, 3, 5], [1, 2, 3, 5]],
    'N=4 banque 1,3,5 : passe 1 seule' => [4, [1, 3, 5], null],
    'N=5 banque 1,2,3,4' => [5, [1, 2, 3, 4], null],
]);

it('select départage une égalité de coût vers le niveau le plus cryptique', function () {
    // Le cas de la spec : 1,2,5 et 1,4,5 coûtent tous deux 1 à N=3.
    expect(frameLevelCoverageValues(FrameLevelCoverage::select(3, frameLevelCoverageMask([1, 2, 4, 5]))))
        ->toBe([1, 2, 5]);

    // Et partout : le choix est de coût minimal, et toute autre combinaison
    // de même coût est lexicographiquement plus évidente. Oracle écrit sans la
    // classe testée : énumération brute des sous-ensembles du masque.
    $ties = 0;

    foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
        $nominal = frameLevelCoverageNominalTable()[$framesPerRound];

        foreach (frameLevelCoverageMasks() as $mask) {
            $selected = frameLevelCoverageValues(FrameLevelCoverage::select($framesPerRound, $mask));

            if ($selected === null) {
                continue;
            }

            $cost = static function (array $levels) use ($nominal): int {
                $sum = 0;

                foreach ($levels as $index => $level) {
                    $sum += abs($level - $nominal[$index]);
                }

                return $sum;
            };

            $candidates = [];

            foreach (frameLevelCoverageMasks() as $subset) {
                if (($subset & $mask) === $subset && frameLevelCoveragePopcount($subset) === $framesPerRound) {
                    $levels = array_values(array_filter(
                        range(1, count(FrameLevel::cases())),
                        static fn (int $level): bool => ($subset & (1 << ($level - 1))) !== 0,
                    ));

                    $candidates[] = $levels;
                }
            }

            $minimum = min(array_map($cost, $candidates));
            $optimal = array_values(array_filter($candidates, static fn (array $levels): bool => $cost($levels) === $minimum));

            usort($optimal, static fn (array $a, array $b): int => $a <=> $b);

            expect($cost($selected))->toBe($minimum)
                ->and($selected)->toBe($optimal[0]);

            if (count($optimal) > 1) {
                $ties++;
            }
        }
    }

    // Le balayage rencontre bien des égalités : sinon il serait vert pour de
    // mauvaises raisons.
    expect($ties)->toBeGreaterThan(0);
});

it('select rend null sous N niveaux distincts, exactement comme supportsFramesPerRound', function () {
    foreach (frameLevelCoverageMasks() as $mask) {
        $projection = new MovieProjection([
            'levels_mask' => $mask,
            'levels_count' => frameLevelCoveragePopcount($mask),
        ]);

        foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
            $selected = FrameLevelCoverage::select($framesPerRound, $mask);

            expect($selected === null)->toBe(frameLevelCoveragePopcount($mask) < $framesPerRound)
                ->and($selected !== null)->toBe($projection->supportsFramesPerRound($framesPerRound));
        }
    }
});

it("l'éligibilité est monotone en N", function () {
    foreach (frameLevelCoverageMasks() as $mask) {
        foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
            if (FrameLevelCoverage::select($framesPerRound, $mask) === null) {
                continue;
            }

            foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, $framesPerRound) as $smaller) {
                expect(FrameLevelCoverage::select($smaller, $mask))->not->toBeNull(
                    "masque {$mask} jouable à N={$framesPerRound} mais pas à N={$smaller}",
                );
            }
        }
    }
});

it('le nombre de cas de FrameLevel égale la borne haute de N', function () {
    expect(FrameLevel::cases())->toHaveCount(RoomSettingsBounds::MAX_FRAMES_PER_ROUND)
        ->and(FrameLevelCoverage::nominal(RoomSettingsBounds::MAX_FRAMES_PER_ROUND))->toBe(FrameLevel::cases())
        ->and(FrameLevelCoverage::levelsIn(FrameLevelCoverage::maskOf(FrameLevel::cases())))->toBe(FrameLevel::cases());
});

it('un masque hors bornes lève InvalidArgumentException', function (int $mask) {
    expect(fn () => FrameLevelCoverage::levelsIn($mask))->toThrow(InvalidArgumentException::class)
        ->and(fn () => FrameLevelCoverage::select(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, $mask))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => FrameLevelCoverage::usesFallback(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, $mask))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'négatif' => [-1],
    'juste au-dessus du masque complet' => [1 << count(FrameLevel::cases())],
    'bit lointain' => [1 << 10],
    'entier maximal' => [PHP_INT_MAX],
]);

it('les plages de chaque palier sont celles de D45 et contiennent la répartition nominale', function () {
    $table = frameLevelCoverageBandsTable();

    expect(array_keys($table))->toBe(frameLevelCoverageFramesPerRound());

    foreach ($table as $framesPerRound => $bands) {
        $actual = array_map(
            static fn (array $band): array => frameLevelCoverageValues($band) ?? [],
            FrameLevelCoverage::bands($framesPerRound),
        );
        $nominal = frameLevelCoverageNominalTable()[$framesPerRound];

        expect($actual)->toBe($bands);

        foreach ($nominal as $index => $level) {
            expect($bands[$index])->toContain($level);
        }
    }

    expect(fn () => FrameLevelCoverage::bands(RoomSettingsBounds::MAX_FRAMES_PER_ROUND + 1))
        ->toThrow(InvalidArgumentException::class);
});

it('sequences rend les séquences croissantes dans les plages, sinon la seule séquence de repli', function () {
    foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
        foreach (frameLevelCoverageMasks() as $mask) {
            $sequences = FrameLevelCoverage::sequences($framesPerRound, $mask);
            $inBands = frameLevelCoverageInBands($framesPerRound, $mask);
            $selected = FrameLevelCoverage::select($framesPerRound, $mask);

            if ($selected === null) {
                expect($sequences)->toBeNull();

                continue;
            }

            $values = array_map(static fn (array $sequence): array => frameLevelCoverageValues($sequence) ?? [], $sequences ?? []);

            expect($values)->toBe($inBands === [] ? [frameLevelCoverageValues($selected)] : $inBands)
                // La séquence de référence est toujours admissible.
                ->and($sequences)->toContain($selected);
        }
    }

    // N = 3, banque 1,2,3,4,5 : les huit séquences admissibles.
    expect(frameLevelCoverageInBands(3, frameLevelCoverageMask([1, 2, 3, 4, 5])))->toBe([
        [1, 2, 4], [1, 2, 5], [1, 3, 4], [1, 3, 5], [1, 4, 5], [2, 3, 4], [2, 3, 5], [2, 4, 5],
    ]);
});

it('usesFallback est vrai exactement quand aucune séquence ne tient dans les plages', function () {
    foreach (frameLevelCoverageFramesPerRound() as $framesPerRound) {
        foreach (frameLevelCoverageMasks() as $mask) {
            $playable = frameLevelCoveragePopcount($mask) >= $framesPerRound;

            expect(FrameLevelCoverage::usesFallback($framesPerRound, $mask))
                ->toBe($playable && frameLevelCoverageInBands($framesPerRound, $mask) === []);
        }
    }

    // Les deux prédicats de spec 30 § 2.5 ne se confondent pas.
    expect(FrameLevelCoverage::usesFallback(4, frameLevelCoverageMask([1, 2, 3, 5])))->toBeTrue()
        ->and(FrameLevelCoverage::usesFallback(3, frameLevelCoverageMask([2, 3, 4, 5])))->toBeFalse()
        ->and(FrameLevelCoverage::usesFallback(2, frameLevelCoverageMask([1, 2, 3])))->toBeTrue()
        ->and(FrameLevelCoverage::usesFallback(3, frameLevelCoverageMask([1, 3, 5])))->toBeFalse()
        // Injouable n'est pas « en repli » : il n'y a rien à montrer.
        ->and(FrameLevelCoverage::usesFallback(4, frameLevelCoverageMask([1, 3, 5])))->toBeFalse();
});
