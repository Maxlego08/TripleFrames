<?php

use App\Enums\Locale;
use App\Enums\RoundPlayerInputState;
use App\Http\Middleware\CaptureReceptionInstant;
use App\Http\Middleware\EnsureActiveSeat;
use App\Http\Middleware\SetLocale;
use App\Jobs\Game\AdvanceRound;
use App\Jobs\Game\InterruptPausedGame;
use App\Models\Guess;
use App\Models\Player;
use App\Settings\RoomSettings;
use App\Settings\RoomSettingsBounds;
use App\Support\Identity\PlayerToken;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Answers\SubmissionFixtures;
use Tests\Support\Draw\PoolFixtures;
use Tests\Support\Game\EngineFixtures;
use Tests\Support\Room\LobbyWrites;
use Tests\Support\Room\SeatEntry;

/*
|--------------------------------------------------------------------------
| Limiteur `answer` et ordre des middlewares — spec 70 § 8, contrat C10
| § 2 et § 3, lot L70-14
|--------------------------------------------------------------------------
|
| Le limiteur est clé sur le SIÈGE que `seat.active` a résolu, jamais sur
| l'IP ni sur le `public_id` ; sa cadence est l'`attemptsPerSecond` de la
| partie courante. Il ne voit ce siège que parce que la liste de priorité de
| `bootstrap/app.php` fait passer `SetLocale` puis `EnsureActiveSeat` AVANT
| `ThrottleRequests`, lui-même avant la liaison implicite : sans ces rangs,
| la closure ne verrait jamais de siège (aucune cadence) et le 429 sortirait
| dans `APP_LOCALE`.
|
| Les soumissions passent par la route réelle et tout le groupe `web`,
| horloge figée à l'instant de réception. Le cache du limiteur est celui de
| la suite (`array`), neuf à chaque test ; ses échéances suivent l'horloge
| figée à la milliseconde.
|
*/

beforeEach(function (): void {
    PoolFixtures::fakeFramesDisk();
    Queue::fake([AdvanceRound::class, InterruptPausedGame::class]);
    SeatEntry::isolateCookies();

    Date::setTestNow(CarbonImmutable::parse('2026-09-27 18:20:00.375'));
});

/**
 * Enveloppe le limiteur `answer` RÉEL, sans rien en remplacer : chaque appel
 * relève le siège que la requête porte à cet instant, le paramètre `{player}`
 * tel que la route le tient encore, et le `Limit` rendu, puis rend ce `Limit`
 * tel quel.
 *
 * @param  list<array{seat: Player|null, player: string, limit: Limit}>  $calls
 */
function answerThrottleSpy(array &$calls): void
{
    $real = RateLimiter::limiter('answer');

    expect($real)->toBeInstanceOf(Closure::class, 'Limiteur answer absent : la route répondrait 500.');

    RateLimiter::for('answer', function (Request $request) use ($real, &$calls): Limit {
        /** @var Closure(Request): Limit $real */
        $limit = $real($request);
        $route = $request->route();

        $calls[] = [
            'seat' => EnsureActiveSeat::seat($request),
            'player' => $route instanceof RoutingRoute ? get_debug_type($route->parameter('player')) : 'null',
            'limit' => $limit,
        ];

        return $limit;
    });
}

/** La clé du budget texte d'un siège : son identifiant interne, jamais l'IP ni le `public_id`. */
function answerThrottleTextKey(Player $seat): string
{
    return 'answer:text:'.$seat->id;
}

/**
 * Le corps d'un 429 (§ 7.7) : le seul message `game.answer.too_fast`, dans
 * cette langue.
 *
 * @return array{message: string}
 */
function answerThrottleTooFastBody(Locale $locale): array
{
    $message = trans('game.answer.too_fast', [], $locale->value);

    expect($message)->toBeString()->not->toBe('game.answer.too_fast');

    return ['message' => (string) $message];
}

/** L'autre langue activée que celle-ci. */
function answerThrottleOtherLocale(Locale $locale): Locale
{
    $others = array_values(array_filter(Locale::cases(), static fn (Locale $case): bool => $case !== $locale));

    expect($others)->not->toBeEmpty();

    return $others[0];
}

/**
 * La pile réellement exécutée de `round.answer.store`, triée par la liste de
 * priorité — le noyau HTTP inscrit ses groupes et cette liste dans le
 * routeur à sa construction.
 *
 * @return list<string>
 */
function answerThrottleStack(): array
{
    app(HttpKernel::class);

    $route = app('router')->getRoutes()->getByName('round.answer.store');

    expect($route)->toBeInstanceOf(RoutingRoute::class);

    /** @var RoutingRoute $route */
    return array_values(array_map('strval', app('router')->gatherRouteMiddleware($route)));
}

