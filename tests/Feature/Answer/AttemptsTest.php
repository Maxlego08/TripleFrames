<?php

use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\InputClosed;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Movie;
use App\Models\NearMiss;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Models\RoundTier;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Tentatives, plafond et épuisement — spec 70 § 3 et § 8
|--------------------------------------------------------------------------
|
| Fichier partagé entre lots : L70-4 y pose l'effet de `text_exhausted` sur
| la fin anticipée (D20 du 23/09, E10-53) ; L70-5 (plafond, comptage,
| routes) et L70-9 (clic à `T_N`) le complètent.
|
| Les soumissions de L70-5 passent par la route réelle, horloge figée à
| l'instant de réception, sur une partie matérialisée et ouverte par les
| transitions réelles. Le plafond (`attemptsPerRound`) et la cadence se
| lisent sur la partie ; deux soumissions d'un même siège sont toujours
| espacées d'au moins la cadence, pour que le limiteur `answer` (L70-14) ne
| refuse jamais ce que ces tests n'éprouvent pas. Un siège qui a « déjà
| épuisé » des tentatives les a comptées comme 70 les compte
| (`SubmissionFixtures::spend()`), pour atteindre le plafond sans quinze
| requêtes — sauf le test du plafond en Expert, qui les joue toutes.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 15:30:00.125'));
});

/**
 * Une manche en cours d'une partie en Normal, seule difficulté où
 * `text_exhausted` est atteignable (10 A16).
 */
function attemptsNormalRound(): Round
{
    $game = Game::factory()->create();

    expect($game->input_difficulty)->toBe(InputDifficulty::Normal);

    return Round::factory()->forGame($game)->running()->create();
}

/**
 * Un siège du salon de la manche, déconnecté à la demande.
 */
function attemptsSeat(Round $round, bool $disconnected = false): Player
{
    $factory = Player::factory();

    return ($disconnected ? $factory->disconnected() : $factory)->create(['room_id' => $round->room_id]);
}

/**
 * Un siège verrouillé, avec la ligne `guess` que l'invariant exige.
 */
function attemptsLockedSeat(Round $round): RoundPlayer
{
    $player = attemptsSeat($round);
    Guess::factory()->forRound($round, $player)->create();

    return RoundPlayer::factory()->forRound($round, $player)->locked()->create();
}

it('un siège text_exhausted ne compte pas comme saisie close pour la fin anticipée', function (): void {
    // A a trouvé ; B a épuisé son texte libre avant `T_N` et attend le QCM.
    $round = attemptsNormalRound();
    attemptsLockedSeat($round);
    $seatB = RoundPlayer::factory()->forRound($round, attemptsSeat($round))->textExhausted()->create();

    expect($seatB->input_state->isClosed())->toBeFalse()
        ->and($seatB->input_closed_at)->toBeNull()
        ->and($round->isEarlyEndReached())->toBeFalse();

    // B clique à `T_N` — ici faux : sa saisie se clôt, et avec elle la manche.
    RoundPlayer::query()->whereKey($seatB->id)->update([
        'input_state' => RoundPlayerInputState::QcmWrong,
        'input_closed_at' => now(),
    ]);

    expect($round->isEarlyEndReached())->toBeTrue();

    // Seul participant, un siège `text_exhausted` suffit à tenir la manche
    // ouverte : la borne `COUNT(participants) >= 1` est satisfaite, pas la
    // clôture.
    $alone = attemptsNormalRound();
    RoundPlayer::factory()->forRound($alone, attemptsSeat($alone))->textExhausted()->create();

    expect($alone->isEarlyEndReached())->toBeFalse();

    // Le dénominateur, lui, ne change pas avec D20 : un siège `text_exhausted`
    // déconnecté n'est pas un participant et ne bloque rien.
    $away = attemptsNormalRound();
    attemptsLockedSeat($away);
    RoundPlayer::factory()->forRound($away, attemptsSeat($away, disconnected: true))->textExhausted()->create();

    expect($away->isEarlyEndReached())->toBeTrue();
});

/** Le plafond de tentatives en texte libre de la partie. */
function attemptsCap(Game $game): int
{
    return $game->settings_snapshot->attemptsPerRound;
}

/**
 * Le palier dont l'ouverture pousse le QCM en Normal (`T_N`), lu par la seule
 * source ; l'instant existe quelle que soit la difficulté jouée.
 */
function attemptsChoicesTier(Game $game): int
{
    return InputDifficulty::Normal->choicesOpenTierIndex($game->frames_per_round)
        ?? throw new LogicException('La difficulté Normal ouvre toujours son QCM à un palier.');
}

