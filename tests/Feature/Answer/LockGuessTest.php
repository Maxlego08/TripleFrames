<?php

use App\Actions\Game\LockGuess;
use App\Enums\ContentAvailability;
use App\Enums\GuessMatchKind;
use App\Enums\GuessSource;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Enums\RoundStatus;
use App\Events\Game\AnswerAccepted;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Guess;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Answers\AnswerMatcher;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use App\Support\Scoring\ScoreCalculator;
use App\ValueObjects\Answers\MatchResult;
use App\ValueObjects\Scoring\TierSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\MatchFixtures;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Transaction de verrouillage — spec 70 § 9, contrat C10 § 4, 10 § 7.6 et A12
|--------------------------------------------------------------------------
|
| `LockGuess` est la seule écrivaine de `guess`. Dans UNE transaction, dans
| cet ordre : `round FOR UPDATE` et revérification de la recevabilité,
| `round_player FOR UPDATE` et revérification de l'état de saisie, points
| par la fonction pure de 80, `found_count + 1` et `lock_rank` lu sous
| verrou, `INSERT guess`, `round_player` `locked`, puis `AnswerAccepted`
| après le commit. Une revérification qui échoue répond 409 `closed` sans
| AUCUNE écriture ; une écriture qui échoue n'en laisse aucune.
|
| D'où l'invariant de 10 § 7.6 (A16) : `input_state = 'locked'` SI ET
| SEULEMENT SI une ligne `guess` existe pour le même couple.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 18:40:00.625'));
});

/**
 * L'appariement réel d'une saisie brute sur la manche (lectures K et O),
 * comme l'étape S6 le rend à la transaction de verrouillage.
 */
function lockGuessMatch(Round $round, string $typed): MatchResult
{
    return app(AnswerMatcher::class)->match($round, AnswerKeyNormalizer::normalize($typed));
}

/**
 * Vérifie l'invariant de 10 § 7.6 sur toutes les participations de la
 * manche : `locked` si et seulement si une ligne `guess` existe, et le
 * compteur vivant de la manche égal au nombre de lignes.
 */
function lockGuessAssertInvariant(Round $round): void
{
    $participations = RoundPlayer::query()->where('round_id', $round->id)->get();

    expect($participations)->not->toBeEmpty();

    foreach ($participations as $participation) {
        $hasGuess = Guess::query()
            ->where('round_id', $round->id)
            ->where('player_id', $participation->player_id)
            ->exists();

        expect($participation->input_state === RoundPlayerInputState::Locked)->toBe($hasGuess, sprintf(
            'Siège %d : état %s, ligne guess %s.',
            $participation->player_id,
            $participation->input_state->value,
            $hasGuess ? 'présente' : 'absente',
        ));
    }

    expect(Round::query()->whereKey($round->id)->value('found_count'))
        ->toBe(Guess::query()->where('round_id', $round->id)->count());
}

/**
 * L'instantané d'une ligne `guess`, colonnes du § 9.3 en valeurs scalaires.
 *
 * @return array<string, mixed>
 */
function lockGuessSnapshot(Guess $guess): array
{
    return [
        'received_at' => $guess->received_at->format('Y-m-d H:i:s.v'),
        'answered_at_ms' => $guess->answered_at_ms,
        'tier_index' => $guess->tier_index,
        'lock_rank' => $guess->lock_rank,
        'source' => $guess->source->value,
        'match_kind' => $guess->match_kind->value,
        'answer_key_id' => $guess->answer_key_id,
        'answer_key_normalized' => $guess->answer_key_normalized,
        'submitted_normalized' => $guess->submitted_normalized,
        'edit_distance' => $guess->edit_distance,
        'prefix_was_ambiguous' => $guess->prefix_was_ambiguous,
        'points_tier' => $guess->points_tier,
        'points_bonus' => $guess->points_bonus,
        'points_total' => $guess->points_total,
    ];
}

