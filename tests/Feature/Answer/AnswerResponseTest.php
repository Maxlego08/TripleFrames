<?php

use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Enums\SubmissionOutcome;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\AnswerKey;
use App\Support\Answers\AnswerMatcher;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Identity\PlayerToken;
use App\ValueObjects\Answers\SubmissionVerdict;
use App\ValueObjects\Scoring\TierScore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\MatchFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Réponses de la soumission — spec 70 § 7.7, contrat C10 § 3
|--------------------------------------------------------------------------
|
| Une réponse HTTP a un destinataire unique. Aucune ne porte de titre,
| d'alias, de nature d'appariement, de forme normalisée ni de distance ; un
| refus pour préfixe ou sous-titre ambigu a EXACTEMENT le corps d'un refus
| franc, et aucun texte de refus ne suggère la proximité : jamais de
| « presque » (décision 13). Le corps d'une acceptation (lot L70-6) est
| éprouvé avec la transaction de verrouillage.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 17:10:00.500'));
});

it('un refus a le même corps quelle que soit sa cause', function (): void {
    // La manche porte « Star Wars: A New Hope » ; « The Empire Strikes Back »
    // est un autre film publié de la même saga.
    $target = SubmissionFixtures::movie('Star Wars: A New Hope');
    $sequel = MatchFixtures::movie('Star Wars: The Empire Strikes Back');

    // Une cause de refus par siège, chacun à son premier refus : seule la
    // cause diffère.
    $causes = [
        // (c) préfixe porté par deux films publiés.
        'préfixe ambigu' => 'Star Wars',
        // (c) sous-titre d'un autre film publié.
        'sous-titre d’un autre film' => 'The Empire Strikes Back',
        // (c) titre complet d'un autre film publié.
        'titre d’un autre film' => 'Star Wars: The Empire Strikes Back',
        // (e) suite de chiffres différente, jamais tolérée.
        'chiffres différents' => 'Star Wars 4',
        // (e) quatre fautes sur une clé qui en tolère trois : presque juste.
        'presque juste' => 'Stor Wors O Now Hope',
        // (e) sans rapport.
        'sans rapport' => SubmissionFixtures::WRONG,
    ];

    $tokens = array_map(static fn (): PlayerToken => PlayerToken::mint(Locale::French), $causes);
    [$game, $round, $seats] = SubmissionFixtures::openedRound(array_values($tokens), target: $target);
    $seats = array_combine(array_keys($causes), $seats);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    // Chaque cause est bien celle annoncée : trois saisies désignent
    // exactement un autre film publié, aucune n'est acceptée.
    $carriedByOther = static fn (string $typed): bool => AnswerKey::query()
        ->where('normalized', AnswerKeyNormalizer::normalize($typed))
        ->where('movie_id', $sequel->id)
        ->exists();

    foreach ($causes as $cause => $typed) {
        expect(app(AnswerMatcher::class)->match($round, AnswerKeyNormalizer::normalize($typed))->accepted)->toBeFalse()
            ->and($carriedByOther($typed))->toBe(in_array($cause, ['préfixe ambigu', 'sous-titre d’un autre film', 'titre d’un autre film'], true));
    }

    $expected = json_encode(SubmissionFixtures::rejectedBody($game->settings_snapshot->attemptsPerRound - 1), JSON_THROW_ON_ERROR);
    $bodies = [];

    foreach ($causes as $cause => $typed) {
        $response = SubmissionFixtures::submit($this, $seats[$cause], $tokens[$cause], $typed, $at)->assertOk();

        $bodies[$cause] = [
            'status' => $response->getStatusCode(),
            'type' => $response->headers->get('Content-Type'),
            'body' => $response->getContent(),
        ];
    }

    // Octet pour octet, le même corps : ni cause, ni nature, ni forme, ni
    // distance, ni titre.
    expect(array_unique(array_map(static fn (array $body): string => json_encode($body, JSON_THROW_ON_ERROR), $bodies)))->toHaveCount(1)
        ->and(reset($bodies)['body'])->toBe($expected);

    foreach ([$target->title_original, $sequel->title_original, 'star wars', 'prefix', 'subtitle', 'title', 'distance'] as $leak) {
        expect(Str::contains((string) reset($bodies)['body'], $leak, ignoreCase: true))->toBeFalse();
    }

    // Aucun texte de refus ne suggère la proximité, dans aucune langue.
    foreach (Locale::cases() as $locale) {
        $refusal = trans('game.answer.rejected', [], $locale->value);

        expect($refusal)->toBeString()->not->toBe('game.answer.rejected')
            ->and(Str::contains((string) $refusal, ['presque', 'pas tout à fait', 'proche', 'almost', 'nearly', 'not quite', 'close'], ignoreCase: true))->toBeFalse();
    }
});

it('SubmissionVerdict rend les trois corps du contrat et refuse un verdict incohérent', function (): void {
    $score = new TierScore(tierIndex: 2, pointsTier: 200, pointsBonus: 37, pointsTotal: 237);

    // Les trois corps, clés et ordre du contrat C10 § 3 ; le `message` d'une
    // clôture est ajouté par le contrôleur, dans la langue de la requête.
    expect(SubmissionVerdict::accepted(3, $score)->toArray())->toBe([
        'result' => 'accepted',
        'inputState' => 'locked',
        'lockRank' => 3,
        'tierIndex' => 2,
        'pointsTier' => 200,
        'pointsBonus' => 37,
        'pointsTotal' => 237,
    ])
        ->and(SubmissionVerdict::rejected(RoundPlayerInputState::Open, 4)->toArray())->toBe(['result' => 'rejected', 'inputState' => 'open', 'attemptsLeft' => 4])
        ->and(SubmissionVerdict::rejected(RoundPlayerInputState::TextExhausted, 0)->toArray())->toBe(['result' => 'rejected', 'inputState' => 'text_exhausted', 'attemptsLeft' => 0])
        ->and(SubmissionVerdict::closed(RoundPlayerInputState::QcmWrong)->toArray())->toBe(['result' => 'closed', 'inputState' => 'qcm_wrong']);

    expect(SubmissionVerdict::accepted(1, $score)->httpStatus())->toBe(Response::HTTP_OK)
        ->and(SubmissionVerdict::rejected(RoundPlayerInputState::Open, 1)->httpStatus())->toBe(Response::HTTP_OK)
        ->and(SubmissionVerdict::closed(RoundPlayerInputState::Open)->httpStatus())->toBe(Response::HTTP_CONFLICT);

    // Incohérences refusées : tentatives restantes hors d'une saisie ouverte,
    // refus verrouillé, rang sans acceptation, acceptation sans rang.
    $incoherent = [
        static fn () => SubmissionVerdict::rejected(RoundPlayerInputState::AttemptsExhausted, 1),
        static fn () => SubmissionVerdict::rejected(RoundPlayerInputState::Open, -1),
        static fn () => SubmissionVerdict::rejected(RoundPlayerInputState::Locked, 0),
        static fn () => new SubmissionVerdict(SubmissionOutcome::Closed, RoundPlayerInputState::Open, 0, 1, null),
        static fn () => new SubmissionVerdict(SubmissionOutcome::Accepted, RoundPlayerInputState::Locked, 0, null, $score),
        static fn () => new SubmissionVerdict(SubmissionOutcome::Accepted, RoundPlayerInputState::Open, 0, 1, $score),
    ];

    foreach ($incoherent as $build) {
        expect($build)->toThrow(InvalidArgumentException::class);
    }
});