/**
 * L'ouverture de `T_N` appliquée SANS QCM, posée directement comme la
 * transaction de composition terminale la laisse (60 § 6.3, étapes 4 et 6) :
 * `round_tier(N).served_at = T_N`, leurres laissés NULL. Jamais par
 * `OpenTier(N)` : sa composition, branchée par L60-11, convertirait aussi les
 * autres sièges `text_exhausted` du test (70 § Lots, L70-5 : « les tests de
 * S8a posent `round_tier(N).served_at` et `round.decoy_movie_id_1` »). Le
 * rattrapage d'une soumission postérieure trouve le palier servi et ne
 * l'ouvre plus.
 */
function attemptsServeChoicesTier(Round $round, int $choicesTier, CarbonImmutable $tN): void
{
    $served = RoundTier::query()
        ->where('round_id', $round->id)
        ->where('tier_index', $choicesTier)
        ->whereNull('served_at')
        ->update(['served_at' => (new RoundTier)->fromDateTime($tN)]);

    expect($served)->toBe(1)
        ->and(Round::query()->whereKey($round->id)->value('decoy_movie_id_1'))->toBeNull();
}

/**
 * Les écritures d'un relevé de requêtes qui portent sur `round_player`.
 *
 * @param  list<QueryExecuted>  $queries
 * @return list<string>
 */
function attemptsRoundPlayerWrites(array $queries): array
{
    return array_values(array_filter(
        SubmissionFixtures::writes($queries),
        static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'round_player'),
    ));
}

it('chaque refus compte, même répété', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);

    // La même saisie fausse, trois fois : aucune n'est reconnue comme déjà
    // soumise, puisqu'aucune saisie fausse n'est mémorisée (décision 19).
    foreach ([1, 2, 3] as $attempt) {
        $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $t1->addMilliseconds($attempt * $cadence))
            ->assertOk()
            ->assertExactJson(SubmissionFixtures::rejectedBody(attemptsCap($game) - $attempt)));

        // Une seule écriture par refus : l'instruction unique de `round_player`.
        expect(SubmissionFixtures::writes($queries))->toHaveCount(1)
            ->and(attemptsRoundPlayerWrites($queries))->toHaveCount(1)
            ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe($attempt);
    }

    // Comptées, jamais stockées : ni texte, ni ligne.
    expect(Guess::query()->count())->toBe(0)
        ->and(NearMiss::query()->count())->toBe(0)
        ->and(SubmissionFixtures::participation($round, $seat)->input_state)->toBe(RoundPlayerInputState::Open);
});

it('le plafond ferme le texte en Expert', function (): void {
    Event::fake([InputClosed::class]);

    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Expert));
    $cap = attemptsCap($game);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);

    // Toutes les tentatives, une à une, jusqu'au plafond.
    for ($attempt = 1; $attempt < $cap; $attempt++) {
        SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $t1->addMilliseconds($attempt * $cadence))
            ->assertOk()
            ->assertExactJson(SubmissionFixtures::rejectedBody($cap - $attempt));
    }

    Event::assertNotDispatched(InputClosed::class);

    $last = $t1->addMilliseconds($cap * $cadence);

    // La manche dure encore : c'est le plafond, et lui seul, qui ferme.
    expect($last->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue();

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $last)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

    $participation = SubmissionFixtures::participation($round, $seat);

    expect($participation->input_state)->toBe(RoundPlayerInputState::AttemptsExhausted)
        ->and($participation->wrong_attempts)->toBe($cap)
        ->and($participation->input_closed_at?->equalTo($last))->toBeTrue();

    Event::assertDispatchedTimes(InputClosed::class, 1);
    Event::assertDispatched(InputClosed::class, static fn (InputClosed $event): bool => $event->roundId === $round->id
        && $event->playerId === $seat->id
        && $event->state === RoundPlayerInputState::AttemptsExhausted);

    // Le texte est fermé : la soumission suivante n'est ni jugée ni comptée.
    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $last->addMilliseconds($cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::AttemptsExhausted));

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe($cap);
    Event::assertDispatchedTimes(InputClosed::class, 1);
});

