<?php

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use App\Support\Scoring\ScoreCalculator;
use App\ValueObjects\Scoring\TierSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Palier retenu autour de T_N — spec 70 § 9.2, contrat C10 § 4, C13 § 4.1
|--------------------------------------------------------------------------
|
| 70 n'écrit aucune formule de palier ni de points : la transaction de
| verrouillage appelle la fonction pure de 80 (`ScoreCalculator::forGuess`)
| et en écrit les quatre sorties telles quelles. Conséquence pour la saisie
| en Normal, où texte et QCM coexistent après `T_N` :
|
| - un TEXTE reçu juste après `T_N` peut encore être crédité au palier
|   `N − 1` : l'instant corrigé de la grâce (`answeredAtMs − tier_grace_ms`)
|   tombe avant `T_N` (principe 4) ;
| - un CLIC reçu au même instant est crédité au palier `N` : les propositions
|   n'existaient pas avant `T_N` (plancher du QCM, R-18) — intitulés du clic
|   livrés par le lot L70-9.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 18:20:00.375'));
});

it('un texte reçu à T_N + 100 ms est crédité au palier N − 1', function (): void {
    $finder = PlayerToken::mint(Locale::French);
    $witness = PlayerToken::mint(Locale::English);
    $target = SubmissionFixtures::movie('Harbour Lights');
    [$game, $round, [$finderSeat, $witnessSeat]] = SubmissionFixtures::openedRound(
        [$finder, $witness],
        SubmissionFixtures::settings(InputDifficulty::Normal),
        $target,
    );

    $framesPerRound = $game->frames_per_round;
    $lastOpensAt = EngineFixtures::opensAt($round, $framesPerRound);
    $receivedAt = $lastOpensAt->addMilliseconds(100);

    // L'intitulé suppose une grâce plus large que 100 ms : c'est elle, lue
    // sur la partie, qui ramène la réponse avant la frontière.
    expect($game->tier_grace_ms)->toBeGreaterThan(100);

    $response = SubmissionFixtures::submit($this, $finderSeat, $finder, 'Harbour Lights', $receivedAt)->assertOk();

    // À cet instant, l'image N est déjà affichée : le rattrapage à l'instant
    // de réception a ouvert le dernier palier.
    $round->refresh();

    expect(EngineFixtures::tier($round, $framesPerRound)->served_at)->not->toBeNull()
        ->and(RoundClock::currentTierIndex($round, $receivedAt))->toBe($framesPerRound);

    // Crédité au palier N − 1, sa valeur et son bonus : ceux que rend la
    // fonction pure de 80 sur les paliers matérialisés, écrits tels quels.
    $guess = SubmissionFixtures::guess($round, $finderSeat);
    $previous = EngineFixtures::tier($round, $framesPerRound - 1);
    $expected = ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $guess->answered_at_ms, GuessSource::Text);

    expect($guess->answered_at_ms)->toBe(RoundClock::offsetMs($round, $receivedAt))
        ->and($guess->answered_at_ms)->toBe($previous->starts_at_offset_ms + $previous->duration_ms + 100)
        ->and($guess->source)->toBe(GuessSource::Text)
        ->and($guess->tier_index)->toBe($framesPerRound - 1)
        ->and($guess->points_tier)->toBe($previous->points)
        ->and($expected->tierIndex)->toBe($framesPerRound - 1)
        ->and([$guess->tier_index, $guess->points_tier, $guess->points_bonus, $guess->points_total])
        ->toBe([$expected->tierIndex, $expected->pointsTier, $expected->pointsBonus, $expected->pointsTotal]);

    $response->assertExactJson([
        'result' => 'accepted',
        'inputState' => 'locked',
        'lockRank' => 1,
        ...$expected->toArray(),
    ]);

    // Témoin : à T_N + tier_grace_ms, l'instant corrigé atteint la frontière,
    // et le même texte est crédité au palier N.
    $atBoundary = $lastOpensAt->addMilliseconds($game->tier_grace_ms);

    SubmissionFixtures::submit($this, $witnessSeat, $witness, 'Harbour Lights', $atBoundary)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'lockRank' => 2, 'tierIndex' => $framesPerRound]);

    expect(SubmissionFixtures::guess($round, $witnessSeat)->tier_index)->toBe($framesPerRound);
});
