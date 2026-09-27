<?php

use App\Actions\Game\CatchUpGame;
use App\Actions\Game\ComposeChoiceSets;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\AnswerAccepted;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Round;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use App\Support\Scoring\ScoreCalculator;
use App\Support\Scoring\ScoreReplayer;
use App\ValueObjects\Scoring\TierSchedule;
use App\ValueObjects\Scoring\TierScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
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
|   n'existaient pas avant `T_N` (plancher du QCM, R-18) ; un clic reçu AVANT
|   `T_N` est refusé comme saisie close par 70, avant tout appel à 80
|   (lot L70-9).
|
| Le QCM est composé par l'action réelle à son instant théorique
| (`SubmissionFixtures::openChoices()`), le clic envoyé par la route réelle.
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

it('un clic QCM en Normal reçu à T_N + 100 ms est crédité au palier N', function (): void {
    $clicker = PlayerToken::mint(Locale::French);
    $typist = PlayerToken::mint(Locale::English);
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, [$clickerSeat, $typistSeat]] = SubmissionFixtures::openedRound(
        [$clicker, $typist],
        SubmissionFixtures::settings(InputDifficulty::Normal),
        $target,
    );
    SubmissionFixtures::decoyCandidates();

    $framesPerRound = $game->frames_per_round;
    $lastOpensAt = SubmissionFixtures::openChoices($round);
    $receivedAt = $lastOpensAt->addMilliseconds(100);
    $last = EngineFixtures::tier($round, $framesPerRound);

    // L'intitulé suppose une grâce plus large que 100 ms : sans plancher,
    // l'instant corrigé de la grâce ramènerait ce clic avant `T_N`.
    expect($game->tier_grace_ms)->toBeGreaterThan(100)
        ->and(RoundClock::offsetMs($round, $receivedAt) - $game->tier_grace_ms)->toBeLessThan($last->starts_at_offset_ms);

    $response = SubmissionFixtures::click($this, $clickerSeat, $clicker, SubmissionFixtures::correctChoice($round, $clickerSeat), $receivedAt)
        ->assertOk();

    // Crédité au palier N, t = 0 : exactement ce que vaudrait un clic reçu à
    // l'instant même de `T_N` — la fonction pure de 80, plancher compris,
    // écrite telle quelle.
    $guess = SubmissionFixtures::guess($round, $clickerSeat);
    $schedule = TierSchedule::fromRound($round);
    $expected = ScoreCalculator::forGuess($game, $schedule, $guess->answered_at_ms, GuessSource::Choice);

    expect($guess->answered_at_ms)->toBe(RoundClock::offsetMs($round, $receivedAt))
        ->and($guess->answered_at_ms)->toBe($last->starts_at_offset_ms + 100)
        ->and($guess->source)->toBe(GuessSource::Choice)
        ->and($guess->match_kind)->toBe(GuessMatchKind::Choice)
        ->and($guess->tier_index)->toBe($framesPerRound)
        ->and($guess->points_tier)->toBe($last->points)
        ->and($expected->equals(ScoreCalculator::forGuess($game, $schedule, $last->starts_at_offset_ms, GuessSource::Choice)))->toBeTrue()
        ->and(TierScore::fromGuess($guess)->equals($expected))->toBeTrue();

    $response->assertExactJson([
        'result' => 'accepted',
        'inputState' => 'locked',
        'lockRank' => 1,
        ...$expected->toArray(),
    ]);

    // Témoin : au même instant, le même film en texte libre est crédité au
    // palier N − 1 — la grâce, que le clic ne peut pas acheter.
    SubmissionFixtures::submit($this, $typistSeat, $typist, 'Harbour Lights', $receivedAt)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'lockRank' => 2, 'tierIndex' => $framesPerRound - 1]);

    expect(SubmissionFixtures::guess($round, $typistSeat)->tier_index)->toBe($framesPerRound - 1);
});