it('en Normal, après une composition terminale, l\'épuisement du texte ferme la saisie en attempts_exhausted', function (): void {
    Event::fake([InputClosed::class]);

    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    [$game, $round, [$before, $terminal, $composed]] = SubmissionFixtures::openedRound($tokens);
    $cap = attemptsCap($game);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $choicesTier = attemptsChoicesTier($game);
    $tN = EngineFixtures::opensAt($round, $choicesTier);

    expect($game->input_difficulty)->toBe(InputDifficulty::Normal);

    foreach ([$before, $terminal, $composed] as $seat) {
        SubmissionFixtures::spend($round, $seat, $cap - 1);
    }

    // Avant `T_N`, le QCM est à venir : texte épuisé, QCM attendu.
    SubmissionFixtures::submit($this, $before, $tokens[0], SubmissionFixtures::WRONG, $tN->subMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    expect(SubmissionFixtures::participation($round, $before)->input_closed_at)->toBeNull();
    Event::assertNotDispatched(InputClosed::class);

    // À `T_N`, l'ouverture est appliquée SANS QCM — aucune composition n'a
    // posé de leurre : c'est la composition terminale. L'épuisement ferme la
    // saisie, à l'instant de réception.
    attemptsServeChoicesTier($round, $choicesTier, $tN);

    $closedAt = $tN->addMilliseconds($cadence);

    SubmissionFixtures::submit($this, $terminal, $tokens[1], SubmissionFixtures::WRONG, $closedAt)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

    expect(EngineFixtures::tier($round, $choicesTier)->served_at)->not->toBeNull()
        ->and(Round::query()->whereKey($round->id)->value('decoy_movie_id_1'))->toBeNull();

    $closed = SubmissionFixtures::participation($round, $terminal);

    expect($closed->input_state)->toBe(RoundPlayerInputState::AttemptsExhausted)
        ->and($closed->wrong_attempts)->toBe($cap)
        ->and($closed->input_closed_at?->equalTo($closedAt))->toBeTrue();

    Event::assertDispatchedTimes(InputClosed::class, 1);
    Event::assertDispatched(InputClosed::class, static fn (InputClosed $event): bool => $event->playerId === $terminal->id
        && $event->state === RoundPlayerInputState::AttemptsExhausted);

    // Témoin : QCM composé à `T_N` (trois leurres posés), il reste à cliquer —
    // l'épuisement du texte n'y ferme pas la saisie.
    $decoys = Movie::factory()->count(3)->create()->modelKeys();
    Round::query()->whereKey($round->id)->update([
        'decoy_movie_id_1' => $decoys[0],
        'decoy_movie_id_2' => $decoys[1],
        'decoy_movie_id_3' => $decoys[2],
    ]);

    SubmissionFixtures::submit($this, $composed, $tokens[2], SubmissionFixtures::WRONG, $closedAt->addMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));

    expect(SubmissionFixtures::participation($round, $composed)->input_closed_at)->toBeNull();
    Event::assertDispatchedTimes(InputClosed::class, 1);
});

it('un épuisement reçu avant T_N et traité après une composition terminale ferme la saisie en attempts_exhausted', function (): void {
    Event::fake([InputClosed::class]);

    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $choicesTier = attemptsChoicesTier($game);
    $tN = EngineFixtures::opensAt($round, $choicesTier);
    $receivedAt = $tN->subMilliseconds(SubmissionFixtures::cadenceMs($game));

    SubmissionFixtures::spend($round, $seat, attemptsCap($game) - 1);

    // La transaction d'ouverture de `T_N` — composition terminale, posée
    // directement (`attemptsServeChoicesTier()`) — est validée PENDANT le
    // traitement de la soumission : après la lecture de la manche (S3) et de
    // la participation (S4), au moment de l'appariement (S6), avant le refus
    // (S8a). Le rattrapage de la requête, à son instant de réception, ne l'a
    // pas exécutée : `T_N` n'était pas échu.
    $applied = false;

    DB::listen(static function (QueryExecuted $query) use (&$applied, $round, $choicesTier, $tN): void {
        if ($applied || ! SubmissionFixtures::touches($query->sql, 'answer_key')) {
            return;
        }

        $applied = true;

        attemptsServeChoicesTier($round, $choicesTier, $tN);
    });

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $receivedAt)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

    expect($applied)->toBeTrue()
        ->and(EngineFixtures::tier($round, $choicesTier)->served_at?->equalTo($tN))->toBeTrue();

    // L'état du QCM est relu dans la transaction du refus : jamais
    // `text_exhausted` après une composition terminale, qui ne le
    // convertirait plus. L'instant de clôture reste celui de la RÉCEPTION.
    $participation = SubmissionFixtures::participation($round, $seat);

    expect($participation->input_state)->toBe(RoundPlayerInputState::AttemptsExhausted)
        ->and($participation->input_closed_at?->equalTo($receivedAt))->toBeTrue();

    Event::assertDispatchedTimes(InputClosed::class, 1);

    // Témoin : le même épuisement, reçu au même instant de sa manche sans
    // transition concurrente, attend le QCM.
    Date::setTestNow($tN->addHour());

    $witnessToken = PlayerToken::mint(Locale::French);
    [$witnessGame, $witnessRound, [$witness]] = SubmissionFixtures::openedRound([$witnessToken]);
    SubmissionFixtures::spend($witnessRound, $witness, attemptsCap($witnessGame) - 1);

    $witnessAt = EngineFixtures::opensAt($witnessRound, $choicesTier)->subMilliseconds(SubmissionFixtures::cadenceMs($witnessGame));

    SubmissionFixtures::submit($this, $witness, $witnessToken, SubmissionFixtures::WRONG, $witnessAt)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::TextExhausted));
});