it("le limiteur answer est clé sur le siège, jamais sur l'IP", function (): void {
    $calls = [];
    answerThrottleSpy($calls);

    $alice = PlayerToken::mint(Locale::French);
    $bob = PlayerToken::mint(Locale::English);
    [$game, $round, [$aliceSeat, $bobSeat]] = SubmissionFixtures::openedRound([$alice, $bob]);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));
    $left = $game->settings_snapshot->attemptsPerRound - 1;

    // Deux sièges derrière la même adresse, au même instant : deux budgets.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

    SubmissionFixtures::submit($this, $aliceSeat, $alice, SubmissionFixtures::WRONG, $at)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($left));

    SubmissionFixtures::submit($this, $bobSeat, $bob, SubmissionFixtures::WRONG, $at)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($left));

    // Le même siège depuis une autre adresse, dans la même seconde : le budget
    // suit le siège, pas l'adresse.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9']);

    SubmissionFixtures::submit($this, $aliceSeat, $alice, SubmissionFixtures::WRONG, $at->addMillisecond())
        ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertExactJson(answerThrottleTooFastBody(Locale::French));

    expect(SubmissionFixtures::participation($round, $aliceSeat)->wrong_attempts)->toBe(1)
        ->and(SubmissionFixtures::participation($round, $bobSeat)->wrong_attempts)->toBe(1);

    // La clé : le budget texte du siège, par son identifiant interne — ni
    // adresse, ni `public_id` —, à la cadence de la partie, sur une seconde.
    expect($calls)->toHaveCount(3);

    foreach ($calls as $index => $call) {
        $seat = $index === 1 ? $bobSeat : $aliceSeat;

        expect($call['limit'])->not->toBeInstanceOf(Unlimited::class)
            ->and($call['limit']->key)->toBe(answerThrottleTextKey($seat))
            ->and($call['limit']->maxAttempts)->toBe($game->settings_snapshot->attemptsPerSecond)
            ->and($call['limit']->decaySeconds)->toBe(1);

        foreach (['203.0.113.7', '198.51.100.9', '127.0.0.1', (string) $seat->public_id] as $forbidden) {
            expect(str_contains((string) $call['limit']->key, $forbidden))->toBeFalse();
        }
    }
});

it('une soumission trop rapide répond 429 traduit sans être comptée', function (): void {
    $target = SubmissionFixtures::movie('Heat');
    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], target: $target);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));
    $cap = $game->settings_snapshot->attemptsPerRound;

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($cap - 1));

    // Dans la même seconde, et même avec le bon titre : 429, le seul message
    // traduit, rien d'autre — ni état de saisie, ni tentatives restantes.
    $tooFast = null;
    $queries = SubmissionFixtures::queries(function () use (&$tooFast, $seat, $token, $at, $game): void {
        $tooFast = SubmissionFixtures::submit($this, $seat, $token, 'Heat', $at->addMilliseconds(SubmissionFixtures::cadenceMs($game) - 1));
    });

    $tooFast->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertExactJson(answerThrottleTooFastBody(Locale::French))
        ->assertHeader('Retry-After');

    // Ni évaluée, ni comptée : aucune lecture de la manche, de la
    // participation ni des clés de réponse, aucune écriture.
    foreach (['round', 'round_player', 'round_tier', 'answer_key', 'guess'] as $table) {
        expect(array_filter($queries, static fn (QueryExecuted $query): bool => SubmissionFixtures::touches($query->sql, $table)))
            ->toBeEmpty("Le 429 a lu {$table}.");
    }

    expect(SubmissionFixtures::writes($queries))->toBe([]);

    $participation = SubmissionFixtures::participation($round, $seat);

    expect($participation->wrong_attempts)->toBe(1)
        ->and($participation->input_state)->toBe(RoundPlayerInputState::Open)
        ->and(Guess::query()->where('round_player_id', $participation->id)->exists())->toBeFalse();

    // Une seconde après la première : jugée à nouveau, et le 429 n'a rien
    // coûté — ni au plafond de la manche, ni au budget de la seconde suivante.
    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at->addMilliseconds(SubmissionFixtures::cadenceMs($game)))
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($cap - 2));
});