it("un clic reçu avant l'ouverture du QCM est refusé comme saisie close", function (): void {
    Event::fake([AnswerAccepted::class]);

    $token = PlayerToken::mint(Locale::French);
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Normal), $target);
    SubmissionFixtures::decoyCandidates();

    $framesPerRound = $game->frames_per_round;
    $cadence = SubmissionFixtures::cadenceMs($game);
    $tN = EngineFixtures::opensAt($round, $framesPerRound);
    $uncomposedAt = $tN->subMilliseconds($cadence + 1);
    $earlyAt = $tN->subMillisecond();

    // Les paliers échus avant `T_N` sont ouverts : le rattrapage des clics
    // n'a plus rien à écrire.
    Date::setTestNow($earlyAt);
    app(CatchUpGame::class)->handle($game, $earlyAt);

    // 1. Avant `T_N`, comme en production, le QCM n'est pas composé : une
    // saisie close (409), jamais une proposition inconnue (422).
    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $seat, $token, SubmissionFixtures::WRONG, $uncomposedAt)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French)));

    expect(SubmissionFixtures::writes($queries))->toBe([]);

    // 2. Le QCM composé à son instant théorique et la bonne proposition en
    // main, un clic juste reçu 1 ms avant `T_N` reste refusé comme saisie
    // close : l'ouverture se lit sans grâce — la grâce, qui ramène un texte
    // au palier N − 1, n'ouvre jamais le QCM avant sa frontière. 70 le
    // décide avant tout appel à 80 : rien n'est verrouillé, rien n'est noté.
    app()->forgetScopedInstances();

    expect(app(ComposeChoiceSets::class)->handle(Round::query()->findOrFail($round->id), $tN))->toBeTrue();

    $correct = SubmissionFixtures::correctChoice($round, $seat);

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::click($this, $seat, $token, $correct, $earlyAt)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French)));

    $participation = SubmissionFixtures::participation($round, $seat);

    expect(SubmissionFixtures::writes($queries))->toBe([])
        ->and(Guess::query()->count())->toBe(0)
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(0)
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Open)
        ->and($participation->input_closed_at)->toBeNull()
        ->and($participation->wrong_attempts)->toBe(0);

    Event::assertNotDispatched(AnswerAccepted::class);

    // Témoin : la même proposition, dès `T_N` franchi, verrouille au palier N.
    $openedAt = $earlyAt->addMilliseconds($cadence);

    expect($openedAt->greaterThanOrEqualTo($tN))->toBeTrue();

    SubmissionFixtures::click($this, $seat, $token, $correct, $openedAt)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1, 'tierIndex' => $framesPerRound]);

    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
});

it('le rejeu depuis guess.source et game.input_difficulty redonne tier_index et points_total', function (): void {
    $clicker = PlayerToken::mint(Locale::French);
    $typist = PlayerToken::mint(Locale::English);
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, [$clickerSeat, $typistSeat]] = SubmissionFixtures::openedRound(
        [$clicker, $typist],
        SubmissionFixtures::settings(InputDifficulty::Normal),
        $target,
    );
    SubmissionFixtures::decoyCandidates();

    $framesPerRound = $game->frames_per_round;
    $receivedAt = SubmissionFixtures::openChoices($round)->addMilliseconds(100);

    // Au même instant de réception : un clic et un texte.
    SubmissionFixtures::click($this, $clickerSeat, $clicker, SubmissionFixtures::correctChoice($round, $clickerSeat), $receivedAt)->assertOk();
    SubmissionFixtures::submit($this, $typistSeat, $typist, 'Harbour Lights', $receivedAt)->assertOk();

    $clicked = SubmissionFixtures::guess($round, $clickerSeat);
    $typed = SubmissionFixtures::guess($round, $typistSeat);

    // Le rejeu relit les seuls faits figés — `guess.answered_at_ms`,
    // `guess.source`, les paliers matérialisés et, sur la partie,
    // `input_difficulty` et `frames_per_round` — et redonne exactement ce
    // que le verrouillage a écrit.
    foreach ([$clicked, $typed] as $guess) {
        $replayed = ScoreReplayer::replay($guess);

        expect($replayed->tierIndex)->toBe($guess->tier_index)
            ->and($replayed->pointsTotal)->toBe($guess->points_total)
            ->and($replayed->equals(TierScore::fromGuess($guess)))->toBeTrue();
    }

    // Même décalage, deux paliers : c'est la source relue qui les distingue…
    $schedule = TierSchedule::fromRound($round);

    expect($clicked->answered_at_ms)->toBe($typed->answered_at_ms)
        ->and($clicked->source)->toBe(GuessSource::Choice)
        ->and($clicked->tier_index)->toBe($framesPerRound)
        ->and($typed->source)->toBe(GuessSource::Text)
        ->and($typed->tier_index)->toBe($framesPerRound - 1)
        ->and(ScoreCalculator::forGuess($game, $schedule, $clicked->answered_at_ms, GuessSource::Text)->tierIndex)->toBe($framesPerRound - 1);

    // … et la difficulté relue sur la partie : le plancher d'un clic est le
    // palier d'ouverture du QCM de SA difficulté. Sous Facile, dont le QCM
    // s'ouvre à `T₁`, le même clic retomberait au palier N − 1 ; la partie en
    // base, elle, n'a pas changé, et le rejeu la relit.
    $asEasy = Game::query()->findOrFail($game->id);
    $asEasy->input_difficulty = InputDifficulty::Easy;

    expect(ScoreCalculator::forGuess($asEasy, $schedule, $clicked->answered_at_ms, $clicked->source)->tierIndex)->toBe($framesPerRound - 1)
        ->and(ScoreReplayer::replay($clicked)->tierIndex)->toBe($framesPerRound);
});
