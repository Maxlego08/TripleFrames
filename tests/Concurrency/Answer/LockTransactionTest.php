<?php

use App\Actions\Game\SubmitChoice;
use App\Actions\Game\SubmitTextAnswer;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\AnswerAccepted;
use App\Events\Game\InputClosed;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Game;
use App\Models\Guess;
use App\Models\Player;
use App\Models\Round;
use App\Models\RoundPlayer;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Transaction de verrouillage sous concurrence — spec 70 § 7.6 et § 9.1,
| contrat C10 § 4, 10 § 7.6 et A12 (lots L70-6 et L70-9)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre.
|
| `lock_rank` est l'ordre d'acquisition du verrou de la manche, jamais un
| `SELECT MAX(lock_rank)` : deux bonnes réponses simultanées lisent sinon
| toutes deux 0, et la seconde heurte `guess_round_rank_uq` (1062) — une
| bonne réponse perdue. Sous `round FOR UPDATE`, la seconde ATTEND que la
| première valide, puis lit le compteur validé.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge :
| 1. la première bonne réponse se verrouille sur une connexion jumelle, dans
|    une transaction laissée ouverte — rang 1 écrit, rien de validé ;
| 2. la seconde, reçue au même instant, sur la connexion par défaut, juge sa
|    saisie sans rien attendre (lectures non verrouillantes), puis BUTE sur
|    la ligne `round` à la première instruction de sa transaction de
|    verrouillage : elle n'a encore rien lu du compteur, rien écrit. Sa
|    connexion attend au plus une seconde (`innodb_lock_wait_timeout` de
|    session : c'est la borne de l'attente, jamais le verdict) ;
| 3. la première valide ; la seconde, rejouée au même instant de réception
|    — ce que fait la requête en attente quand le verrou se libère —, lit le
|    compteur validé et prend le rang 2.
|
| Clic faux contre bonne réponse texte d'un même siège (lot L70-9) : la
| première écriture se joue sur la jumelle, transaction laissée ouverte ; la
| seconde, par la route sur la connexion par défaut, lit une saisie `open`
| (S4), et la jumelle valide À UNE LECTURE DONNÉE de la seconde — ses
| propositions (S5') pour un clic, ses clés (S6) pour un texte —, avant la
| transaction qui écrit. C'est donc la revérification sous verrou —
| instruction conditionnelle du clic faux, relecture de `LockGuess` — qui
| tranche, jamais la lecture de S4.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 18:55:00.250'));
});

test('deux bonnes réponses simultanées reçoivent des lock_rank distincts sans erreur 1062', function (): void {
    Event::fake([AnswerAccepted::class]);

    $firstToken = PlayerToken::mint(Locale::French);
    $secondToken = PlayerToken::mint(Locale::English);
    $target = SubmissionFixtures::movie('Harbour Lights');
    [$game, $round, [$firstSeat, $secondSeat]] = SubmissionFixtures::openedRound([$firstToken, $secondToken], target: $target);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    // Aucune transition échue au rattrapage : aucun verrou exclusif sur
    // `round` hors de la transaction de verrouillage elle-même.
    expect($at->lessThan(EngineFixtures::opensAt($round, 2)))->toBeTrue();

    $default = DB::getDefaultConnection();
    $rival = 'lock_guess_rival';

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $blocked = null;

    try {
        // 1. Première bonne réponse, sur sa propre connexion, transaction
        // laissée ouverte : rang 1 écrit, rien de validé.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);
        Date::setTestNow($at);

        $first = app(SubmitTextAnswer::class)->handle($firstSeat, $game, 1, 'Harbour Lights', $at);

        expect($first->toArray())->toMatchArray(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);

        // 2. Seconde bonne réponse, reçue au même instant, sur la connexion
        // par défaut : elle attend le verrou de la manche.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            app(SubmitTextAnswer::class)->handle($secondSeat, $game, 1, 'Harbour Lights', $at);
        } catch (QueryException $exception) {
            $blocked = $exception;
        }

        // Rien de la première n'est encore visible, rien de la seconde n'est
        // écrit, rien n'est annoncé.
        expect(Round::query()->whereKey($round->id)->value('found_count'))->toBe(0)
            ->and(Guess::query()->where('round_id', $round->id)->count())->toBe(0)
            ->and(SubmissionFixtures::participation($round, $secondSeat)->input_state)->toBe(RoundPlayerInputState::Open);
        Event::assertNotDispatched(AnswerAccepted::class);

        // 3. La première valide.
        DB::connection($rival)->commit();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    // La seconde a buté sur le verrou de la manche, première instruction de
    // sa transaction (MySQL 1205 : attente de verrou dépassée) — jamais sur
    // l'index unique des rangs (1062).
    expect($blocked)->toBeInstanceOf(QueryException::class)
        ->and($blocked?->errorInfo[1] ?? null)->toBe(1205)
        ->and(strtolower((string) $blocked?->getSql()))->toContain('`round`')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('for update')
        ->and(strtolower((string) $blocked?->getSql()))->not->toContain('`guess`');

    Event::assertDispatchedTimes(AnswerAccepted::class, 1);

    // Reprise au même instant de réception, par la route : le compteur validé
    // est lu sous le verrou, le rang 2 attribué, sans 1062.
    SubmissionFixtures::submit($this, $secondSeat, $secondToken, 'Harbour Lights', $at)
        ->assertOk()
        ->assertJson(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 2]);

    $guesses = Guess::query()->where('round_id', $round->id)->orderBy('lock_rank')->get();

    expect($guesses->pluck('lock_rank')->all())->toBe([1, 2])
        ->and($guesses->pluck('player_id')->all())->toBe([$firstSeat->id, $secondSeat->id])
        // Reçues au même instant : même décalage, même palier ; seul le rang
        // d'acquisition du verrou les distingue.
        ->and($guesses->pluck('answered_at_ms')->unique()->all())->toHaveCount(1)
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(2)
        ->and(SubmissionFixtures::participation($round, $firstSeat)->input_state)->toBe(RoundPlayerInputState::Locked)
        ->and(SubmissionFixtures::participation($round, $secondSeat)->input_state)->toBe(RoundPlayerInputState::Locked);

    Event::assertDispatchedTimes(AnswerAccepted::class, 2);
});

