<?php

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\RoundStatus;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\PlatformLimits;
use App\Settings\RoomSettings;
use App\Support\Scoring\ScoreCalculator;
use App\Support\Scoring\ScoreReplayer;
use App\Support\Scoring\ScoringRules;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierScore;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Le rejeu d'une bonne réponse — spec 80 § 6.3, 10 § 7.5, contrat C13 (L80-2)
|--------------------------------------------------------------------------
|
| Le rejeu rappelle la fonction pure du score sur les seuls faits figés —
| `answered_at_ms` et `source` du `guess`, les lignes `round_tier`, et sur
| `game` la grâce de frontière, l'instantané des réglages, `N`, la difficulté
| de saisie et la version de règle — et doit redonner EXACTEMENT ce que le
| verrouillage a écrit.
|
| Les bonnes réponses sont notées par `GuessFactory::scoredAt()`, qui appelle
| `ScoreCalculator::forGuess()` comme la transaction de verrouillage ; les
| attendus sont épinglés en LITTÉRAUX (exemples chiffrés de la spec 80 § 3.3
| et § 4.5, ou calculés à la main et commentés), pour qu'un rejeu et une
| écriture fautifs de la même façon ne se donnent pas raison l'un l'autre.
|
*/

/**
 * Une manche en cours d'une partie figée sur ces réglages, ses `N` paliers
 * matérialisés comme au lancement.
 *
 * @param  array<string, mixed>  $gameState  Colonnes de `game` imposées (ex. `tier_grace_ms`).
 */
function scoreReplayRound(RoomSettings $settings, array $gameState = []): Round
{
    $game = Game::factory()->withSettings($settings)->create($gameState);
    $round = Round::factory()->forGame($game)->running()->create([
        'duration_ms' => $settings->roundDuration() * 1000,
    ]);

    foreach (range(1, $settings->framesPerRound) as $tierIndex) {
        RoundTier::factory()->for($round)->atTier($tierIndex, settings: $settings)->create();
    }

    return $round;
}

/**
 * Une bonne réponse notée au verrouillage, relue en base : le rejeu part de ce
 * qui a été écrit, jamais de l'instance qui l'a écrit.
 */
function scoreReplayGuess(Round $round, int $answeredAtMs, GuessSource $source, int $lockRank): Guess
{
    $guess = Guess::factory()
        ->forRound($round, Player::factory()->create())
        ->withRank($lockRank)
        ->scoredAt($answeredAtMs, $source)
        ->create();

    return Guess::query()->findOrFail($guess->id);
}