it('input_state locked si et seulement si une ligne guess existe', function (): void {
    Event::fake([AnswerAccepted::class]);

    $target = SubmissionFixtures::movie('Harbour Lights');
    $names = ['first', 'wrong', 'spent', 'second', 'failing', 'idle'];
    $tokens = array_combine($names, array_map(static fn (): PlayerToken => PlayerToken::mint(Locale::French), $names));
    [$game, $round, $seats] = SubmissionFixtures::openedRound(
        array_values($tokens),
        SubmissionFixtures::settings(InputDifficulty::Expert),
        $target,
    );
    $seats = array_combine($names, $seats);
    $cap = $game->settings_snapshot->attemptsPerRound;
    $cadence = SubmissionFixtures::cadenceMs($game);
    $at = static fn (int $step): CarbonImmutable => EngineFixtures::opensAt($round, 1)->addMilliseconds($step * $cadence);

    // Instances lues AVANT tout verrouillage, comme S4 les lit : la
    // transaction ne s'y fie jamais.
    $staleFirst = SubmissionFixtures::participation($round, $seats['first']);
    $staleSpent = SubmissionFixtures::participation($round, $seats['spent']);

    // Deux bonnes réponses, un refus, un épuisement du texte (Expert).
    SubmissionFixtures::submit($this, $seats['first'], $tokens['first'], 'Harbour Lights', $at(1))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);

    SubmissionFixtures::submit($this, $seats['wrong'], $tokens['wrong'], SubmissionFixtures::WRONG, $at(2))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($cap - 1));

    SubmissionFixtures::spend($round, $seats['spent'], $cap - 1);
    SubmissionFixtures::submit($this, $seats['spent'], $tokens['spent'], SubmissionFixtures::WRONG, $at(3))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

    SubmissionFixtures::submit($this, $seats['second'], $tokens['second'], 'Harbour Lights', $at(4))
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 2]);

    lockGuessAssertInvariant($round);

    // Revérification sous le verrou, sur des instances périmées (`open` en
    // mémoire) : un siège déjà verrouillé, un siège épuisé. 409 `closed` avec
    // l'état relu, et AUCUNE écriture.
    $lock = app(LockGuess::class);
    $match = lockGuessMatch($round, 'Harbour Lights');
    $receivedAt = $at(5);
    Date::setTestNow($receivedAt);

    expect($match->accepted)->toBeTrue()
        ->and($staleFirst->input_state)->toBe(RoundPlayerInputState::Open)
        ->and($staleSpent->input_state)->toBe(RoundPlayerInputState::Open);

    $verdicts = [];
    $queries = SubmissionFixtures::queries(function () use ($lock, $round, $game, $match, $receivedAt, $staleFirst, $staleSpent, &$verdicts): void {
        foreach ([$staleFirst, $staleSpent] as $stale) {
            $verdicts[] = $lock->handle($round, $stale, $game, $match, GuessSource::Text, $receivedAt, RoundClock::offsetMs($round, $receivedAt))->toArray();
        }
    });

    expect($verdicts)->toBe([
        ['result' => 'closed', 'inputState' => RoundPlayerInputState::Locked->value],
        ['result' => 'closed', 'inputState' => RoundPlayerInputState::AttemptsExhausted->value],
    ])
        ->and(SubmissionFixtures::writes($queries))->toBe([])
        ->and(Guess::query()->where('round_id', $round->id)->count())->toBe(2);

    // Une écriture qui échoue APRÈS l'insertion de la ligne `guess` — au
    // passage de la participation en `locked` — n'en laisse aucune : ni
    // ligne, ni rang consommé, ni saisie close, ni annonce.
    $armed = true;
    DB::beforeExecuting(static function (string $sql) use (&$armed): void {
        if ($armed && str_starts_with(strtolower(ltrim($sql)), 'update') && SubmissionFixtures::touches($sql, 'round_player')) {
            $armed = false;

            throw new RuntimeException('Échec simulé de l’écriture de round_player.');
        }
    });

    $inserted = false;
    DB::listen(static function ($query) use (&$inserted): void {
        if (SubmissionFixtures::touches($query->sql, 'guess') && str_starts_with(strtolower(ltrim($query->sql)), 'insert')) {
            $inserted = true;
        }
    });

    $failing = SubmissionFixtures::participation($round, $seats['failing']);

    expect(fn () => $lock->handle($round, $failing, $game, $match, GuessSource::Text, $receivedAt, RoundClock::offsetMs($round, $receivedAt)))
        ->toThrow(RuntimeException::class, 'Échec simulé');

    expect($armed)->toBeFalse()
        ->and($inserted)->toBeTrue()
        ->and(Guess::query()->where('round_id', $round->id)->where('player_id', $seats['failing']->id)->exists())->toBeFalse()
        ->and(SubmissionFixtures::participation($round, $seats['failing'])->input_state)->toBe(RoundPlayerInputState::Open)
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(2);

    lockGuessAssertInvariant($round);

    // Une transaction englobante annulée emporte tout, annonce comprise :
    // `AnswerAccepted` n'est délivré qu'après le commit.
    DB::beginTransaction();

    try {
        $rolledBack = $lock->handle($round, SubmissionFixtures::participation($round, $seats['idle']), $game, $match, GuessSource::Text, $receivedAt, RoundClock::offsetMs($round, $receivedAt));
    } finally {
        DB::rollBack();
    }

    expect($rolledBack->toArray())->toMatchArray(['result' => 'accepted', 'lockRank' => 3])
        ->and(Guess::query()->where('round_id', $round->id)->where('player_id', $seats['idle']->id)->exists())->toBeFalse()
        ->and(SubmissionFixtures::participation($round, $seats['idle'])->input_state)->toBe(RoundPlayerInputState::Open);
    Event::assertNotDispatched(AnswerAccepted::class, static fn (AnswerAccepted $event): bool => $event->playerId === $seats['idle']->id);

    lockGuessAssertInvariant($round);

    // Manche close puis révélée par une transition concurrente : la
    // recevabilité relue sous le verrou (`revealing`) ferme la saisie, même à
    // un instant de réception encore dans la fenêtre.
    EngineFixtures::close($round);
    EngineFixtures::reveal($round);

    $lateButInWindow = EngineFixtures::durationEnd($round)->addMilliseconds($game->tier_grace_ms - 1);
    $idle = SubmissionFixtures::participation($round, $seats['idle']);

    expect(Round::query()->whereKey($round->id)->value('status'))->toBe(RoundStatus::Revealing);

    $queries = SubmissionFixtures::queries(function () use ($lock, $round, $game, $match, $idle, $lateButInWindow, &$verdicts): void {
        $verdicts = [$lock->handle($round, $idle, $game, $match, GuessSource::Text, $lateButInWindow, RoundClock::offsetMs($round, $lateButInWindow))->toArray()];
    });

    expect($verdicts)->toBe([['result' => 'closed', 'inputState' => RoundPlayerInputState::Open->value]])
        ->and(SubmissionFixtures::writes($queries))->toBe([]);

    // Bilan : deux bonnes réponses, deux sièges verrouillés, deux rangs
    // distincts, deux annonces après commit — rien d'autre.
    lockGuessAssertInvariant($round);

    expect(Guess::query()->where('round_id', $round->id)->orderBy('lock_rank')->pluck('player_id')->all())
        ->toBe([$seats['first']->id, $seats['second']->id])
        ->and(Guess::query()->where('round_id', $round->id)->orderBy('lock_rank')->pluck('lock_rank')->all())->toBe([1, 2])
        ->and(RoundPlayer::query()->where('round_id', $round->id)->where('input_state', RoundPlayerInputState::Locked->value)->count())->toBe(2);

    Event::assertDispatchedTimes(AnswerAccepted::class, 2);
    Event::assertDispatched(AnswerAccepted::class, static fn (AnswerAccepted $event): bool => $event->roundId === $round->id
        && $event->playerId === $seats['first']->id
        && $event->lockRank === 1);
    Event::assertDispatched(AnswerAccepted::class, static fn (AnswerAccepted $event): bool => $event->roundId === $round->id
        && $event->playerId === $seats['second']->id
        && $event->lockRank === 2);
});

