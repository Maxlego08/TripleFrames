<?php

use App\Enums\Locale;
use App\Http\Middleware\CaptureReceptionInstant;
use App\Models\Round;
use App\Models\RoundTier;
use App\Settings\EngineConstants;
use App\Support\Game\ReceptionInstant;
use App\Support\Game\RoundClock;
use App\Support\Identity\PlayerToken;
use App\Support\Identity\PlayerTokenCookie;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Horloge et instant de réception — spec 60 § 2.1, § 2.2, § 2.4 et § 20
|--------------------------------------------------------------------------
|
| Trois garanties du serveur autoritaire sur le temps :
| - `clock.show` sert l'instant serveur de la poignée de main d'horloge,
|   affichage seulement, par une pile sans session ni `Set-Cookie` ;
| - l'instant de réception est pris une fois, en tête du groupe `web`, et
|   aucune attente ultérieure (limiteur, verrou) ne le décale ;
| - le décalage d'une manche est un entier de millisecondes calculé sur les
|   microsecondes, par la seule formule du dépôt.
|
*/

/** Format de comparaison des instants : à la microseconde, en UTC. */
function clockTestMicros(CarbonImmutable $instant): string
{
    return $instant->utc()->format('Y-m-d H:i:s.u');
}

/** La pile de middlewares réellement exécutée pour une route, dans l'ordre. */
function clockTestStack(string $routeName): array
{
    $route = app('router')->getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull();

    return app('router')->gatherRouteMiddleware($route);
}

/** Un palier matérialisé, en mémoire : les seules colonnes de sa fenêtre. */
function clockTestTier(int $tierIndex, int $startsAtOffsetMs, int $durationMs, int $points): RoundTier
{
    return (new RoundTier)->forceFill([
        'tier_index' => $tierIndex,
        'starts_at_offset_ms' => $startsAtOffsetMs,
        'duration_ms' => $durationMs,
        'points' => $points,
    ]);
}

afterEach(function (): void {
    Date::setTestNow();
});

it("clock.show répond l'instant serveur sans session ni Set-Cookie", function (): void {
    // Instant exprimé hors UTC, microsecondes comprises : le fil le rend en
    // UTC, tronqué à la milliseconde.
    Date::setTestNow(CarbonImmutable::parse('2026-09-23 16:05:13.004987', 'Europe/Paris'));

    $this->withCredentials();

    // Première visite sans jeton : `SetLocale` négocie la langue et met le
    // cookie `locale` en file — il ne doit jamais partir.
    $negotiated = $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->getJson(route('clock.show'));

    $negotiated->assertOk()
        ->assertExactJson(['serverNow' => '2026-09-23T14:05:13.004Z']);

    expect($negotiated->headers->getCookies())->toBe([])
        ->and($negotiated->headers->has('Set-Cookie'))->toBeFalse()
        ->and((string) $negotiated->headers->get('Cache-Control'))->toContain('no-store');

    // Avec un jeton d'invité : lu pour le limiteur, jamais reposé.
    $token = PlayerToken::mint(Locale::English);

    $seated = $this->withCookie(PlayerTokenCookie::NAME, json_encode($token->toClaims(), JSON_THROW_ON_ERROR))
        ->getJson(route('clock.show'));

    $seated->assertOk()
        ->assertExactJson(['serverNow' => '2026-09-23T14:05:13.004Z']);

    expect($seated->headers->getCookies())->toBe([])
        ->and($seated->headers->has('Set-Cookie'))->toBeFalse();

    // Aucune session n'a été posée sur ces deux requêtes. `StartSession`
    // appelle `setLaravelSession()` sur la requête traitée ; l'état du magasin
    // ne prouverait rien, `Store::save()` le remet à « non démarré » en fin de
    // requête.
    expect($negotiated->baseRequest->hasSession())->toBeFalse()
        ->and($seated->baseRequest->hasSession())->toBeFalse();

    // La pile réelle : sans session, sans jeton CSRF ni file de cookies,
    // `EncryptCookies` conservé (le `player_token` reste lisible), limiteur
    // `game-read`.
    $stack = clockTestStack('clock.show');

    expect($stack)->not->toContain(StartSession::class)
        ->and($stack)->not->toContain(ShareErrorsFromSession::class)
        ->and($stack)->not->toContain(PreventRequestForgery::class)
        ->and($stack)->not->toContain(AddQueuedCookiesToResponse::class)
        ->and($stack)->toContain(EncryptCookies::class)
        ->and($stack)->toContain(ThrottleRequests::class.':game-read');
});