test('le rejeu de (answered_at_ms, round_tier, tier_grace_ms, settings_snapshot, scoring_version) redonne exactement tier_index et points_total', function (): void {
    // Chaque scénario : réglages, puis [instant reçu, source, palier, points_total] attendus.
    $scenarios = [
        // Spec 80 § 4.5 : N = 3, D = 30 s en trois paliers de 10 s (300 / 200 / 100),
        // bonus actif, B_max = 50 %, grâce de 300 ms, en Normal.
        'N = 3, réglage par défaut, Normal' => [
            RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Normal->value]),
            [
                [2_000, GuessSource::Text, 1, 424],
                [10_299, GuessSource::Text, 1, 300],
                [10_300, GuessSource::Text, 2, 300],
                [20_150, GuessSource::Text, 2, 201],
                [20_150, GuessSource::Choice, 3, 150],
                [22_000, GuessSource::Text, 3, 141],
            ],
        ],
        // N = 5, D = 45 s en cinq paliers de 9 s (500 … 100), B_max = 25 %, Normal :
        // - 0 → palier 1, t = 0 : 500 + intdiv(500 × 25, 100) = 625 ;
        // - 9 299 / 9 300 → l'égalité exacte du § 3.3 au preset Hardcore : 500 et 400 + 100 ;
        // - 36 150 en texte → corrigé 35 850, palier 4, t = 8 850 :
        //   200 + intdiv(200 × 25 × 150, 100 × 9 000) = 200 + 0 ;
        // - 36 150 en clic QCM → dans la grâce de T_N : plancher, palier 5, t = 0 :
        //   100 + intdiv(100 × 25, 100) = 125.
        'N = 5, 45 s, Normal, clic QCM dans la grâce de T_N' => [
            RoomSettings::fromInput([
                'framesPerRound' => 5,
                'roundDuration' => 45,
                'inputDifficulty' => InputDifficulty::Normal->value,
            ]),
            [
                [0, GuessSource::Text, 1, 625],
                [9_299, GuessSource::Text, 1, 500],
                [9_300, GuessSource::Text, 2, 500],
                [36_150, GuessSource::Text, 4, 200],
                [36_150, GuessSource::Choice, 5, 125],
            ],
        ],
        // L'interrupteur du bonus se lit dans settings_snapshot : désactivé, seule
        // la valeur du palier compte (§ 3.2).
        'N = 3, bonus désactivé dans l’instantané' => [
            RoomSettings::fromInput(['speedBonus' => false, 'inputDifficulty' => InputDifficulty::Normal->value]),
            [
                [2_000, GuessSource::Text, 1, 300],
                [10_300, GuessSource::Text, 2, 200],
                [20_150, GuessSource::Choice, 3, 100],
            ],
        ],
    ];

    foreach ($scenarios as $label => [$settings, $answers]) {
        $round = scoreReplayRound($settings);
        $game = $round->game;

        expect($game->scoring_version)->toBe(ScoringRules::VERSION, $label)
            ->and($game->tier_grace_ms)->toBe(PlatformLimits::tierGraceMs(), $label);

        foreach ($answers as $position => [$answeredAtMs, $source, $tierIndex, $pointsTotal]) {
            $context = "{$label} — {$answeredAtMs} ms, {$source->value}";
            $guess = scoreReplayGuess($round, $answeredAtMs, $source, $position + 1);
            $replayed = ScoreReplayer::replay($guess);

            // Ce que le verrouillage a écrit est l'attendu de la spec…
            expect($guess->tier_index)->toBe($tierIndex, $context)
                ->and($guess->points_total)->toBe($pointsTotal, $context)
                ->and($guess->points_total)->toBe($guess->points_tier + $guess->points_bonus, $context);

            // … et le rejeu le redonne exactement, les quatre parts comprises.
            expect($replayed->tierIndex)->toBe($guess->tier_index, $context)
                ->and($replayed->pointsTotal)->toBe($guess->points_total, $context)
                ->and($replayed->equals(TierScore::fromGuess($guess)))->toBeTrue($context);
        }
    }

    // Le rejeu lit les lignes `round_tier`, SEULE source du chemin de score
    // (§ 4.1, C13 § 2.2), jamais le calendrier que `settings_snapshot` dériverait :
    // la valeur du palier 1 figée en ligne diverge ici de celle de l'instantané.
    // Attendu : 500 + intdiv(500 × 50 × 8 300, 100 × 10 000) = 500 + 207 = 707 ;
    // un rejeu par `TierSchedule::fromSettings()` rendrait 300 + 124 = 424.
    $divergent = scoreReplayRound(RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Normal->value]));

    DB::table('round_tier')->where('round_id', $divergent->id)->where('tier_index', 1)->update(['points' => 500]);

    $fromRow = scoreReplayGuess($divergent, 2_000, GuessSource::Text, 1);
    $fromSnapshot = ScoreCalculator::forGuess(
        $divergent->game,
        TierSchedule::fromSettings($divergent->game->settings_snapshot),
        $fromRow->answered_at_ms,
        $fromRow->source,
    );

    expect($fromRow->tier_index)->toBe(1)
        ->and($fromRow->points_total)->toBe(707)
        ->and(ScoreReplayer::replay($fromRow)->pointsTotal)->toBe(707)
        ->and(ScoreReplayer::replay($fromRow)->equals(TierScore::fromGuess($fromRow)))->toBeTrue()
        ->and($fromSnapshot->pointsTotal)->toBe(424);

    // Le rejeu RECALCULE, il ne relit pas les colonnes de points : sur une ligne
    // altérée, il redonne l'attendu d'origine et l'écart devient visible.
    $round = scoreReplayRound(RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Normal->value]));
    $guess = scoreReplayGuess($round, 2_000, GuessSource::Text, 1);

    DB::table('guess')->where('id', $guess->id)->update([
        'tier_index' => 2,
        'points_tier' => 200,
        'points_bonus' => 0,
        'points_total' => 200,
    ]);

    $altered = Guess::query()->findOrFail($guess->id);
    $replayed = ScoreReplayer::replay($altered);

    expect($replayed->toArray())->toBe(['tierIndex' => 1, 'pointsTier' => 300, 'pointsBonus' => 124, 'pointsTotal' => 424])
        ->and($replayed->equals(TierScore::fromGuess($altered)))->toBeFalse();

    // Indifférent au statut de la manche : une manche annulée se rejoue à
    // l'identique, le journal restant cohérent là même où ses points ne comptent pas.
    $scored = scoreReplayGuess($round, 22_000, GuessSource::Text, 2);
    $round->forceFill(['status' => RoundStatus::Cancelled, 'cancelled_at' => now(), 'ended_at' => now()])->save();

    expect(ScoreReplayer::replay($scored)->equals(TierScore::fromGuess($scored)))->toBeTrue()
        ->and($scored->points_total)->toBe(141);
});

