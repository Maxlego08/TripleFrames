<?php

use App\Actions\Game\SubmitTextAnswer;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\AnswerAccepted;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Guess;
use App\Models\Round;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Transaction de verrouillage sous concurrence — spec 70 § 9.1, contrat C10
| § 4, 10 § 7.6 et A12 (lot L70-6)
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
