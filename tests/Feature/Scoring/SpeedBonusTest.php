<?php

use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Scoring\ScoreCalculator;
use App\Support\Scoring\ScoringRules;
use App\ValueObjects\Scoring\TierSchedule;

/*
|--------------------------------------------------------------------------
| B_max sur toute la matrice des réglages acceptés — spec 80 § 3, lot L80-1
|--------------------------------------------------------------------------
|
| Exemple de consommation du jeu `room_settings.accepted` (contrat C18, spec
| 100 § 4) : chaque combinaison que l'hôte peut réellement poser. Le paramètre
| est typé `RoomSettings` : le jeu rend des fermetures liées, que Pest résout
| dans le test (E8-3).
|
| Pour chaque combinaison : `B_max(N)` est un pourcentage entier sous le
| plafond, tel que `(N − 1) × B_max ≤ 100` (§ 3.3) et tel que la prop client
| l'expose ; à l'ouverture de chaque palier le bonus vaut EXACTEMENT
| `intdiv(P × B_max, 100)`, et à sa dernière milliseconde il est nul — le fait
| qui porte la preuve du § 3.3 (§ 3.2).
|
*/

test('garde B_max en pourcentage entier pour chaque N accepté', function (RoomSettings $settings): void {
    $framesPerRound = $settings->framesPerRound;
    $percent = ScoringRules::speedBonusMaxPercent($framesPerRound);

    expect($percent)->toBeInt()
        ->toBeGreaterThanOrEqual(0)
        ->toBeLessThanOrEqual(PlatformLimits::SPEED_BONUS_MAX_PERCENT_CAP)
        ->and(($framesPerRound - 1) * $percent)->toBeLessThanOrEqual(PlatformLimits::FULL_PERCENT)
        ->and(PlatformLimits::current()->toArray()['speedBonusMaxPercent'][$framesPerRound])->toBe($percent);

    $tiers = TierSchedule::fromSettings($settings);
    $grace = PlatformLimits::tierGraceMs();

    expect($tiers->count())->toBe($framesPerRound);

    foreach ($tiers->tiers as $tier) {
        expect($tier->startsAtOffsetMs)->toBe($settings->tierStartOffsetMs($tier->tierIndex));

        $opening = ScoreCalculator::score($tier->startsAtOffsetMs + $grace, $tiers, $grace, true, $percent, ScoringRules::VERSION);
        $closing = ScoreCalculator::score($tier->startsAtOffsetMs + $tier->durationMs - 1 + $grace, $tiers, $grace, true, $percent, ScoringRules::VERSION);

        expect($opening->tierIndex)->toBe($tier->tierIndex)
            ->and($opening->pointsBonus)->toBe(intdiv($tier->points * $percent, PlatformLimits::FULL_PERCENT))
            ->and($closing->tierIndex)->toBe($tier->tierIndex)
            ->and($closing->pointsBonus)->toBe(0)
            ->and($closing->pointsTotal)->toBe($tier->points);
    }
})->with('room_settings.accepted');