it("un tiers qui connaît le public_id d'un siège ne consomme pas son budget", function (): void {
    $calls = [];
    answerThrottleSpy($calls);

    $victim = PlayerToken::mint(Locale::English);
    $intruder = PlayerToken::mint(Locale::French);
    [$game, $round, [$victimSeat, $intruderSeat]] = SubmissionFixtures::openedRound([$victim, $intruder]);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));
    $perSecond = $game->settings_snapshot->attemptsPerSecond;

    // Un joueur du même salon, qui connaît le `public_id` de sa victime — et,
    // au pire, son jeton d'onglet —, en rafale au-delà de la cadence : 403 à
    // chaque fois, avant le limiteur. Au même instant que la victime ensuite :
    // un budget consommé ici ne serait pas encore rendu.
    Date::setTestNow($at);

    for ($attempt = 0; $attempt <= $perSecond; $attempt++) {
        LobbyWrites::actAs($this, $intruder);

        $this->postJson(
            route('round.answer.store', $victimSeat),
            ['round' => 1, 'answer' => SubmissionFixtures::WRONG],
            [EnsureActiveSeat::HEADER => (string) $victimSeat->active_seat_token],
        )->assertForbidden();
    }

    // Un visiteur sans jeton, de même : une requête JSON n'emporte les
    // cookies que si elle le demande.
    $this->withCredentials = false;
    $this->postJson(
        route('round.answer.store', $victimSeat),
        ['round' => 1, 'answer' => SubmissionFixtures::WRONG],
        [EnsureActiveSeat::HEADER => (string) $victimSeat->active_seat_token],
    )->assertForbidden();

    // Le limiteur n'a jamais été consulté pour eux.
    expect($calls)->toBe([]);

    // La victime, au même instant : son budget est intact.
    SubmissionFixtures::submit($this, $victimSeat, $victim, SubmissionFixtures::WRONG, $at)
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($game->settings_snapshot->attemptsPerRound - 1));

    // Le tiers ne dépense que le sien, par son propre siège.
    SubmissionFixtures::submit($this, $intruderSeat, $intruder, SubmissionFixtures::WRONG, $at)
        ->assertOk();

    expect(array_map(static fn (array $call): string => (string) $call['limit']->key, $calls))
        ->toBe([answerThrottleTextKey($victimSeat), answerThrottleTextKey($intruderSeat)])
        ->and(SubmissionFixtures::participation($round, $victimSeat)->wrong_attempts)->toBe(1);
});

it('le limiteur answer voit le siège résolu par seat.active dans la pile réelle de middlewares', function (): void {
    // La pile réelle : `SetLocale`, puis `seat.active`, puis le limiteur,
    // puis la liaison implicite (70 § 8), quel que soit l'ordre déclaré.
    $stack = answerThrottleStack();
    $at = static fn (string $middleware): int|false => array_search($middleware, $stack, true);

    expect($at(SetLocale::class))->toBeInt()
        ->and($at(EnsureActiveSeat::class))->toBeInt()
        ->and($at(ThrottleRequests::class.':answer'))->toBeInt()
        ->and($at(SubstituteBindings::class))->toBeInt()
        ->and($at(SetLocale::class))->toBeLessThan($at(EnsureActiveSeat::class))
        ->and($at(EnsureActiveSeat::class))->toBeLessThan($at(ThrottleRequests::class.':answer'))
        ->and($at(ThrottleRequests::class.':answer'))->toBeLessThan($at(SubstituteBindings::class));

    $calls = [];
    answerThrottleSpy($calls);

    // Une partie dont la cadence n'est pas celle du défaut : le limiteur la lit
    // sur la partie courante que `seat.active` a mise en mémoire.
    $perSecond = RoomSettingsBounds::MAX_ATTEMPTS_PER_SECOND;

    expect($perSecond)->not->toBe(RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND);

    $token = PlayerToken::mint(Locale::French);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token], RoomSettings::fromInput([
        'roundsCount' => RoomSettingsBounds::MIN_ROUNDS_COUNT,
        'attemptsPerSecond' => $perSecond,
    ]));
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at)->assertOk();

    // Le siège résolu, alors que `{player}` n'est encore qu'une chaîne : le
    // limiteur passe APRÈS `seat.active` et AVANT la liaison.
    expect($calls)->toHaveCount(1)
        ->and($calls[0]['seat']?->is($seat))->toBeTrue()
        ->and($calls[0]['player'])->toBe('string')
        ->and($calls[0]['limit'])->not->toBeInstanceOf(Unlimited::class)
        ->and($calls[0]['limit']->key)->toBe(answerThrottleTextKey($seat))
        ->and($calls[0]['limit']->maxAttempts)->toBe($perSecond);

    // Sans partie courante (salon au lobby) : siège résolu, cadence du défaut
    // de `RoomSettingsBounds`, et le contrôleur répond 409.
    $lobbyToken = PlayerToken::mint(Locale::English);
    [, $lobbySeat] = LobbyWrites::hostedRoom($lobbyToken);

    SubmissionFixtures::submit($this, $lobbySeat, $lobbyToken, SubmissionFixtures::WRONG, $at)
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertExactJson(SubmissionFixtures::closedBody(Locale::English));

    expect($calls)->toHaveCount(2)
        ->and($calls[1]['seat']?->is($lobbySeat))->toBeTrue()
        ->and($calls[1]['limit']->key)->toBe(answerThrottleTextKey($lobbySeat))
        ->and($calls[1]['limit']->maxAttempts)->toBe(RoomSettingsBounds::DEFAULT_ATTEMPTS_PER_SECOND);
});

