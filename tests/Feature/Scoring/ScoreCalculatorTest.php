<?php

use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Scoring\ScoreCalculator;
use App\Support\Scoring\ScoringRules;
use App\Support\Scoring\UnsupportedScoringVersion;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierScore;
use App\ValueObjects\Scoring\TierWindow;

/*
|--------------------------------------------------------------------------
| La fonction pure du score — spec 80 § 3 et § 4, contrat C13 (lot L80-1)
|--------------------------------------------------------------------------
|
| Une seule fonction rend ENSEMBLE le palier retenu et les points : la saisie
| l'appelle au verrouillage, le rejeu à l'identique. Tout est entier : un
| point d'écart entre le direct et le rejeu suffit à perdre un litige de
| score (§ 3.2).
|
| Les bornes sont lues dans `RoomSettingsBounds` et `PlatformLimits` ; les
| seuls littéraux sont les exemples chiffrés de la spec, qu'un test DOIT
| épingler tels quels, et les décalages d'une milliseconde autour d'une
| frontière.
|
*/

/**
 * Réglages d'hôte par le chemin d'entrée, aux seuls champs donnés.
 *
 * @param  array<string, mixed>  $input
 */
function scoreCalculatorSettings(array $input = []): RoomSettings
{
    return RoomSettings::fromInput($input);
}

/** Une partie figée sur ces réglages, non persistée (le calcul n'en lit que les attributs). */
function scoreCalculatorGame(RoomSettings $settings): Game
{
    return Game::factory()->withSettings($settings)->make();
}

/**
 * Le score d'une réponse reçue à `$answeredAtMs`, sous la règle courante, avec la
 * grâce de la plateforme et `B_max(N)` du calendrier.
 */
function scoreCalculatorScore(
    TierSchedule $tiers,
    int $answeredAtMs,
    bool $speedBonus = true,
    int $floorTierIndex = 1,
): TierScore {
    return ScoreCalculator::score(
        $answeredAtMs,
        $tiers,
        PlatformLimits::tierGraceMs(),
        $speedBonus,
        ScoringRules::speedBonusMaxPercent($tiers->count()),
        ScoringRules::VERSION,
        $floorTierIndex,
    );
}

/**
 * Chaque `N` des bornes, avec son calendrier au découpage et au barème par
 * défaut, à la durée de manche par défaut.
 *
 * @return array<int, TierSchedule>
 */
function scoreCalculatorDefaultSchedules(): array
{
    $schedules = [];

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $schedules[$framesPerRound] = TierSchedule::fromSettings(scoreCalculatorSettings(['framesPerRound' => $framesPerRound]));
    }

    return $schedules;
}

test('une réponse reçue à borne + (tier_grace_ms − 1) retient le palier précédent', function (): void {
    $grace = PlatformLimits::tierGraceMs();

    expect($grace)->toBeGreaterThan(0);

    foreach (scoreCalculatorDefaultSchedules() as $framesPerRound => $tiers) {
        for ($tierIndex = 1; $tierIndex < $framesPerRound; $tierIndex++) {
            $boundary = $tiers->tier($tierIndex + 1)->startsAtOffsetMs;
            $score = scoreCalculatorScore($tiers, $boundary + $grace - 1);

            // L'instant corrigé est la dernière milliseconde du palier précédent.
            expect($score->tierIndex)->toBe($tierIndex)
                ->and($score->pointsTier)->toBe($tiers->tier($tierIndex)->points)
                ->and($score->pointsBonus)->toBe(0);
        }
    }
});

test('une réponse reçue à borne + (tier_grace_ms + 1) retient le palier suivant', function (): void {
    $grace = PlatformLimits::tierGraceMs();
    $percent = static fn (TierSchedule $tiers): int => ScoringRules::speedBonusMaxPercent($tiers->count());

    foreach (scoreCalculatorDefaultSchedules() as $framesPerRound => $tiers) {
        for ($tierIndex = 1; $tierIndex < $framesPerRound; $tierIndex++) {
            $next = $tiers->tier($tierIndex + 1);
            $atBoundary = scoreCalculatorScore($tiers, $next->startsAtOffsetMs + $grace);
            $justAfter = scoreCalculatorScore($tiers, $next->startsAtOffsetMs + $grace + 1);

            // À borne + grâce exactement, l'instant corrigé EST l'ouverture : t = 0.
            expect($atBoundary->tierIndex)->toBe($tierIndex + 1)
                ->and($atBoundary->pointsBonus)->toBe(intdiv($next->points * $percent($tiers), PlatformLimits::FULL_PERCENT))
                ->and($justAfter->tierIndex)->toBe($tierIndex + 1)
                ->and($justAfter->pointsTier)->toBe($next->points)
                ->and($justAfter->pointsBonus)->toBeLessThanOrEqual($atBoundary->pointsBonus);
        }
    }
});