test('un changement de configuration de tier_grace_ms après la partie ne change pas le rejeu', function (): void {
    $settings = RoomSettings::fromInput(['inputDifficulty' => InputDifficulty::Normal->value]);
    $atLaunch = PlatformLimits::tierGraceMs();

    // La partie porte une grâce figée DIFFÉRENTE de celle de la plateforme.
    $frozenGrace = $atLaunch + 200;
    $round = scoreReplayRound($settings, ['tier_grace_ms' => $frozenGrace]);

    expect($round->game->tier_grace_ms)->toBe($frozenGrace)
        ->and($frozenGrace)->not->toBe(PlatformLimits::tierGraceMs());

    // Reçue entre les deux grâces après T₂ : la grâce figée la retient au palier 1,
    // celle de la plateforme l'aurait créditée au palier 2.
    $answeredAtMs = $settings->tierStartOffsetMs(2) + $atLaunch + 100;
    $guess = scoreReplayGuess($round, $answeredAtMs, GuessSource::Text, 1);
    $tiers = TierSchedule::fromRound($round);
    $underPlatform = static fn (): TierScore => ScoreCalculator::score(
        $answeredAtMs,
        $tiers,
        PlatformLimits::tierGraceMs(),
        $settings->speedBonus,
        ScoringRules::speedBonusMaxPercent($settings->framesPerRound),
        ScoringRules::VERSION,
    );

    expect($guess->tier_index)->toBe(1)
        ->and($underPlatform()->tierIndex)->toBe(2)
        ->and(ScoreReplayer::replay($guess)->equals(TierScore::fromGuess($guess)))->toBeTrue();

    // Une clé de configuration posée après la partie : ignorée par la plateforme
    // (note 2 du 23/09), et de toute façon jamais lue par le rejeu.
    platformLimitsConfigure(['tier_grace_ms' => 0]);

    expect(ScoreReplayer::replay($guess)->equals(TierScore::fromGuess($guess)))->toBeTrue();

    // Un nouveau DÉFAUT de code après la partie (une nouvelle version de règle,
    // § 6.1) : la plateforme change, la partie ancienne se rejoue sous sa colonne.
    $current = PlatformLimits::current();
    app()->instance(PlatformLimits::class, new PlatformLimits(...array_merge(get_object_vars($current), [
        'tierGraceMs' => 0,
    ])));

    expect(PlatformLimits::tierGraceMs())->toBe(0)
        ->and($underPlatform()->tierIndex)->toBe(2)
        ->and($underPlatform()->equals(TierScore::fromGuess($guess)))->toBeFalse();

    $replayed = ScoreReplayer::replay($guess);

    expect($replayed->equals(TierScore::fromGuess($guess)))->toBeTrue()
        ->and($replayed->tierIndex)->toBe(1)
        ->and($replayed->pointsTotal)->toBe($guess->points_total);
});