it('le guess porte l\'instantané complet de la règle', function (): void {
    $title = 'The Lord of the Rings: The Two Towers';
    $target = SubmissionFixtures::movie($title);
    // Un remake homonyme, encore brouillon : il sera publié en pleine manche.
    $remake = MatchFixtures::movie($title, availability: ContentAvailability::Draft);

    $names = ['typo', 'subtitle', 'homonym', 'choice'];
    $tokens = array_combine($names, array_map(static fn (): PlayerToken => PlayerToken::mint(Locale::English), $names));
    [$game, $round, $seats] = SubmissionFixtures::openedRound(
        array_values($tokens),
        SubmissionFixtures::settings(InputDifficulty::Normal),
        $target,
    );
    $seats = array_combine($names, $seats);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $framesPerRound = $game->frames_per_round;
    $fullKey = MatchFixtures::key($target, 'lord of the rings the two towers');
    $subtitleKey = MatchFixtures::key($target, 'two towers');

    // L'instantané attendu d'une acceptation : les colonnes de règle, puis
    // les quatre sorties de la fonction pure de 80, recalculées ici en
    // témoin sur les paliers matérialisés.
    $expect = static function (array $rule, CarbonImmutable $receivedAt, int $lockRank, GuessSource $source) use ($game, $round): array {
        $answeredAtMs = RoundClock::offsetMs($round, $receivedAt);
        $score = ScoreCalculator::forGuess($game, TierSchedule::fromRound($round), $answeredAtMs, $source);

        return [
            'received_at' => $receivedAt->format('Y-m-d H:i:s.v'),
            'answered_at_ms' => $answeredAtMs,
            'tier_index' => $score->tierIndex,
            'lock_rank' => $lockRank,
            'source' => $source->value,
            ...$rule,
            'points_tier' => $score->pointsTier,
            'points_bonus' => $score->pointsBonus,
            'points_total' => $score->pointsTotal,
        ];
    };

    // Texte accepté par tolérance (d), au palier 1. Ni le travail de
    // validation ni l'attente du verrou ne font changer de palier (§ 7.2,
    // principe 1) : l'horloge avance d'un palier entier après l'appariement,
    // puis d'un autre pendant que la transaction de verrouillage attend la
    // manche, et la réponse reste datée et notée à sa réception.
    $typoAt = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence);
    $typed = 'The Lord of the Rings: The Two Towerz';
    $firstTierMs = EngineFixtures::tier($round, 1)->duration_ms;
    $matched = false;
    $waited = false;

    DB::listen(static function (QueryExecuted $query) use (&$matched, &$waited, $firstTierMs): void {
        if ($waited) {
            return;
        }

        // La lecture O de l'appariement (S6) : la saisie est jugée.
        if (! $matched && SubmissionFixtures::touches($query->sql, 'answer_key') && str_contains(strtolower($query->sql), 'join')) {
            $matched = true;
            Date::setTestNow(Date::now()->addMilliseconds($firstTierMs));

            return;
        }

        // Après elle, la première lecture de la manche est celle,
        // verrouillante, de la transaction.
        if ($matched && preg_match('/^\s*select\b/i', $query->sql) === 1 && SubmissionFixtures::touches($query->sql, 'round')) {
            $waited = true;
            Date::setTestNow(Date::now()->addMilliseconds($firstTierMs));
        }
    });

    SubmissionFixtures::submit($this, $seats['typo'], $tokens['typo'], $typed, $typoAt)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'tierIndex' => 1]);

    expect($matched)->toBeTrue()
        ->and($waited)->toBeTrue();

    // Sous-titre accepté en (b), au palier 2.
    $subtitleAt = EngineFixtures::opensAt($round, 2)->addMilliseconds($cadence);
    SubmissionFixtures::submit($this, $seats['subtitle'], $tokens['subtitle'], 'The Two Towers', $subtitleAt)->assertOk();

    // Le remake est publié en pleine manche : le titre complet reste accepté
    // en (a), et l'homonymie publiée à l'instant du match est journalisée.
    MatchFixtures::publish($remake);
    $homonymAt = $subtitleAt->addMilliseconds($cadence);
    SubmissionFixtures::submit($this, $seats['homonym'], $tokens['homonym'], $title, $homonymAt)->assertOk();

    // Le siège du clic épuise son texte avant T_N (Normal) : `text_exhausted`,
    // seul état où les deux voies divergent (§ 3.3). L'instance est lue avant,
    // `open` en mémoire : la transaction ne s'y fie jamais.
    $cap = $game->settings_snapshot->attemptsPerRound;
    $exhaustedAt = $homonymAt->addMilliseconds($cadence);
    SubmissionFixtures::spend($round, $seats['choice'], $cap - 1);
    $staleChoice = SubmissionFixtures::participation($round, $seats['choice']);

    expect($exhaustedAt->lessThan(EngineFixtures::opensAt($round, $framesPerRound)))->toBeTrue()
        ->and($staleChoice->input_state)->toBe(RoundPlayerInputState::Open);

    SubmissionFixtures::submit($this, $seats['choice'], $tokens['choice'], SubmissionFixtures::WRONG, $exhaustedAt)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    // Clic QCM (étape S5' de L70-9) : égalité stricte contre `choice_1`,
    // `answer_key` jamais consulté ; reçu à T_N + 100 ms, crédité au palier
    // N par le plancher du QCM.
    $choiceAt = EngineFixtures::opensAt($round, $framesPerRound)->addMilliseconds(100);
    Date::setTestNow($choiceAt);

    // Une bonne réponse TEXTE sur ce siège, au même instant : le prédicat de
    // la voie texte (`acceptsText()`), relu sous le verrou, refuse
    // `text_exhausted` — 409 `closed`, sans aucune écriture.
    $textMatch = lockGuessMatch($round, $title);
    $textVerdict = null;
    $textQueries = SubmissionFixtures::queries(function () use ($round, $staleChoice, $game, $textMatch, $choiceAt, &$textVerdict): void {
        $textVerdict = app(LockGuess::class)->handle($round, $staleChoice, $game, $textMatch, GuessSource::Text, $choiceAt, RoundClock::offsetMs($round, $choiceAt))->toArray();
    });

    expect($textMatch->accepted)->toBeTrue()
        ->and($textVerdict)->toBe(['result' => 'closed', 'inputState' => RoundPlayerInputState::TextExhausted->value])
        ->and(SubmissionFixtures::writes($textQueries))->toBe([])
        ->and(SubmissionFixtures::participation($round, $seats['choice'])->input_state)->toBe(RoundPlayerInputState::TextExhausted);

    // Le clic, lui, passe sur la même instance périmée : `acceptsChoice()`
    // admet `text_exhausted`.
    $choiceSeat = $staleChoice;
    $choiceMatch = new MatchResult(
        accepted: true,
        submittedNormalized: AnswerKeyNormalizer::normalize($title),
        answerKeyId: null,
        answerKeyNormalized: AnswerKeyNormalizer::normalize($title),
        matchKind: GuessMatchKind::Choice,
        editDistance: 0,
        prefixWasAmbiguous: false,
    );
    $choiceOffsetMs = RoundClock::offsetMs($round, $choiceAt);
    $clicked = null;
    $statements = SubmissionFixtures::queries(function () use ($round, $choiceSeat, $game, $choiceMatch, $choiceAt, $choiceOffsetMs, &$clicked): void {
        $clicked = app(LockGuess::class)->handle($round, $choiceSeat, $game, $choiceMatch, GuessSource::Choice, $choiceAt, $choiceOffsetMs);
    });

    // Les étapes du § 9.1, dans cet ordre, et rien d'autre : la manche, puis
    // la participation, les paliers matérialisés, le compteur, la ligne
    // `guess`, la participation. Jamais la partie, ni lue, ni verrouillée.
    expect(array_map(static function (QueryExecuted $query): string {
        $sql = strtolower(ltrim($query->sql));
        preg_match('/^(select|insert|update|delete)\b/', $sql, $verb);
        preg_match(match ($verb[1] ?? '') {
            'insert' => '/\binto\s+[`"]?(\w+)/',
            'update' => '/^update\s+[`"]?(\w+)/',
            default => '/\bfrom\s+[`"]?(\w+)/',
        }, $sql, $table);

        return ($verb[1] ?? '?').' '.($table[1] ?? '?');
    }, $statements))->toBe([
        'select round',
        'select round_player',
        'select round_tier',
        'update round',
        'insert guess',
        'update round_player',
    ]);

    $expected = [
        'typo' => $expect([
            'match_kind' => GuessMatchKind::Title->value,
            'answer_key_id' => $fullKey->id,
            'answer_key_normalized' => 'lord of the rings the two towers',
            'submitted_normalized' => 'lord of the rings the two towerz',
            'edit_distance' => 1,
            'prefix_was_ambiguous' => false,
        ], $typoAt, 1, GuessSource::Text),
        'subtitle' => $expect([
            'match_kind' => GuessMatchKind::Subtitle->value,
            'answer_key_id' => $subtitleKey->id,
            'answer_key_normalized' => 'two towers',
            'submitted_normalized' => 'two towers',
            'edit_distance' => 0,
            'prefix_was_ambiguous' => false,
        ], $subtitleAt, 2, GuessSource::Text),
        'homonym' => $expect([
            'match_kind' => GuessMatchKind::Title->value,
            'answer_key_id' => $fullKey->id,
            'answer_key_normalized' => 'lord of the rings the two towers',
            'submitted_normalized' => 'lord of the rings the two towers',
            'edit_distance' => 0,
            'prefix_was_ambiguous' => true,
        ], $homonymAt, 3, GuessSource::Text),
        'choice' => $expect([
            'match_kind' => GuessMatchKind::Choice->value,
            'answer_key_id' => null,
            'answer_key_normalized' => 'lord of the rings the two towers',
            'submitted_normalized' => 'lord of the rings the two towers',
            'edit_distance' => 0,
            'prefix_was_ambiguous' => false,
        ], $choiceAt, 4, GuessSource::Choice),
    ];

    // Les paliers attendus, par construction des instants : 1, 2, 2 et N.
    expect(array_column($expected, 'tier_index'))->toBe([1, 2, 2, $framesPerRound])
        ->and($clicked->toArray())->toMatchArray(['result' => 'accepted', 'lockRank' => 4, 'tierIndex' => $framesPerRound]);

    foreach ($expected as $name => $snapshot) {
        $guess = SubmissionFixtures::guess($round, $seats[$name]);

        expect(lockGuessSnapshot($guess))->toBe($snapshot, $name)
            // Colonnes du schéma, et aucune autre : ni IP, ni horodatage
            // client, ni texte brut.
            ->and(array_keys($guess->getAttributes()))->toEqualCanonicalizing([
                'id', 'round_id', 'player_id', 'received_at', 'answered_at_ms', 'tier_index', 'lock_rank',
                'source', 'match_kind', 'answer_key_id', 'answer_key_normalized', 'submitted_normalized',
                'edit_distance', 'prefix_was_ambiguous', 'points_tier', 'points_bonus', 'points_total', 'created_at',
            ])
            ->and($guess->round_id)->toBe($round->id)
            ->and($guess->player_id)->toBe($seats[$name]->id)
            ->and(in_array($typed, $guess->getAttributes(), true))->toBeFalse()
            ->and(in_array($title, $guess->getAttributes(), true))->toBeFalse()
            ->and(SubmissionFixtures::participation($round, $seats[$name])->input_closed_at?->format('Y-m-d H:i:s.v'))
            ->toBe($snapshot['received_at']);
    }
});
