<?php

use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\WrongAnswer;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Journal des réponses fausses — spec 10 § 7.6 bis, spec 70 § 7.5 et § 7.6,
| D46 du 01/10, lot L20-36
|--------------------------------------------------------------------------
|
| Une ligne `wrong_answer` par refus COMPTÉ, texte comme clic, écrite dans
| la transaction du refus ; un refus non compté n'écrit rien.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-10-01 14:00:00.250'));
});

it('un refus compté écrit exactement une réponse fausse, texte brut compris', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $receivedAt = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    SubmissionFixtures::submit($this, $seat, $token, 'Xqzv, Wkjb !', $receivedAt)->assertOk();

    $line = WrongAnswer::query()->sole();

    expect($line->round_id)->toBe($round->id)
        ->and($line->player_id)->toBe($seat->id)
        ->and($line->source)->toBe(GuessSource::Text)
        ->and($line->submitted_text)->toBe('Xqzv, Wkjb !')
        ->and($line->submitted_normalized)->toBe(AnswerKeyNormalizer::normalize('Xqzv, Wkjb !'))
        ->and($line->attempt_number)->toBe(1)
        ->and($line->received_at->equalTo($receivedAt))->toBeTrue()
        ->and($line->answered_at_ms)->toBe(RoundClock::offsetMs($round, $receivedAt));
});

it('un clic faux écrit une réponse fausse avec la chaîne cliquée et sans rang de tentative', function (): void {
    $token = PlayerToken::mint(Locale::French);
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Normal), $target);
    SubmissionFixtures::decoyCandidates();
    SubmissionFixtures::openChoices($round);

    $wrong = SubmissionFixtures::wrongChoice($round, $seat);
    $closesAt = EngineFixtures::durationEnd($round);

    SubmissionFixtures::click($this, $seat, $token, $wrong, $closesAt->subSecond())->assertOk();

    $line = WrongAnswer::query()->sole();

    expect($line->source)->toBe(GuessSource::Choice)
        ->and($line->submitted_text)->toBe($wrong)
        ->and($line->attempt_number)->toBeNull()
        ->and($line->player_id)->toBe($seat->id);
});

it('un refus non compté n\'écrit aucune réponse fausse', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);

    $late = EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms);

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $late)
        ->assertStatus(Response::HTTP_CONFLICT);

    expect(WrongAnswer::query()->count())->toBe(0);
});

it('une bonne réponse n\'écrit aucune réponse fausse', function (): void {
    $token = PlayerToken::mint(Locale::French);
    $target = SubmissionFixtures::movie('Harbour Lights');
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], target: $target);

    SubmissionFixtures::submit($this, $seat, $token, 'Harbour Lights', EngineFixtures::opensAt($round, 1)->addSecond())
        ->assertOk()
        ->assertJson(['result' => 'accepted']);

    expect(WrongAnswer::query()->count())->toBe(0);
});