test('le bonus est un plancher entier : P = 100, B_max = 50 %, d = 5 000 ms, t = 1 700 ms donne 33', function (): void {
    $tiers = new TierSchedule([
        new TierWindow(1, 0, 5_000, 200),
        new TierWindow(2, 5_000, 5_000, 100),
    ]);
    $grace = PlatformLimits::tierGraceMs();

    $score = ScoreCalculator::score(5_000 + 1_700 + $grace, $tiers, $grace, true, 50, ScoringRules::VERSION);

    expect($score->toArray())->toBe([
        'tierIndex' => 2,
        'pointsTier' => 100,
        'pointsBonus' => 33,
        'pointsTotal' => 133,
    ]);

    // Le flottant, lui, perd un point : c'est exactement l'écart que le calcul
    // entier ferme entre le direct et le rejeu.
    expect((int) floor(100 * 0.5 * (1 - 1_700 / 5_000)))->toBe(32);
});

test('le bonus vaut exactement intdiv(P × B_max, 100) à t = 0 et ne le dépasse jamais', function (): void {
    $violations = [];
    $pointsSamples = array_values(array_unique([
        RoomSettingsBounds::MIN_TIER_POINTS,
        RoomSettingsBounds::MIN_TIER_POINTS + 1,
        RoomSettingsBounds::TIER_POINTS_UNIT - 1,
        RoomSettingsBounds::TIER_POINTS_UNIT,
        RoomSettingsBounds::TIER_POINTS_UNIT * 3 + 33,
        RoomSettingsBounds::MAX_TIER_POINTS - 1,
        RoomSettingsBounds::MAX_TIER_POINTS,
    ]));

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $percent = ScoringRules::speedBonusMaxPercent($framesPerRound);
        // Le palier le plus court et le plus long qu'une partie à N images admet.
        $shortestMs = RoomSettingsBounds::MIN_TIER_DURATION * 1_000;
        $longestMs = RoomSettingsBounds::maxTierDuration($framesPerRound) * 1_000;

        foreach ($pointsSamples as $points) {
            $ceiling = intdiv($points * $percent, PlatformLimits::FULL_PERCENT);

            foreach ([$shortestMs => 1, $longestMs => 997] as $durationMs => $stride) {
                // Le calcul ne voit que la fenêtre retenue : un palier unique suffit.
                $tiers = new TierSchedule([new TierWindow(1, 0, $durationMs, $points)]);
                $previous = null;
                $instants = range(0, $durationMs - 1, $stride);
                $instants[] = $durationMs - 1;

                foreach ($instants as $elapsedMs) {
                    $bonus = ScoreCalculator::score($elapsedMs, $tiers, 0, true, $percent, ScoringRules::VERSION)->pointsBonus;

                    if ($elapsedMs === 0 && $bonus !== $ceiling) {
                        $violations[] = "N={$framesPerRound} P={$points} d={$durationMs} : {$bonus} à t = 0, attendu {$ceiling}";
                    }

                    if ($bonus > $ceiling || $bonus < 0 || ($previous !== null && $bonus > $previous)) {
                        $violations[] = "N={$framesPerRound} P={$points} d={$durationMs} t={$elapsedMs} : {$bonus}";
                    }

                    $previous = $bonus;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

test('un palier à 0 rapporte 0, bonus compris', function (): void {
    $default = RoomSettingsBounds::defaultTierPoints(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND);
    $zeroSecond = $default;
    $zeroSecond[1] = RoomSettingsBounds::MIN_TIER_POINTS;

    $game = scoreCalculatorGame(scoreCalculatorSettings(['tierPoints' => $zeroSecond]));
    $tiers = TierSchedule::fromSettings($game->settings_snapshot);
    $second = $tiers->tier(2);

    expect($game->settings_snapshot->speedBonus)->toBeTrue();

    foreach ([$second->startsAtOffsetMs, $second->startsAtOffsetMs + intdiv($second->durationMs, 2)] as $corrected) {
        $score = ScoreCalculator::forGuess($game, $tiers, $corrected + $game->tier_grace_ms, GuessSource::Text);

        expect($score->toArray())->toBe([
            'tierIndex' => 2,
            'pointsTier' => 0,
            'pointsBonus' => 0,
            'pointsTotal' => 0,
        ]);
    }

    // Tous les paliers à 0 (mode sans score) : chaque bonne réponse vaut 0.
    $scoreless = scoreCalculatorGame(scoreCalculatorSettings([
        'tierPoints' => array_fill(0, RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, RoomSettingsBounds::MIN_TIER_POINTS),
    ]));
    $scorelessTiers = TierSchedule::fromSettings($scoreless->settings_snapshot);

    foreach ($scorelessTiers->tiers as $tier) {
        expect(ScoreCalculator::forGuess($scoreless, $scorelessTiers, $tier->startsAtOffsetMs, GuessSource::Text)->pointsTotal)->toBe(0);
    }
});

test('bonus désactivé : seule la valeur du palier compte', function (): void {
    $game = scoreCalculatorGame(scoreCalculatorSettings(['speedBonus' => false]));
    $tiers = TierSchedule::fromSettings($game->settings_snapshot);

    foreach ($tiers->tiers as $tier) {
        foreach ([$tier->startsAtOffsetMs, $tier->startsAtOffsetMs + $tier->durationMs - 1] as $corrected) {
            $score = ScoreCalculator::forGuess($game, $tiers, $corrected + $game->tier_grace_ms, GuessSource::Text);

            expect($score->tierIndex)->toBe($tier->tierIndex)
                ->and($score->pointsTier)->toBe($tier->points)
                ->and($score->pointsBonus)->toBe(0)
                ->and($score->pointsTotal)->toBe($tier->points);
        }
    }
});

test('B_max vaut 50, 50, 33 et 25 % pour N = 2, 3, 4 et 5', function (): void {
    $table = [];

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $table[$framesPerRound] = ScoringRules::speedBonusMaxPercent($framesPerRound);

        // Et c'est bien ce pourcentage que le calcul applique à une partie à N images.
        $game = scoreCalculatorGame(scoreCalculatorSettings(['framesPerRound' => $framesPerRound]));
        $tiers = TierSchedule::fromSettings($game->settings_snapshot);
        $opening = ScoreCalculator::forGuess($game, $tiers, 0, GuessSource::Text);

        expect($opening->pointsBonus)->toBe(intdiv($tiers->tier(1)->points * $table[$framesPerRound], PlatformLimits::FULL_PERCENT));
    }

    expect($table)->toBe([2 => 50, 3 => 50, 4 => 33, 5 => 25]);
});

test('au barème par défaut, attendre la frontière suivante ne rapporte jamais plus, pour tout N et toute durée légale', function (): void {
    $grace = PlatformLimits::tierGraceMs();
    $violations = [];
    $boundaries = 0;

    for ($framesPerRound = RoomSettingsBounds::MIN_FRAMES_PER_ROUND; $framesPerRound <= RoomSettingsBounds::MAX_FRAMES_PER_ROUND; $framesPerRound++) {
        $percent = ScoringRules::speedBonusMaxPercent($framesPerRound);

        for ($duration = RoomSettingsBounds::minRoundDuration($framesPerRound); $duration <= RoomSettingsBounds::MAX_ROUND_DURATION; $duration++) {
            $settings = scoreCalculatorSettings(['framesPerRound' => $framesPerRound, 'roundDuration' => $duration]);

            // Le découpage et le barème par défaut, et eux seuls.
            expect($settings->tierDurations)->toBe(RoomSettingsBounds::defaultTierDurations($framesPerRound, $duration))
                ->and($settings->tierPoints)->toBe(RoomSettingsBounds::defaultTierPoints($framesPerRound));

            $tiers = TierSchedule::fromSettings($settings);

            // Par la preuve du § 3.3, comparer la dernière milliseconde du palier i à la
            // première du palier i + 1 suffit : le score ne croît jamais DANS un palier.
            for ($tierIndex = 1; $tierIndex < $framesPerRound; $tierIndex++) {
                $boundary = $tiers->tier($tierIndex + 1)->startsAtOffsetMs;
                $last = ScoreCalculator::score($boundary - 1 + $grace, $tiers, $grace, true, $percent, ScoringRules::VERSION);
                $first = ScoreCalculator::score($boundary + $grace, $tiers, $grace, true, $percent, ScoringRules::VERSION);
                $boundaries++;

                if ($last->tierIndex !== $tierIndex || $first->tierIndex !== $tierIndex + 1 || $first->pointsTotal > $last->pointsTotal) {
                    $violations[] = "N={$framesPerRound} D={$duration} frontière {$tierIndex}→".($tierIndex + 1)
                        ." : {$last->pointsTotal} puis {$first->pointsTotal}";
                }
            }
        }
    }

    expect($violations)->toBe([])
        ->and($boundaries)->toBeGreaterThan(0);
});

test('aux cas N = 3 et N = 5, le début du palier 2 rapporte exactement la valeur du palier 1', function (): void {
    $grace = PlatformLimits::tierGraceMs();

    foreach ([3, 5] as $framesPerRound) {
        for ($duration = RoomSettingsBounds::minRoundDuration($framesPerRound); $duration <= RoomSettingsBounds::MAX_ROUND_DURATION; $duration++) {
            $tiers = TierSchedule::fromSettings(scoreCalculatorSettings(['framesPerRound' => $framesPerRound, 'roundDuration' => $duration]));
            $boundary = $tiers->tier(2)->startsAtOffsetMs;

            $last = scoreCalculatorScore($tiers, $boundary - 1 + $grace);
            $first = scoreCalculatorScore($tiers, $boundary + $grace);

            expect($first->tierIndex)->toBe(2)
                ->and($first->pointsTotal)->toBe($tiers->tier(1)->points)
                ->and($last->pointsTotal)->toBe($tiers->tier(1)->points);
        }
    }

    // Les exemples de la spec, chiffrés : N = 3 au défaut (10 s par palier) et
    // Hardcore (45 s en cinq paliers de 9 s), grâce de 300 ms.
    expect($grace)->toBe(300);

    $default = TierSchedule::fromSettings(scoreCalculatorSettings());
    expect(scoreCalculatorScore($default, 10_299)->toArray())->toBe(['tierIndex' => 1, 'pointsTier' => 300, 'pointsBonus' => 0, 'pointsTotal' => 300])
        ->and(scoreCalculatorScore($default, 10_300)->toArray())->toBe(['tierIndex' => 2, 'pointsTier' => 200, 'pointsBonus' => 100, 'pointsTotal' => 300]);

    $hardcore = TierSchedule::fromSettings(scoreCalculatorSettings(['framesPerRound' => 5, 'roundDuration' => 45]));
    expect(scoreCalculatorScore($hardcore, 9_299)->toArray())->toBe(['tierIndex' => 1, 'pointsTier' => 500, 'pointsBonus' => 0, 'pointsTotal' => 500])
        ->and(scoreCalculatorScore($hardcore, 9_300)->toArray())->toBe(['tierIndex' => 2, 'pointsTier' => 400, 'pointsBonus' => 100, 'pointsTotal' => 500]);
});

test('un clic QCM en Normal reçu dans la grâce de T_N est crédité au palier N avec t = 0', function (): void {
    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        $game = scoreCalculatorGame(scoreCalculatorSettings([
            'framesPerRound' => $framesPerRound,
            'inputDifficulty' => InputDifficulty::Normal->value,
        ]));
        $tiers = TierSchedule::fromSettings($game->settings_snapshot);
        $last = $tiers->tier($framesPerRound);
        $percent = ScoringRules::speedBonusMaxPercent($framesPerRound);

        foreach ([0, intdiv($game->tier_grace_ms, 2), $game->tier_grace_ms - 1] as $delayMs) {
            $answeredAtMs = $last->startsAtOffsetMs + $delayMs;
            $click = ScoreCalculator::forGuess($game, $tiers, $answeredAtMs, GuessSource::Choice);
            $text = ScoreCalculator::forGuess($game, $tiers, $answeredAtMs, GuessSource::Text);
            $ceiling = intdiv($last->points * $percent, PlatformLimits::FULL_PERCENT);

            // Le clic est relevé au palier N, bonus maximal ; le texte garde la grâce.
            expect($click->toArray())->toBe([
                'tierIndex' => $framesPerRound,
                'pointsTier' => $last->points,
                'pointsBonus' => $ceiling,
                'pointsTotal' => $last->points + $ceiling,
            ])->and($text->tierIndex)->toBe($framesPerRound - 1);
        }
    }

    // L'exemple du § 4.5 : 20 150 ms en Normal, N = 3.
    $game = scoreCalculatorGame(scoreCalculatorSettings(['inputDifficulty' => InputDifficulty::Normal->value]));
    $tiers = TierSchedule::fromSettings($game->settings_snapshot);

    expect(ScoreCalculator::forGuess($game, $tiers, 20_150, GuessSource::Choice)->toArray())
        ->toBe(['tierIndex' => 3, 'pointsTier' => 100, 'pointsBonus' => 50, 'pointsTotal' => 150])
        ->and(ScoreCalculator::forGuess($game, $tiers, 20_150, GuessSource::Text)->toArray())
        ->toBe(['tierIndex' => 2, 'pointsTier' => 200, 'pointsBonus' => 1, 'pointsTotal' => 201]);
});

test('texte libre et clic QCM se notent à l’identique hors plancher', function (): void {
    $stepMs = 97;

    // Facile : le plancher vaut 1, il n'agit jamais.
    $easy = scoreCalculatorGame(scoreCalculatorSettings(['inputDifficulty' => InputDifficulty::Easy->value]));
    $easyTiers = TierSchedule::fromSettings($easy->settings_snapshot);
    $window = $easyTiers->durationMs() + $easy->tier_grace_ms;

    foreach ([...range(0, $window - 1, $stepMs), $window - 1] as $answeredAtMs) {
        expect(ScoreCalculator::forGuess($easy, $easyTiers, $answeredAtMs, GuessSource::Choice)
            ->equals(ScoreCalculator::forGuess($easy, $easyTiers, $answeredAtMs, GuessSource::Text)))->toBeTrue();
    }

    // Normal : identiques dès que l'instant corrigé est dans le palier N.
    $normal = scoreCalculatorGame(scoreCalculatorSettings(['inputDifficulty' => InputDifficulty::Normal->value]));
    $normalTiers = TierSchedule::fromSettings($normal->settings_snapshot);
    $from = $normalTiers->tier($normal->frames_per_round)->startsAtOffsetMs + $normal->tier_grace_ms;
    $window = $normalTiers->durationMs() + $normal->tier_grace_ms;

    foreach ([...range($from, $window - 1, $stepMs), $window - 1] as $answeredAtMs) {
        expect(ScoreCalculator::forGuess($normal, $normalTiers, $answeredAtMs, GuessSource::Choice)
            ->equals(ScoreCalculator::forGuess($normal, $normalTiers, $answeredAtMs, GuessSource::Text)))->toBeTrue();
    }
});

test('une version de règle inconnue est refusée', function (): void {
    $tiers = TierSchedule::fromSettings(scoreCalculatorSettings());
    $unknown = [ScoringRules::VERSION + 1, 0, -1];

    ScoringRules::assertSupported(ScoringRules::VERSION);

    foreach ($unknown as $version) {
        expect(fn () => ScoringRules::assertSupported($version))->toThrow(UnsupportedScoringVersion::class)
            ->and(fn () => ScoreCalculator::score(0, $tiers, PlatformLimits::tierGraceMs(), true, 50, $version))
            ->toThrow(UnsupportedScoringVersion::class)
            ->and(fn () => ScoringRules::speedBonusMaxPercent(RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, $version))
            ->toThrow(UnsupportedScoringVersion::class)
            ->and(fn () => ScoringRules::floorTierIndex(GuessSource::Text, InputDifficulty::Normal, RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND, $version))
            ->toThrow(UnsupportedScoringVersion::class);
    }

    // Une partie écrite sous une version que ce code ne porte pas n'est jamais notée.
    $game = Game::factory()->state(['scoring_version' => ScoringRules::VERSION + 1])->make();

    expect(fn () => ScoreCalculator::forGuess($game, $tiers, 0, GuessSource::Text))
        ->toThrow(UnsupportedScoringVersion::class);
});

test('un answered_at_ms hors de [0, D + tier_grace_ms) est refusé', function (): void {
    $game = scoreCalculatorGame(scoreCalculatorSettings());
    $tiers = TierSchedule::fromSettings($game->settings_snapshot);
    $end = $tiers->durationMs() + $game->tier_grace_ms;

    foreach ([-1, $end, $end + 1] as $answeredAtMs) {
        expect(fn () => ScoreCalculator::forGuess($game, $tiers, $answeredAtMs, GuessSource::Text))
            ->toThrow(InvalidArgumentException::class);
    }

    // Les deux bornes incluses : l'ouverture, et la dernière milliseconde de la fenêtre.
    expect(ScoreCalculator::forGuess($game, $tiers, 0, GuessSource::Text)->tierIndex)->toBe(1);

    $last = ScoreCalculator::forGuess($game, $tiers, $end - 1, GuessSource::Text);

    expect($last->tierIndex)->toBe($game->frames_per_round)
        ->and($last->pointsBonus)->toBe(0);

    // Sans grâce, la fenêtre s'arrête exactement à D.
    expect(fn () => ScoreCalculator::score($tiers->durationMs(), $tiers, 0, true, 50, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);
});

test('points_total = points_tier + points_bonus et tient en unsignedSmallInteger au plafond', function (): void {
    $unsignedSmallIntegerMax = 65_535;
    $ceiling = RoomSettingsBounds::MAX_TIER_POINTS
        + intdiv(RoomSettingsBounds::MAX_TIER_POINTS * PlatformLimits::SPEED_BONUS_MAX_PERCENT_CAP, PlatformLimits::FULL_PERCENT);

    expect($ceiling)->toBe(1_500)
        ->and($ceiling)->toBeLessThanOrEqual($unsignedSmallIntegerMax);

    foreach (range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND) as $framesPerRound) {
        // Tous les paliers au plafond du barème : le pire cas de chaque N.
        $game = scoreCalculatorGame(scoreCalculatorSettings([
            'framesPerRound' => $framesPerRound,
            'tierPoints' => array_fill(0, $framesPerRound, RoomSettingsBounds::MAX_TIER_POINTS),
        ]));
        $tiers = TierSchedule::fromSettings($game->settings_snapshot);
        $window = $tiers->durationMs() + $game->tier_grace_ms;

        foreach ([...range(0, $window - 1, 131), $window - 1] as $answeredAtMs) {
            $score = ScoreCalculator::forGuess($game, $tiers, $answeredAtMs, GuessSource::Text);

            expect($score->pointsTotal)->toBe($score->pointsTier + $score->pointsBonus)
                ->and($score->pointsTotal)->toBeLessThanOrEqual($ceiling);
        }

        $opening = ScoreCalculator::forGuess($game, $tiers, 0, GuessSource::Text);

        expect($opening->pointsTotal)->toBe(RoomSettingsBounds::MAX_TIER_POINTS
            + intdiv(RoomSettingsBounds::MAX_TIER_POINTS * ScoringRules::speedBonusMaxPercent($framesPerRound), PlatformLimits::FULL_PERCENT));
    }

    // Le plafond est atteint : N = 2 ou 3, palier à 1 000, t = 0.
    $top = ScoreCalculator::score(
        0,
        new TierSchedule([new TierWindow(1, 0, RoomSettingsBounds::MIN_TIER_DURATION * 1_000, RoomSettingsBounds::MAX_TIER_POINTS)]),
        0,
        true,
        PlatformLimits::SPEED_BONUS_MAX_PERCENT_CAP,
        ScoringRules::VERSION,
    );

    expect($top->pointsTotal)->toBe($ceiling);
});

// Ajout (hors intitulés de la spec) : préconditions du § 4.4, plancher en
// Expert et garde de taille de forGuess() (§ 4.1).
test('refuse un plancher, un B_max ou une grâce hors bornes, un clic QCM en Expert et un calendrier d’une autre taille que N', function (): void {
    $tiers = TierSchedule::fromSettings(scoreCalculatorSettings());
    $grace = PlatformLimits::tierGraceMs();
    $percent = ScoringRules::speedBonusMaxPercent($tiers->count());

    foreach ([0, $tiers->count() + 1] as $floor) {
        expect(fn () => ScoreCalculator::score(0, $tiers, $grace, true, $percent, ScoringRules::VERSION, $floor))
            ->toThrow(InvalidArgumentException::class);
    }

    foreach ([-1, PlatformLimits::FULL_PERCENT + 1] as $invalidPercent) {
        expect(fn () => ScoreCalculator::score(0, $tiers, $grace, true, $invalidPercent, ScoringRules::VERSION))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => ScoreCalculator::score(0, $tiers, -1, true, $percent, ScoringRules::VERSION))
        ->toThrow(InvalidArgumentException::class);

    // Bornes admises : plancher N, B_max nul ou entier, grâce nulle.
    expect(ScoreCalculator::score(0, $tiers, $grace, true, $percent, ScoringRules::VERSION, $tiers->count())->tierIndex)->toBe($tiers->count())
        ->and(ScoreCalculator::score(0, $tiers, 0, true, 0, ScoringRules::VERSION)->pointsBonus)->toBe(0);

    // Le plancher : 1 en texte libre et en Facile, N en Normal, refusé en Expert.
    $framesPerRound = $tiers->count();

    expect(ScoringRules::floorTierIndex(GuessSource::Text, InputDifficulty::Normal, $framesPerRound))->toBe(1)
        ->and(ScoringRules::floorTierIndex(GuessSource::Text, InputDifficulty::Expert, $framesPerRound))->toBe(1)
        ->and(ScoringRules::floorTierIndex(GuessSource::Choice, InputDifficulty::Easy, $framesPerRound))->toBe(1)
        ->and(ScoringRules::floorTierIndex(GuessSource::Choice, InputDifficulty::Normal, $framesPerRound))->toBe($framesPerRound)
        ->and(fn () => ScoringRules::floorTierIndex(GuessSource::Choice, InputDifficulty::Expert, $framesPerRound))->toThrow(LogicException::class);

    $expert = scoreCalculatorGame(scoreCalculatorSettings(['inputDifficulty' => InputDifficulty::Expert->value]));

    expect(fn () => ScoreCalculator::forGuess($expert, $tiers, 0, GuessSource::Choice))->toThrow(LogicException::class);

    // Le calendrier doit compter exactement frames_per_round paliers.
    $game = scoreCalculatorGame(scoreCalculatorSettings(['framesPerRound' => RoomSettingsBounds::MIN_FRAMES_PER_ROUND]));

    expect(fn () => ScoreCalculator::forGuess($game, $tiers, 0, GuessSource::Text))->toThrow(LogicException::class);
});

// Ajout : le calendrier du chemin de score (§ 4.1) et la seule formule de fenêtre.
test('TierSchedule lit round_tier trié par tier_index, refuse un calendrier incohérent et porte la seule formule de fenêtre', function (): void {
    $settings = scoreCalculatorSettings(['framesPerRound' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND]);
    $game = Game::factory()->withSettings($settings)->create();
    $round = Round::factory()->forGame($game)->running()->create();

    // Insérés à rebours : seul le tri par tier_index rend l'ordre du calendrier.
    foreach (array_reverse(range(1, $settings->framesPerRound)) as $tierIndex) {
        RoundTier::factory()->for($round)->atTier($tierIndex, settings: $settings)->create();
    }

    $fromRound = TierSchedule::fromRound($round);
    $windows = static fn (TierSchedule $schedule): array => array_map(
        static fn (TierWindow $window): array => $window->toArray(),
        $schedule->tiers,
    );

    expect($windows($fromRound))->toBe($windows(TierSchedule::fromSettings($settings)))
        ->and($fromRound->count())->toBe($settings->framesPerRound)
        ->and($fromRound->durationMs())->toBe((int) $round->tiers()->sum('duration_ms'));

    foreach ($fromRound->tiers as $window) {
        expect($window->startsAtOffsetMs)->toBe($settings->tierStartOffsetMs($window->tierIndex));
    }

    // RoundTier::containsOffsetMs() délègue à TierWindow::contains() : même verdict.
    foreach ($round->tiers()->get() as $tier) {
        $window = TierWindow::fromRoundTier($tier);
        $end = $tier->starts_at_offset_ms + $tier->duration_ms;

        foreach ([$tier->starts_at_offset_ms - 1, $tier->starts_at_offset_ms, $end - 1, $end] as $offset) {
            expect($tier->containsOffsetMs($offset))->toBe($window->contains($offset));
        }

        expect($tier->containsOffsetMs($tier->starts_at_offset_ms))->toBeTrue()
            ->and($tier->containsOffsetMs($end))->toBeFalse();
    }

    // Hors calendrier : décalage, rang.
    foreach ([-1, $fromRound->durationMs()] as $offset) {
        expect(fn () => $fromRound->containing($offset))->toThrow(InvalidArgumentException::class);
    }

    foreach ([0, $fromRound->count() + 1] as $tierIndex) {
        expect(fn () => $fromRound->tier($tierIndex))->toThrow(InvalidArgumentException::class);
    }

    // Calendriers incohérents.
    $incoherent = [
        'vide' => [],
        'indices non contigus' => [new TierWindow(1, 0, 5_000, 300), new TierWindow(3, 5_000, 5_000, 200)],
        'premier décalage non nul' => [new TierWindow(1, 1, 5_000, 300)],
        'trou entre deux paliers' => [new TierWindow(1, 0, 5_000, 300), new TierWindow(2, 5_001, 5_000, 200)],
        'durée nulle' => [new TierWindow(1, 0, 0, 300)],
        'valeur au-dessus du barème' => [new TierWindow(1, 0, 5_000, RoomSettingsBounds::MAX_TIER_POINTS + 1)],
        'valeur négative' => [new TierWindow(1, 0, 5_000, RoomSettingsBounds::MIN_TIER_POINTS - 1)],
    ];

    foreach ($incoherent as $label => $tiers) {
        expect(fn () => new TierSchedule($tiers))->toThrow(InvalidArgumentException::class, null, $label);
    }

    // Une manche sans palier matérialisé n'a pas de calendrier : aucun palier inventé.
    $bare = Round::factory()->forGame($game)->atSequence(2)->create();

    expect(fn () => TierSchedule::fromRound($bare))->toThrow(InvalidArgumentException::class);
});

// Ajout : l'état de fabrique que les lots suivants emploient pour tout score lu.
test('la fabrique de bonne réponse note par forGuess sur la manche matérialisée', function (): void {
    $settings = scoreCalculatorSettings(['inputDifficulty' => InputDifficulty::Normal->value]);
    $game = Game::factory()->withSettings($settings)->create();
    $round = Round::factory()->forGame($game)->running()->create();

    foreach (range(1, $settings->framesPerRound) as $tierIndex) {
        RoundTier::factory()->for($round)->atTier($tierIndex, settings: $settings)->create();
    }

    // Dans la grâce de T_N : le clic est relevé au palier N, le texte non.
    $answeredAtMs = $settings->tierStartOffsetMs($settings->framesPerRound) + $game->tier_grace_ms - 1;

    $click = Guess::factory()->forRound($round, Player::factory()->create())->scoredAt($answeredAtMs, GuessSource::Choice)->create();
    $text = Guess::factory()->forRound($round, Player::factory()->create())->withRank(2)->scoredAt($answeredAtMs)->create();

    foreach ([$click, $text] as $guess) {
        $stored = Guess::query()->findOrFail($guess->id);

        expect(TierScore::fromGuess($stored)->equals(
            ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $stored->source),
        ))->toBeTrue()
            ->and($stored->answered_at_ms)->toBe($answeredAtMs)
            ->and($stored->points_total)->toBe($stored->points_tier + $stored->points_bonus);
    }

    expect($click->source)->toBe(GuessSource::Choice)
        ->and($click->match_kind)->toBe(GuessMatchKind::Choice)
        ->and($click->tier_index)->toBe($settings->framesPerRound)
        ->and($text->source)->toBe(GuessSource::Text)
        ->and($text->tier_index)->toBe($settings->framesPerRound - 1);

    // Un bonus posé après le calcul garde points_total = points_tier + points_bonus :
    // le total se résout à l'expansion, sur le palier que scoredAt() a calculé.
    $firstTierMs = $settings->tierStartOffsetMs(1) + 1_000;
    $bonus = 40;
    $bonused = Guess::factory()->forRound($round, Player::factory()->create())->withRank(3)->scoredAt($firstTierMs)->withSpeedBonus($bonus)->create();
    $stored = Guess::query()->findOrFail($bonused->id);

    expect($stored->tier_index)->toBe(1)
        ->and($stored->points_tier)->toBe(ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $firstTierMs, GuessSource::Text)->pointsTier)
        ->and($stored->points_bonus)->toBe($bonus)
        ->and($stored->points_total)->toBe($stored->points_tier + $bonus);

    // Une manche sans palier matérialisé fait échouer la fixture.
    expect(fn () => Guess::factory()->scoredAt(0)->create())->toThrow(InvalidArgumentException::class);
});
