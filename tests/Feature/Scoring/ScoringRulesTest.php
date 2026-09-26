<?php

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Models\Game;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Scoring\ScoreCalculator;
use App\Support\Scoring\ScoringRules;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierWindow;
use Database\Factories\GameFactory;

/*
|--------------------------------------------------------------------------
| La règle de score, versionnée — spec 80 § 6, contrat C13 (lot L80-1)
|--------------------------------------------------------------------------
|
| L'empreinte de la version 1 épingle, EN LITTÉRAUX, ce qu'une partie de
| version 1 a pu écrire : la table B_max, la chaîne de départage et des
| échantillons d'arrondi (§ 6.4). Aucune valeur n'y est lue dans une borne ou
| un défaut courant : un défaut qui changerait sans nouvelle version doit
| faire rougir ce fichier, jamais le suivre. Une modification de l'un d'eux
| ne passe qu'avec une incrémentation de `ScoringRules::VERSION` et une
| nouvelle branche ; ce test reste alors vert, et un second s'y ajoute.
|
*/

test('empreinte de la version 1 : table B_max, chaîne de départage, échantillons d’arrondi', function (): void {
    $version = 1;

    expect(ScoringRules::VERSION)->toBe($version);

    // Table B_max(N), en pourcentage entier (§ 3.1, D22 du 23/09).
    $table = [];

    foreach ([2, 3, 4, 5] as $framesPerRound) {
        $table[$framesPerRound] = ScoringRules::speedBonusMaxPercent($framesPerRound, $version);
    }

    expect($table)->toBe([2 => 50, 3 => 50, 4 => 33, 5 => 25]);

    // Chaîne de départage (§ 8.1).
    expect(ScoringRules::TIE_BREAK_CHAIN)->toBe([
        'score_desc',
        'correct_answers_desc',
        'total_answer_time_ms_asc',
        'tier_finds_desc',
    ]);

    // Échantillons d'arrondi : les lignes du § 4.5 — N = 3, trois paliers de 10 s
    // (300 / 200 / 100), bonus actif, B_max = 50 %, grâce de 300 ms.
    $tiers = new TierSchedule([
        new TierWindow(1, 0, 10_000, 300),
        new TierWindow(2, 10_000, 10_000, 200),
        new TierWindow(3, 20_000, 10_000, 100),
    ]);
    $rows = [
        'clic en Facile à 150' => [150, GuessSource::Choice, InputDifficulty::Easy, [1, 300, 150, 450]],
        '2 000' => [2_000, GuessSource::Text, InputDifficulty::Normal, [1, 300, 124, 424]],
        '9 000' => [9_000, GuessSource::Text, InputDifficulty::Normal, [1, 300, 19, 319]],
        '10 299' => [10_299, GuessSource::Text, InputDifficulty::Normal, [1, 300, 0, 300]],
        '10 300' => [10_300, GuessSource::Text, InputDifficulty::Normal, [2, 200, 100, 300]],
        '10 301' => [10_301, GuessSource::Text, InputDifficulty::Normal, [2, 200, 99, 299]],
        '20 150, texte, Normal' => [20_150, GuessSource::Text, InputDifficulty::Normal, [2, 200, 1, 201]],
        '20 150, clic QCM, Normal' => [20_150, GuessSource::Choice, InputDifficulty::Normal, [3, 100, 50, 150]],
        '22 000' => [22_000, GuessSource::Text, InputDifficulty::Normal, [3, 100, 41, 141]],
    ];

    foreach ($rows as $label => [$answeredAtMs, $source, $difficulty, [$tierIndex, $pointsTier, $pointsBonus, $pointsTotal]]) {
        $score = ScoreCalculator::score(
            $answeredAtMs,
            $tiers,
            300,
            true,
            ScoringRules::speedBonusMaxPercent(3, $version),
            $version,
            ScoringRules::floorTierIndex($source, $difficulty, 3, $version),
        );

        expect($score->toArray())->toBe([
            'tierIndex' => $tierIndex,
            'pointsTier' => $pointsTier,
            'pointsBonus' => $pointsBonus,
            'pointsTotal' => $pointsTotal,
        ], $label);
    }

    // P = 100, B_max = 50 %, d = 5 000 ms, t = 1 700 ms donne 33 (le flottant donnerait 32).
    $single = new TierSchedule([new TierWindow(1, 0, 5_000, 100)]);

    expect(ScoreCalculator::score(1_700, $single, 0, true, 50, $version)->pointsBonus)->toBe(33);
});

test('la fabrique de partie écrit ScoringRules::VERSION dans scoring_version', function (): void {
    $multiplayer = Game::factory()->create();
    $solo = Game::factory()->solo()->create();
    $configured = Game::factory()
        ->withSettings(RoomSettings::fromInput(['framesPerRound' => RoomSettingsBounds::MAX_FRAMES_PER_ROUND]))
        ->create();

    foreach ([$multiplayer, $solo, $configured] as $game) {
        expect(Game::query()->findOrFail($game->id)->scoring_version)->toBe(ScoringRules::VERSION);
    }

    // La constante provisoire de la fabrique n'existe plus : un seul domicile.
    expect(defined(GameFactory::class.'::SCORING_VERSION'))->toBeFalse();
});

// Ajout (hors intitulés de la spec) : le mode sans score (§ 2.5), que L80-3 et
// L80-5 lisent pour le classement et le podium.
test('isScoreless ne vaut vrai que si tous les paliers valent 0', function (): void {
    $framesPerRound = RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND;
    $allZero = array_fill(0, $framesPerRound, RoomSettingsBounds::MIN_TIER_POINTS);
    $oneScoring = $allZero;
    $oneScoring[$framesPerRound - 1] = RoomSettingsBounds::MIN_TIER_POINTS + 1;

    expect(ScoringRules::isScoreless(RoomSettings::fromInput(['tierPoints' => $allZero])))->toBeTrue()
        ->and(ScoringRules::isScoreless(RoomSettings::fromInput(['tierPoints' => $oneScoring])))->toBeFalse()
        ->and(ScoringRules::isScoreless(RoomSettings::defaults()))->toBeFalse();
});