/**
 * Une partie Normal dont le QCM de la manche 1 est composé à `T_N` (lot
 * L70-9) : deux sièges, et l'instant de réception commun des soumissions
 * concurrentes, après `T_N`, sans aucune transition échue au rattrapage.
 *
 * @return array{game: Game, round: Round, seats: list<Player>, tokens: list<PlayerToken>, at: CarbonImmutable}
 */
function lockTransactionChoiceRound(): array
{
    $tokens = [PlayerToken::mint(Locale::French), PlayerToken::mint(Locale::French)];
    $target = SubmissionFixtures::movie('Harbour Lights', 'Les Feux du port');
    [$game, $round, $seats] = SubmissionFixtures::openedRound($tokens, SubmissionFixtures::settings(InputDifficulty::Normal), $target);
    SubmissionFixtures::decoyCandidates();

    $at = SubmissionFixtures::openChoices($round)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    expect($at->lessThan(EngineFixtures::durationEnd($round)))->toBeTrue();

    return ['game' => $game, 'round' => $round, 'seats' => $seats, 'tokens' => $tokens, 'at' => $at];
}

/**
 * Joue `$first` sur une connexion jumelle, dans une transaction laissée
 * ouverte, puis `$second` sur la connexion par défaut ; la jumelle valide
 * dès que `$second` exécute une requête sur `$table`. Rend ce que rend
 * `$second` et vrai si la jumelle a bien validé À CE MOMENT.
 *
 * Aucune attente de verrou n'est attendue : `innodb_lock_wait_timeout = 1`
 * de session n'en est que la borne, pour qu'un entrelacement raté échoue
 * vite au lieu de pendre.
 *
 * @template TResult
 *
 * @param  callable(): mixed  $first
 * @param  callable(): TResult  $second
 * @return array{0: TResult|null, 1: bool}
 */
function lockTransactionInterleave(callable $first, string $table, callable $second): array
{
    $default = DB::getDefaultConnection();
    $rival = 'lock_guess_rival';

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $committed = false;
    $result = null;

    try {
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);

        $first();

        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::listen(static function (QueryExecuted $query) use (&$committed, $default, $rival, $table): void {
            if ($committed || $query->connectionName !== $default || ! SubmissionFixtures::touches($query->sql, $table)) {
                return;
            }

            DB::connection($rival)->commit();
            $committed = true;
        });

        $result = $second();
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    return [$result, $committed];
}

/**
 * L'invariant de la saisie sur toutes les participations de la manche
 * (10 § 7.6, A16) : `locked` si et seulement si une ligne `guess` existe,
 * et jamais une ligne `guess` pour un clic faux.
 */
function lockTransactionAssertInvariant(Round $round): void
{
    foreach (RoundPlayer::query()->where('round_id', $round->id)->get() as $participation) {
        $guessed = Guess::query()
            ->where('round_id', $round->id)
            ->where('player_id', $participation->player_id)
            ->exists();

        expect($participation->input_state === RoundPlayerInputState::Locked)->toBe($guessed)
            ->and($participation->input_state === RoundPlayerInputState::QcmWrong && $guessed)->toBeFalse();
    }
}