it("l'instant de réception est capturé une fois, avant tout middleware de jeu", function (): void {
    $received = CarbonImmutable::parse('2026-09-23 14:05:13.004321', 'UTC');
    Date::setTestNow($received);

    // Un limiteur de jeu qui consomme du temps (attente du cache) : il
    // s'exécute après la capture, et ne doit rien décaler.
    RateLimiter::for('clock-test-slow', function (Request $request): Limit {
        Date::setTestNow(Date::now()->addMilliseconds(400));

        return Limit::perMinute(EngineConstants::gameWritesPerMinute())->by('clock-test');
    });

    Route::middleware(['web', 'throttle:clock-test-slow'])
        ->post('/_test/clock/reception', function (Request $request): JsonResponse {
            $first = ReceptionInstant::of($request);

            // Attente d'un verrou de manche, puis lecture par une copie de
            // FormRequest, comme la soumission de 70.
            Date::setTestNow(Date::now()->addMilliseconds(900));
            $second = ReceptionInstant::of(FormRequest::createFrom($request));

            return response()->json([
                'first' => clockTestMicros($first),
                'second' => clockTestMicros($second),
                'now' => clockTestMicros(Date::now()->toImmutable()),
            ]);
        });

    $this->postJson('/_test/clock/reception')
        ->assertOk()
        ->assertExactJson([
            'first' => '2026-09-23 14:05:13.004321',
            'second' => '2026-09-23 14:05:13.004321',
            'now' => '2026-09-23 14:05:14.304321',
        ]);

    // Le premier middleware de route du groupe `web` est la capture, et le tri
    // de priorité du routeur ne l'en déloge sur aucune route du groupe —
    // `clock.show` et ses exclusions comprises : aucun middleware de jeu ne
    // passe avant elle.
    expect(app(HttpKernel::class)->getMiddlewareGroups()['web'][0] ?? null)
        ->toBe(CaptureReceptionInstant::class);

    $webRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn (Illuminate\Routing\Route $route): bool => in_array('web', $route->gatherMiddleware(), true));

    expect($webRoutes->map(static fn (Illuminate\Routing\Route $route): string => $route->getName() ?? $route->uri())->all())
        ->toContain('clock.show', 'home', '_test/clock/reception');

    foreach ($webRoutes as $route) {
        expect(app('router')->gatherRouteMiddleware($route)[0] ?? null)
            ->toBe(CaptureReceptionInstant::class, 'Route '.($route->getName() ?? $route->uri()));
    }

    // Capturé UNE fois : un second passage du middleware n'écrase rien.
    Date::setTestNow($received);
    $request = Request::create('/_test/clock/twice');
    $capture = new CaptureReceptionInstant;
    $next = static fn (Request $request): JsonResponse => response()->json();

    $capture->handle($request, $next);
    Date::setTestNow($received->addSeconds(3));
    $capture->handle($request, $next);

    expect(clockTestMicros(ReceptionInstant::of($request)))->toBe('2026-09-23 14:05:13.004321');

    // Hors de la pile `web`, le repli `Date::now()` est mémorisé à la première
    // lecture : deux lectures rendent le même instant.
    $bare = Request::create('/_test/clock/bare');
    $fallback = ReceptionInstant::of($bare);
    Date::setTestNow($received->addSeconds(7));

    expect(clockTestMicros(ReceptionInstant::of($bare)))->toBe(clockTestMicros($fallback))
        ->and(clockTestMicros($fallback))->toBe('2026-09-23 14:05:16.004321');
});

it("le décalage d'une manche se calcule en millisecondes entières sur les microsecondes", function (): void {
    // Origine telle qu'elle se relit en base (`timestamp(3)`), manche à trois
    // paliers de 10 s.
    $startedAt = CarbonImmutable::parse('2026-09-23 14:05:03.000', 'UTC');

    $round = (new Round)->forceFill(['started_at' => $startedAt, 'duration_ms' => 30000]);
    $round->setRelation('tiers', new EloquentCollection([
        clockTestTier(1, 0, 10000, 300),
        clockTestTier(2, 10000, 10000, 200),
        clockTestTier(3, 20000, 10000, 100),
    ]));

    // 9 999,6 ms après l'origine : 9 999 ms entières, palier 1. Un calcul sur
    // deux horodatages arrondis à la milliseconde rendrait 10 000 et le
    // palier 2 — une réponse et son rejeu divergeraient.
    $instant = CarbonImmutable::parse('2026-09-23 14:05:12.999600', 'UTC');
    $offset = RoundClock::offsetMs($round, $instant);

    expect($offset)->toBeInt()->toBe(9999)
        ->and(RoundClock::currentTierIndex($round, $instant))->toBe(1)
        ->and($instant->getTimestampMs() - $startedAt->getTimestampMs())->toBe(10000);

    // Indépendant de la zone de l'instant, et identique à chaque calcul.
    expect(RoundClock::offsetMs($round, $instant->setTimezone('Europe/Paris')))->toBe(9999)
        ->and(RoundClock::offsetMs($round, $instant))->toBe($offset);

    // Le même calcul sur une manche relue en base.
    $stored = Round::factory()->running()->create(['started_at' => $startedAt, 'duration_ms' => 30000]);

    expect(RoundClock::offsetMs($stored->fresh() ?? $stored, $instant))->toBe(9999);

    // Frontières des fenêtres `[offset, offset + durée)`, sans grâce.
    $at = static fn (string $time): CarbonImmutable => CarbonImmutable::parse('2026-09-23 '.$time, 'UTC');

    expect(RoundClock::currentTierIndex($round, $at('14:05:03.000000')))->toBe(1)
        ->and(RoundClock::offsetMs($round, $at('14:05:13.000000')))->toBe(10000)
        ->and(RoundClock::currentTierIndex($round, $at('14:05:13.000000')))->toBe(2)
        ->and(RoundClock::currentTierIndex($round, $at('14:05:32.999999')))->toBe(3)
        ->and(RoundClock::offsetMs($round, $at('14:05:33.000000')))->toBe(30000)
        ->and(RoundClock::currentTierIndex($round, $at('14:05:33.000000')))->toBeNull();

    // Avant T₁ : décalage négatif tronqué vers zéro, et aucun palier — même à
    // 0,4 ms de l'origine, que la troncature ramène à 0.
    expect(RoundClock::offsetMs($round, $at('14:05:02.998500')))->toBe(-1)
        ->and(RoundClock::offsetMs($round, $at('14:05:02.999600')))->toBe(0)
        ->and(RoundClock::currentTierIndex($round, $at('14:05:02.999600')))->toBeNull();

    // Manche sans origine : aucun palier, et aucun décalage.
    $pending = (new Round)->forceFill(['started_at' => null, 'duration_ms' => 30000]);
    $pending->setRelation('tiers', $round->tiers);

    expect(RoundClock::currentTierIndex($pending, $instant))->toBeNull()
        ->and(fn () => RoundClock::offsetMs($pending, $instant))->toThrow(LogicException::class);
});