it('en Facile la route texte répond saisie close sans compter', function (): void {
    $target = SubmissionFixtures::movie('Heat');
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Easy), $target);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);

    // Une saisie fausse, puis le titre exact : ni l'une ni l'autre n'est
    // jugée — Facile n'a pas de texte libre.
    $queries = [];

    foreach ([SubmissionFixtures::WRONG, $target->title_original] as $step => $answer) {
        $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, $answer, $t1->addMilliseconds(($step + 1) * $cadence))
            ->assertStatus(Response::HTTP_CONFLICT)
            ->assertExactJson(SubmissionFixtures::closedBody(Locale::French)))];
    }

    $participation = SubmissionFixtures::participation($round, $seat);

    expect($participation->wrong_attempts)->toBe(0)
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Open)
        ->and(attemptsRoundPlayerWrites($queries))->toBe([])
        ->and(array_values(array_filter(
            array_map(static fn (QueryExecuted $query): string => $query->sql, $queries),
            static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'answer_key'),
        )))->toBe([])
        ->and(Guess::query()->count())->toBe(0);
});

it('une saisie vide après normalisation ou trop longue est refusée en 422 sans compter', function (): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $max = $game->settings_snapshot->maxAnswerLength;
    $t1 = EngineFixtures::opensAt($round, 1);
    $attribute = trans('validation.attributes.answer', [], Locale::French->value);

    expect($attribute)->toBeString()->not->toBe('validation.attributes.answer');

    $refused = [
        // Aucune lettre ni chiffre : vide APRÈS normalisation.
        ['!!! ? …', trans('game.answer.unreadable', [], Locale::French->value)],
        // Blanc : vide avant même la normalisation.
        ['   ', trans('validation.required', ['attribute' => $attribute], Locale::French->value)],
        // Un caractère de plus que la longueur maximale de la partie, mesurée
        // en caractères et non en octets.
        [str_repeat('é', $max + 1), trans('validation.max.string', ['attribute' => $attribute, 'max' => $max], Locale::French->value)],
    ];

    $queries = [];

    foreach ($refused as $step => [$answer, $message]) {
        expect($message)->toBeString();

        $queries = [...$queries, ...SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, $answer, $t1->addMilliseconds(($step + 1) * $cadence))
            ->assertUnprocessable()
            ->assertExactJson(['message' => $message, 'errors' => ['answer' => [$message]]]))];
    }

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0)
        ->and(attemptsRoundPlayerWrites($queries))->toBe([]);

    // La longueur maximale elle-même est jugée, et comptée.
    SubmissionFixtures::submit($this, $seat, $token, str_repeat('é', $max), $t1->addMilliseconds((count($refused) + 1) * $cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(attemptsCap($game) - 1));

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(1);
});