test('un clic faux et une bonne réponse texte concurrents ne produisent jamais locked et qcm_wrong', function (): void {
    Event::fake([AnswerAccepted::class, InputClosed::class]);

    ['game' => $game, 'round' => $round, 'seats' => [$typedFirst, $clickedFirst], 'tokens' => $tokens, 'at' => $at] = lockTransactionChoiceRound();

    // 1. La bonne réponse texte d'abord, sur la jumelle, verrouillage écrit et
    // non validé ; le clic faux du MÊME siège, reçu au même instant, a lu une
    // saisie `open` (S4) quand le verrouillage valide, à sa lecture des
    // propositions (S5').
    $wrong = SubmissionFixtures::wrongChoice($round, $typedFirst);

    [$response, $committed] = lockTransactionInterleave(
        static function () use ($typedFirst, $game, $at): void {
            Date::setTestNow($at);

            expect(app(SubmitTextAnswer::class)->handle($typedFirst, $game, 1, 'Harbour Lights', $at)->toArray())
                ->toMatchArray(['result' => 'accepted', 'inputState' => 'locked', 'lockRank' => 1]);
        },
        'round_choice_set',
        fn () => SubmissionFixtures::click($this, $typedFirst, $tokens[0], $wrong, $at),
    );

    expect($committed)->toBeTrue();
    $response?->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::Locked));

    expect(SubmissionFixtures::participation($round, $typedFirst)->input_state)->toBe(RoundPlayerInputState::Locked);

    // 2. Le clic faux d'abord, sur la jumelle, `qcm_wrong` écrit et non
    // validé ; la bonne réponse texte du MÊME siège, reçue au même instant, a
    // lu une saisie `open` (S4) et apparie quand le clic valide, à sa lecture
    // des clés (S6) : la transaction de verrouillage relit l'état sous verrou.
    $wrong = SubmissionFixtures::wrongChoice($round, $clickedFirst);

    [$response, $committed] = lockTransactionInterleave(
        static function () use ($clickedFirst, $game, $at, $wrong): void {
            Date::setTestNow($at);

            expect(app(SubmitChoice::class)->handle($clickedFirst, $game, 1, $wrong, $at)->toArray())
                ->toBe(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::QcmWrong));
        },
        'answer_key',
        fn () => SubmissionFixtures::submit($this, $clickedFirst, $tokens[1], 'Harbour Lights', $at),
    );

    expect($committed)->toBeTrue();
    $response?->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::QcmWrong));

    // Jamais les deux : un siège verrouillé sans clic faux, un siège au clic
    // faux sans ligne `guess`, et une seule annonce de chaque.
    expect(SubmissionFixtures::participation($round, $clickedFirst)->input_state)->toBe(RoundPlayerInputState::QcmWrong)
        ->and(Guess::query()->where('round_id', $round->id)->pluck('player_id')->all())->toBe([$typedFirst->id])
        ->and(Round::query()->whereKey($round->id)->value('found_count'))->toBe(1);

    lockTransactionAssertInvariant($round);

    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
    Event::assertDispatched(AnswerAccepted::class, static fn (AnswerAccepted $event): bool => $event->playerId === $typedFirst->id);
    Event::assertDispatchedTimes(InputClosed::class, 1);
    Event::assertDispatched(InputClosed::class, static fn (InputClosed $event): bool => $event->playerId === $clickedFirst->id
        && $event->state === RoundPlayerInputState::QcmWrong);
});

test("un clic faux concurrent d'un verrouillage répond saisie close sans émettre InputClosed", function (): void {
    Event::fake([AnswerAccepted::class, InputClosed::class]);

    ['game' => $game, 'round' => $round, 'seats' => [$seat], 'tokens' => $tokens, 'at' => $at] = lockTransactionChoiceRound();
    $wrong = SubmissionFixtures::wrongChoice($round, $seat);
    $clickedAt = $at->addMillisecond();
    $recorded = [];

    // Le verrouillage valide pendant le clic, après que celui-ci a lu une
    // saisie `open` : c'est l'instruction conditionnelle du clic faux qui ne
    // touche rien, et sa relecture qui dit l'état validé.
    [$response, $committed] = lockTransactionInterleave(
        static function () use ($seat, $game, $at): void {
            Date::setTestNow($at);

            app(SubmitTextAnswer::class)->handle($seat, $game, 1, 'Harbour Lights', $at);
        },
        'round_choice_set',
        function () use ($seat, $tokens, $wrong, $clickedAt, &$recorded): TestResponse {
            $clicked = null;
            $recorded = SubmissionFixtures::queries(function () use ($seat, $tokens, $wrong, $clickedAt, &$clicked): void {
                $clicked = SubmissionFixtures::click($this, $seat, $tokens[0], $wrong, $clickedAt);
            });

            return $clicked ?? throw new LogicException('Le clic n’a rendu aucune réponse.');
        },
    );

    expect($committed)->toBeTrue();

    // Saisie close, avec l'état relu — `locked` —, jamais `rejected`.
    $response?->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::Locked));

    // L'instruction du clic faux a bien été tentée, et n'a rien touché : la
    // ligne est celle du verrouillage, à l'instant de réception de la bonne
    // réponse.
    $updates = array_values(array_filter(
        SubmissionFixtures::writes($recorded),
        static fn (string $sql): bool => SubmissionFixtures::touches($sql, 'round_player'),
    ));
    $participation = SubmissionFixtures::participation($round, $seat);

    expect($updates)->toHaveCount(1)
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Locked)
        ->and($participation->input_closed_at?->equalTo($at))->toBeTrue()
        ->and($participation->wrong_attempts)->toBe(0);

    lockTransactionAssertInvariant($round);

    // Aucune clôture annoncée : la base dit `locked`, que le clic n'a pas
    // écrit. Seul le verrouillage est annoncé.
    Event::assertNotDispatched(InputClosed::class);
    Event::assertDispatchedTimes(AnswerAccepted::class, 1);
});