it('le limiteur answer limite réellement une seconde soumission du même siège dans la seconde, middlewares du groupe web compris', function (): void {
    // La route vit dans le groupe `web` et la requête le traverse en entier :
    // aucun middleware du groupe n'est retiré, `CaptureReceptionInstant` et la
    // session compris.
    app(HttpKernel::class);
    $stack = answerThrottleStack();
    $web = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($web)->toContain(CaptureReceptionInstant::class, StartSession::class, SetLocale::class);

    foreach ($web as $middleware) {
        expect($stack)->toContain($middleware);
    }

    $token = PlayerToken::mint(Locale::English);
    [$game, $round, [$seat]] = SubmissionFixtures::openedRound([$token]);
    $perSecond = $game->settings_snapshot->attemptsPerSecond;
    $cadenceMs = SubmissionFixtures::cadenceMs($game);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds($cadenceMs);

    // Le budget de la seconde, puis une soumission de trop dans cette même
    // seconde, à sa dernière milliseconde.
    for ($attempt = 0; $attempt < $perSecond; $attempt++) {
        SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at->addMilliseconds($attempt))->assertOk();
    }

    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at->addMilliseconds(999))
        ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
        ->assertExactJson(answerThrottleTooFastBody(Locale::English));

    expect(SubmissionFixtures::participation($round, $seat)->wrong_attempts)->toBe($perSecond);

    // La seconde écoulée, le siège soumet de nouveau.
    SubmissionFixtures::submit($this, $seat, $token, SubmissionFixtures::WRONG, $at->addSecond())
        ->assertOk()
        ->assertExactJson(SubmissionFixtures::rejectedBody($game->settings_snapshot->attemptsPerRound - $perSecond - 1));
});

it('répond 429 dans la locale résolue de la requête et non dans APP_LOCALE', function (): void {
    $locales = Locale::cases();
    $tokens = array_map(static fn (Locale $locale): PlayerToken => PlayerToken::mint($locale), $locales);
    [$game, $round, $seats] = SubmissionFixtures::openedRound($tokens);
    $at = EngineFixtures::opensAt($round, 1)->addMilliseconds(SubmissionFixtures::cadenceMs($game));

    foreach ($locales as $index => $locale) {
        // L'instance tourne dans l'AUTRE langue que celle du joueur.
        $instance = answerThrottleOtherLocale($locale);
        config(['app.locale' => $instance->value]);
        App::setLocale($instance->value);

        SubmissionFixtures::submit($this, $seats[$index], $tokens[$index], SubmissionFixtures::WRONG, $at)->assertOk();

        // Chaque requête de production naît dans un processus neuf, à la
        // locale d'instance : la précédente ne doit rien lui avoir laissé.
        App::setLocale($instance->value);

        $tooFast = SubmissionFixtures::submit($this, $seats[$index], $tokens[$index], SubmissionFixtures::WRONG, $at->addMillisecond())
            ->assertStatus(Response::HTTP_TOO_MANY_REQUESTS)
            ->assertExactJson(answerThrottleTooFastBody($locale));

        expect($tooFast->json('message'))->not->toBe(answerThrottleTooFastBody($instance)['message']);
    }
});

it('le limiteur answer refuse de compter une autre route que les soumissions de la saisie', function (): void {
    // Deux budgets par siège, un par route de soumission (70 § 8) : posé sur
    // une autre route, le limiteur ne sait pas quel budget elle consomme, et
    // le dit plutôt que de compter au hasard.
    Route::middleware(['web', 'seat.active', 'throttle:answer'])
        ->post('/_test/answer-throttle/{player}', static fn () => response()->noContent());

    $token = PlayerToken::mint(Locale::French);
    [, $seat] = LobbyWrites::hostedRoom($token);
    LobbyWrites::actAs($this, $token);

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson(
        '/_test/answer-throttle/'.$seat->public_id,
        [],
        [EnsureActiveSeat::HEADER => (string) $seat->active_seat_token],
    ))->toThrow(LogicException::class, 'Le limiteur answer ne compte que les soumissions de la saisie');
});
