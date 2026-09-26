<?php

use App\Enums\Locale;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\Player;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\MatchFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Refus à travail constant — invariant L4, spec 70 § 7.4 et § 7.5, E10-57
|--------------------------------------------------------------------------
|
| Le temps de réponse et le nombre de requêtes d'un refus ne dépendent
| JAMAIS de la proximité de la réponse : sinon un chronomètre dirait
| « presque », et confirmerait une saga que la décision 13 cache. Chaque
| refus est donc joué de bout en bout, par la route, et son relevé de
| requêtes comparé à celui d'un autre refus, à l'instruction près.
|
| Joué sous `Queue::fake()` : sous `QUEUE_CONNECTION=sync`, un job exécuté
| dans la requête ferait dépendre le travail du job. « Aucune ligne »
| s'entend d'aucune insertion : l'unique `UPDATE` conditionnel de
| `round_player` est la même écriture pour tout refus (E10-57).
|
| Deux sièges d'une même manche, dans le même état, reçus au même instant :
| seule la saisie diffère.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake();
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 16:45:00.375'));
});

/**
 * Le relevé des requêtes d'un refus joué par la route, reçu à `$at`.
 *
 * @return list<QueryExecuted>
 */
function constantWorkRefusal(TestCase $test, Player $seat, PlayerToken $token, string $answer, CarbonImmutable $at): array
{
    return SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($test, $seat, $token, $answer, $at)
        ->assertOk()
        ->assertJsonPath('result', 'rejected'));
}

/**
 * Les instructions d'un relevé, sans leurs valeurs : ce que la base a eu à
 * faire, dans l'ordre.
 *
 * @param  list<QueryExecuted>  $queries
 * @return list<string>
 */
function constantWorkStatements(array $queries): array
{
    return array_map(static fn (QueryExecuted $query): string => $query->sql, $queries);
}

/** Le nombre de jobs poussés depuis le début du test. */
function constantWorkPushedJobs(): int
{
    return array_sum(array_map(count(...), Queue::pushedJobs()));
}

/** La plus petite distance d'une saisie aux clés du film, mesurée comme l'appariement la mesure. */
function constantWorkDistance(Movie $movie, string $typed): int
{
    $submitted = AnswerKeyNormalizer::normalize($typed);

    return (int) min(AnswerKey::query()
        ->where('movie_id', $movie->id)
        ->pluck('normalized')
        ->map(static fn (mixed $key): int => AnswerKeyNormalizer::distance($submitted, (string) $key))
        ->all());
}

it('le refus d\'une chaîne à distance 1 et celui d\'une chaîne à distance 12 exécutent le même nombre de requêtes, dont exactement un UPDATE de round_player, et n\'insèrent aucune ligne', function (): void {
    // « Heat » : quatre caractères compacts, tolérance nulle — une faute
    // d'une lettre est refusée, comme une saisie sans rapport.
    $target = SubmissionFixtures::movie('Heat');
    $near = 'Heal';
    $far = str_repeat('z', 12);

    expect(constantWorkDistance($target, $near))->toBe(1)
        ->and(constantWorkDistance($target, $far))->toBe(12);

    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    [$game, $round, [$nearSeat, $farSeat]] = SubmissionFixtures::openedRound($tokens, target: $target);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));
    $pushed = constantWorkPushedJobs();

    $nearQueries = constantWorkRefusal($this, $nearSeat, $tokens[0], $near, $at);
    $farQueries = constantWorkRefusal($this, $farSeat, $tokens[1], $far, $at);

    // Le même travail : les mêmes instructions, dans le même ordre.
    expect(count($nearQueries))->toBe(count($farQueries))
        ->and(constantWorkStatements($nearQueries))->toBe(constantWorkStatements($farQueries));

    foreach ([$nearQueries, $farQueries] as $queries) {
        $writes = SubmissionFixtures::writes($queries);

        // Exactement une écriture, l'`UPDATE` de `round_player` ; aucune insertion.
        expect($writes)->toHaveCount(1)
            ->and(SubmissionFixtures::touches($writes[0], 'round_player'))->toBeTrue()
            ->and(preg_match('/^\s*update\b/i', $writes[0]))->toBe(1)
            ->and(array_filter($writes, static fn (string $sql): bool => preg_match('/^\s*insert\b/i', $sql) === 1))->toBe([]);
    }

    // Aucun job poussé par un refus : la frontière suivante était déjà en file.
    expect(constantWorkPushedJobs())->toBe($pushed);

    expect(SubmissionFixtures::participation($round, $nearSeat)->wrong_attempts)->toBe(1)
        ->and(SubmissionFixtures::participation($round, $farSeat)->wrong_attempts)->toBe(1);
});

it('un refus pour préfixe ambigu exécute le même nombre de requêtes qu\'un refus franc', function (): void {
    // « Star Wars » est le préfixe de deux films publiés : refusé à l'étape
    // (c), quand une saisie sans rapport l'est à l'étape (e).
    $target = SubmissionFixtures::movie('Star Wars: A New Hope');
    $sequel = MatchFixtures::movie('Star Wars: The Empire Strikes Back');
    $ambiguous = 'Star Wars';
    $prefix = AnswerKeyNormalizer::normalize($ambiguous);

    expect(AnswerKey::query()->where('normalized', $prefix)->pluck('movie_id')->sort()->values()->all())
        ->toBe(collect([$target->id, $sequel->id])->sort()->values()->all());

    $tokens = [PlayerToken::mint(Locale::English), PlayerToken::mint(Locale::English)];
    [$game, $round, [$ambiguousSeat, $plainSeat]] = SubmissionFixtures::openedRound($tokens, target: $target);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    $ambiguousQueries = constantWorkRefusal($this, $ambiguousSeat, $tokens[0], $ambiguous, $at);
    $plainQueries = constantWorkRefusal($this, $plainSeat, $tokens[1], SubmissionFixtures::WRONG, $at);

    expect(count($ambiguousQueries))->toBe(count($plainQueries))
        ->and(constantWorkStatements($ambiguousQueries))->toBe(constantWorkStatements($plainQueries))
        ->and(SubmissionFixtures::writes($ambiguousQueries))->toBe(SubmissionFixtures::writes($plainQueries));
});