it('n\'atteint jamais text_exhausted hors difficulté Normal', function (InputDifficulty $difficulty, ?RoundPlayerInputState $beforeChoices, ?RoundPlayerInputState $afterChoices): void {
    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    [$game, $round, [$early, $late]] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings($difficulty));
    $cap = attemptsCap($game);
    $cadence = SubmissionFixtures::cadenceMs($game);
    // L'instant où le QCM de Normal s'ouvrirait, quelle que soit la
    // difficulté jouée.
    $choicesTier = attemptsChoicesTier($game);
    $tN = EngineFixtures::opensAt($round, $choicesTier);

    SubmissionFixtures::spend($round, $early, $cap - 1);
    SubmissionFixtures::spend($round, $late, $cap - 1);

    $cases = [
        [$early, $tokens[0], $tN->subMilliseconds($cadence), $beforeChoices],
        [$late, $tokens[1], $tN->addMilliseconds($cadence), $afterChoices],
    ];

    foreach ($cases as $step => [$seat, $token, $at, $expected]) {
        // `T_N` franchi entre les deux soumissions : ouverture appliquée sans
        // QCM, en toute difficulté.
        if ($step === 1) {
            attemptsServeChoicesTier($round, $choicesTier, $tN);
        }

        $response = SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at);

        // `null` : pas de texte libre, rien n'est jugé.
        if ($expected === null) {
            $response->assertStatus(Response::HTTP_CONFLICT)->assertExactJson(SubmissionFixtures::closedBody(Locale::French));
        } else {
            $response->assertOk()->assertExactJson(SubmissionFixtures::rejectedBody(0, $expected));
        }

        expect(SubmissionFixtures::participation($round, $seat)->input_state)->toBe($expected ?? RoundPlayerInputState::Open);
    }

    $reached = RoundPlayer::query()
        ->where('round_id', $round->id)
        ->where('input_state', RoundPlayerInputState::TextExhausted->value)
        ->exists();

    expect($reached)->toBe($difficulty === InputDifficulty::Normal);
})->with([
    'Expert' => [InputDifficulty::Expert, RoundPlayerInputState::AttemptsExhausted, RoundPlayerInputState::AttemptsExhausted],
    'Facile' => [InputDifficulty::Easy, null, null],
    // Témoin : la seule difficulté où l'état est atteignable.
    'Normal' => [InputDifficulty::Normal, RoundPlayerInputState::TextExhausted, RoundPlayerInputState::AttemptsExhausted],
]);

it('wrong_attempts ne dépasse jamais attemptsPerRound', function (): void {
    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    [$game, $round, [$seat, $stale]] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings(InputDifficulty::Expert));
    $cap = attemptsCap($game);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);

    SubmissionFixtures::spend($round, $seat, $cap - 1);

    SubmissionFixtures::submit($this, $seat, $tokens[0], SubmissionFixtures::WRONG, $t1->addMilliseconds($cadence))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

    SubmissionFixtures::submit($this, $seat, $tokens[0], SubmissionFixtures::WRONG, $t1->addMilliseconds(2 * $cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::AttemptsExhausted));

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe($cap);

    // La garde de l'instruction elle-même : une ligne restée `open` au
    // plafond — état qu'aucun écrivain ne produit — n'est jamais incrémentée.
    SubmissionFixtures::spend($round, $stale, $cap);

    SubmissionFixtures::submit($this, $stale, $tokens[1], SubmissionFixtures::WRONG, $t1->addMilliseconds(3 * $cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French));

    expect(SubmissionFixtures::participation($round, $stale)->wrong_attempts)->toBe($cap)
        ->and((int) RoundPlayer::query()->where('round_id', $round->id)->max('wrong_attempts'))->toBe($cap);
});

it('après revealed ou skipped, la route texte répond 409 sans rien écrire', function (RoundPlayerInputState $state): void {
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], solo: true);
    $cadence = SubmissionFixtures::cadenceMs($game);
    $gesture = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadence);

    expect($state->isSoloOnly())->toBeTrue()
        ->and($seat->room_id)->toBeNull();

    // Le geste solo, écrit comme 60 l'écrit : état et instant de clôture.
    RoundPlayer::query()
        ->where('round_id', $round->id)
        ->where('player_id', $seat->id)
        ->update(['input_state' => $state->value, 'input_closed_at' => (new RoundPlayer)->fromDateTime($gesture)]);

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $gesture->addMilliseconds($cadence))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, $state)));

    $participation = SubmissionFixtures::participation($round, $seat);

    expect(SubmissionFixtures::writes($queries))->toBe([])
        ->and($participation->input_state)->toBe($state)
        ->and($participation->wrong_attempts)->toBe(0)
        ->and($participation->input_closed_at?->equalTo($gesture))->toBeTrue()
        ->and(Guess::query()->count())->toBe(0);
})->with([
    'revealed' => [RoundPlayerInputState::Revealed],
    'skipped' => [RoundPlayerInputState::Skipped],
]);

it('une seconde soumission d\'un siège verrouillé répond 409 closed avec inputState locked', function (): void {
    $token = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);

    // Verrouillé comme la transaction de verrouillage le laisse : la ligne
    // `guess` et l'état (10 A16).
    Guess::factory()->forRound($round, $seat)->create();
    RoundPlayer::query()
        ->where('round_id', $round->id)
        ->where('player_id', $seat->id)
        ->update(['input_state' => RoundPlayerInputState::Locked->value, 'input_closed_at' => (new RoundPlayer)->fromDateTime(Date::now())]);

    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    $queries = SubmissionFixtures::queries(fn () => SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English, RoundPlayerInputState::Locked)));

    expect(SubmissionFixtures::writes($queries))->toBe([])
        ->and(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe(0);
});
