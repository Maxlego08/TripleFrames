<?php

use App\Actions\Game\SubmitTextAnswer;
use App\Enums\InputDifficulty;
use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Events\Game\InputClosed;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
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
| Relecture du refus sous concurrence — spec 70 § 7.5 et § 18 (arbitrage 3)
|--------------------------------------------------------------------------
|
| Groupe `locks-timing` (par répertoire), MySQL réel, `DatabaseTruncation` :
| deux connexions voient les écritures l'une de l'autre.
|
| « La relecture par clé primaire, toujours exécutée, fournit inputState et
| attemptsLeft exacts à la réponse. » En REPEATABLE READ (InnoDB), la lecture
| de `round_tier(N).served_at` fixe l'instantané de la transaction du refus.
| Quand un autre refus du même siège atteint le plafond et valide APRÈS cet
| instantané, l'instruction unique ne touche rien (lecture courante) ; une
| relecture simple rendrait alors l'instantané périmé (`open`, un essai
| restant) au lieu de l'état validé. Seule une relecture VERROUILLANTE lit la
| dernière version validée.
|
| L'entrelacement est rendu déterministe, sans processus ni horloge :
| 1. le premier refus atteint le plafond sur une connexion jumelle, dans une
|    transaction laissée ouverte — son écriture est faite, rien n'est validé ;
| 2. le second refus, par la route sur la connexion par défaut, lit encore
|    le siège `open` (S4), apparie (S6), ouvre sa transaction et lit
|    `served_at` : son instantané est pris ; à cet instant, le premier valide ;
| 3. l'instruction du second ne touche rien ; sa relecture doit rendre
|    `attempts_exhausted`. Aucune attente de verrou n'est attendue :
|    `innodb_lock_wait_timeout = 1` de session n'en est que la borne.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 15:30:00.125'));
});

test('deux refus concurrents d’un même siège au plafond moins un : celui qui ne compte rien répond 409 avec l’état validé', function (): void {
    Event::fake([InputClosed::class]);

    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], SubmissionFixtures::settings(InputDifficulty::Expert));
    $cap = $game->settings_snapshot->attemptsPerRound;
    $cadence = SubmissionFixtures::cadenceMs($game);
    $t1 = EngineFixtures::opensAt($round, 1);
    $firstAt = $t1->addMilliseconds($cadence);
    $secondAt = $t1->addMilliseconds(2 * $cadence);

    // Aucune transition échue pour l'un ni l'autre rattrapage : aucun verrou
    // exclusif sur `round`, seul le verrou de ligne de `round_player` compte.
    expect($secondAt->lessThan(EngineFixtures::opensAt($round, 2)))->toBeTrue();

    SubmissionFixtures::spend($round, $seat, $cap - 1);

    $default = DB::getDefaultConnection();
    $rival = 'submit_text_answer_rival';

    config(["database.connections.{$rival}" => config("database.connections.{$default}")]);

    $committed = false;
    $response = null;

    try {
        // 1. Premier refus, sur sa propre connexion, transaction laissée
        // ouverte : il atteint le plafond, rien n'est encore validé.
        DB::connection($rival)->beginTransaction();
        DB::setDefaultConnection($rival);
        Date::setTestNow($firstAt);

        $first = app(SubmitTextAnswer::class)->handle($seat, $game, 1, SubmissionFixtures::WRONG, $firstAt);

        expect($first->toArray())->toBe(SubmissionFixtures::rejectedBody(0, RoundPlayerInputState::AttemptsExhausted));

        // 2. Second refus, concurrent, par la route : le premier valide juste
        // après la lecture de `served_at`, qui a fixé l'instantané du second.
        DB::setDefaultConnection($default);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        $matched = false;

        DB::listen(static function (QueryExecuted $query) use (&$matched, &$committed, $default, $rival): void {
            if ($committed || $query->connectionName !== $default) {
                return;
            }

            if (SubmissionFixtures::touches($query->sql, 'answer_key')) {
                $matched = true;

                return;
            }

            if ($matched && SubmissionFixtures::touches($query->sql, 'round_tier')) {
                DB::connection($rival)->commit();
                $committed = true;
            }
        });

        $response = SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $secondAt);
    } finally {
        DB::setDefaultConnection($default);

        if (DB::connection($rival)->transactionLevel() > 0) {
            DB::connection($rival)->rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        DB::purge($rival);
    }

    expect($committed)->toBeTrue();

    // 3. Le second refus n'a rien compté : 409, avec l'état VALIDÉ du siège,
    // jamais l'instantané `open` de sa transaction.
    $response->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::French, RoundPlayerInputState::AttemptsExhausted));

    $participation = SubmissionFixtures::participation($round, $seat);

    expect($participation->input_state)->toBe(RoundPlayerInputState::AttemptsExhausted)
        ->and($participation->wrong_attempts)->toBe($cap)
        ->and($participation->input_closed_at?->equalTo($firstAt))->toBeTrue();

    // Une seule clôture annoncée : celle du premier refus, après son commit.
    Event::assertDispatchedTimes(InputClosed::class, 1);
});
