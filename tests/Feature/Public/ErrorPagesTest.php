<?php

use App\Enums\ErrorPageStatus;
use App\Enums\Locale;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Room;
use App\Models\User;
use App\Support\I18n\LocaleCookie;
use App\Support\I18n\TranslationDomains;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View as ViewInstance;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\I18n\FrontSource;

/*
|--------------------------------------------------------------------------
| Pages d'erreur traduites — spec 90 § 4.8, lot L90-5
|--------------------------------------------------------------------------
|
| Hors mode debug, une erreur HTTP de la liste close d'`ErrorPageStatus` est
| rendue par la page Inertia `error`, dans la langue du visiteur, avec toutes
| les props partagées — y compris quand elle naît AVANT `SetLocale` et
| `HandleInertiaRequests` : URL inconnue, liaison introuvable, refus de rôle,
| limiteur, jeton CSRF. Le back-office garde sa page `admin/error`, en
| français. Une requête qui attend du JSON garde le JSON du framework.
|
| Le mode debug est éteint test par test (`config(['app.debug' => false])`) :
| `.env.example`, donc la CI, l'allume. La déclaration des domaines de la page
| (`common` et `legal` exactement, `admin` seul au back-office) est prouvée
| par `TranslationDomainDeclarationTest`, pas ici (R-04).
|
*/

// Le chemin de production : la page d'erreur plutôt que la trace, et aucun
// manifeste Vite requis par la vue racine.
beforeEach(function (): void {
    config(['app.debug' => false]);
    $this->withoutVite();
});

/**
 * Les en-têtes qu'envoie le client Inertia lors d'une visite : sans
 * l'`Accept` explicite, `X-Requested-With` seul ferait passer la requête pour
 * une requête JSON (`expectsJson()` accepte un `Accept` absent).
 *
 * @return array<string, string>
 */
function errorPagesInertiaHeaders(): array
{
    return [
        Header::INERTIA => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];
}

/**
 * Vrai si la réponse est une page Inertia rendue par la vue racine.
 */
function errorPagesIsInertiaPage(TestResponse $response): bool
{
    $original = $response->baseResponse->original ?? null;

    return $original instanceof ViewInstance && array_key_exists('page', $original->getData());
}

/**
 * Réactive la vérification du jeton CSRF, que le framework saute sous
 * PHPUnit : c'est la seule façon d'obtenir une page expirée À SA VRAIE PLACE,
 * dans le groupe `web`, avant `SetLocale` et `HandleInertiaRequests`.
 */
function errorPagesEnforceCsrf(): void
{
    app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
}

/**
 * Remet la locale de l'application à l'anglais entre deux requêtes d'un même
 * test : `App::setLocale()` survit à la requête qui l'a posée. Le français
 * d'une page d'erreur ne peut alors venir que de la résolution faite par le
 * gestionnaire (cookie ou compte), jamais d'une requête précédente.
 */
function errorPagesResetLocale(): void
{
    App::setLocale(Locale::English->value);

    expect(App::getLocale())->not->toBe(Locale::French->value);
}

/**
 * Clés des props partagées par `HandleInertiaRequests` sur une page
 * ordinaire. Toute prop partagée ajoutée plus tard — `maintenance`, que pose
 * le drainage (C18-bis, L100-5) — rejoint d'elle-même cette liste, donc les
 * exigences de complétude ci-dessous.
 *
 * @return list<string>
 */
function errorPagesSharedKeys(): array
{
    return array_keys(app(HandleInertiaRequests::class)->share(Request::create('/')));
}

it('rend la page error traduite pour 403, 404, 429, 500 et 503 hors mode debug', function () {
    Route::middleware('web')->get('/__errors/abort/{status}', fn (string $status) => abort((int) $status));

    $statuses = [
        ErrorPageStatus::Forbidden,
        ErrorPageStatus::NotFound,
        ErrorPageStatus::TooManyRequests,
        ErrorPageStatus::ServerError,
        ErrorPageStatus::ServiceUnavailable,
    ];

    foreach ($statuses as $status) {
        foreach (Locale::cases() as $locale) {
            // Singleton : une requête neuve repart d'une sélection vide.
            app()->forgetInstance(TranslationDomains::class);

            $response = $this->withUnencryptedCookie(LocaleCookie::NAME, $locale->value)
                ->get("/__errors/abort/{$status->value}")
                ->assertStatus($status->value)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('error')
                    ->where('status', $status->value)
                    ->where('locale', $locale->value));

            $translations = $response->inertiaProps('translations');

            // Le texte de CE statut, dans CETTE langue, et la sortie.
            foreach ([$status->titleKey(), $status->descriptionKey(), 'common.error.back_home'] as $key) {
                expect($translations[$key] ?? null)
                    ->toBeString()
                    ->toBe(__($key, [], $locale->value), "{$key} en {$locale->value}");
            }

            expect((string) $response->getContent())->toContain('<html lang="'.$locale->value.'"');
        }
    }

    // La page appelle les clés des six statuts rendus, ni plus ni moins, et le
    // back-office les siennes : une ligne ajoutée à l'enum sans son miroir
    // client, ou l'inverse, fait échouer ce test.
    $expected = [];
    $expectedAdmin = [];

    foreach (ErrorPageStatus::cases() as $case) {
        array_push($expected, $case->titleKey(), $case->descriptionKey());
        array_push($expectedAdmin, "admin.error.http.{$case->value}.title", "admin.error.http.{$case->value}.description");
    }

    $pageKeys = FrontSource::literalKeys((string) file_get_contents(resource_path('js/pages/error.tsx')));
    $adminKeys = FrontSource::literalKeys((string) file_get_contents(resource_path('js/pages/admin/error.tsx')));

    expect(array_values(array_diff($pageKeys, ['common.error.back_home'])))->toEqualCanonicalizing($expected)
        ->and(array_values(array_filter($adminKeys, static fn (string $key): bool => str_starts_with($key, 'admin.error.http.'))))
        ->toEqualCanonicalizing($expectedAdmin);

    // En mode debug, la page du framework reste : la trace sert au développeur.
    config(['app.debug' => true]);
    app()->forgetInstance(TranslationDomains::class);

    $debug = $this->get('/__errors/abort/500')->assertStatus(500);

    expect(errorPagesIsInertiaPage($debug))->toBeFalse();
});

it('rend la page error traduite et complète pour une URL inconnue, une liaison de route introuvable, un refus de rôle et un 429 levés avant le middleware Inertia', function () {
    // Le code de salon inconnu, première erreur d'un joueur (spec 50 § 7.2) :
    // la liaison lève dans `SubstituteBindings`, avant `SetLocale`.
    Route::middleware(['web', 'translations:game,room,legal'])
        ->get('/__errors/rooms/{room}', fn (Room $room) => 'unreachable');

    // Le limiteur passe avant la substitution des liaisons, donc avant
    // `SetLocale` et `HandleInertiaRequests` (liste de priorité du noyau).
    Route::middleware(['web', 'throttle:1,1'])->get('/__errors/throttled', fn () => 'ok');

    $shared = errorPagesSharedKeys();

    expect($shared)->toContain('locale', 'locales', 'translations', 'name');

    // Un joueur dont le compte ET l'appareil sont en français : `users.locale`
    // prime sur le cookie dès que la session est lue (spec 05 § Négociation).
    $player = User::factory()->player()->locale(Locale::French)->create();

    $cases = [
        'URL inconnue' => [ErrorPageStatus::NotFound, fn () => $this->get('/__errors/definitely-unknown-url')],
        'liaison de route introuvable' => [ErrorPageStatus::NotFound, fn () => $this->get('/__errors/rooms/ZZZZZZ')],
        'limiteur' => [ErrorPageStatus::TooManyRequests, function () {
            // Cette première requête traverse `SetLocale` et y pose le
            // français : remis à l'anglais avant celle qui lève.
            $this->get('/__errors/throttled')->assertOk();
            errorPagesResetLocale();

            return $this->get('/__errors/throttled');
        }],
        'refus de rôle' => [ErrorPageStatus::Forbidden, fn () => $this->actingAs($player)->get(route('admin.dashboard'))],
    ];

    $this->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value);

    $responses = [];

    foreach ($cases as $label => [$status, $visit]) {
        app()->forgetInstance(TranslationDomains::class);

        // La locale posée par `App::setLocale()` survit d'une requête à
        // l'autre dans l'instance du test : sans cette remise à l'anglais, un
        // cas hériterait du français du précédent et ne prouverait rien de la
        // résolution faite par le gestionnaire (étape 1).
        errorPagesResetLocale();

        $response = $responses[$label] = $visit()->assertStatus($status->value);

        expect(errorPagesIsInertiaPage($response))->toBeTrue("{$label} : la page error n'est pas rendue");

        $response->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', $status->value)
            ->where('locale', Locale::French->value)
            ->where('name', config('app.name')));

        $props = $response->inertiaProps();

        // Complète : chaque prop partagée d'une page ordinaire est là.
        expect(array_values(array_diff($shared, array_keys($props))))
            ->toBe([], "{$label} : props partagées absentes");

        expect($props['locales'])->toBeArray()->toHaveCount(count(Locale::cases()))
            ->and($props['translations'][$status->titleKey()] ?? null)
            ->toBe(__($status->titleKey(), [], Locale::French->value), "{$label} : titre hors de la locale du cookie")
            ->and($props['translations'][$status->descriptionKey()] ?? null)
            ->toBe(__($status->descriptionKey(), [], Locale::French->value));

        expect((string) $response->getContent())->toContain('<html lang="fr"');
    }

    // Le 429 garde l'en-tête que le limiteur lui a posé.
    expect($responses['limiteur']->headers->has('Retry-After'))->toBeTrue();

    // En visite Inertia aussi, avant tout middleware : la charge JSON de la
    // page, marquée comme telle, et variant sur l'en-tête Inertia.
    app()->forgetInstance(TranslationDomains::class);
    errorPagesResetLocale();

    $visit = $this->withHeaders(errorPagesInertiaHeaders())
        ->get('/__errors/another-unknown-url')
        ->assertNotFound()
        ->assertHeader(Header::INERTIA, 'true');

    expect($visit->json('component'))->toBe('error')
        ->and($visit->json('props.status'))->toBe(404)
        ->and($visit->json('props.locale'))->toBe(Locale::French->value)
        ->and((string) $visit->headers->get('Vary'))->toContain(Header::INERTIA);
});

it("rend l'erreur du back-office en français quel que soit le cookie locale", function () {
    // Levées DERRIÈRE `admin.locale` : le domaine `admin` est déjà sélectionné.
    Route::middleware(['web', 'admin.locale'])->get('/__errors/admin/forbidden', fn () => abort(403));
    Route::middleware(['web', 'admin.locale'])->get('/__errors/admin/failure', fn () => abort(500));

    // Tout, chez ce curateur, dit l'anglais : le compte comme l'appareil.
    $curator = User::factory()->curator()->locale(Locale::English)->create();

    $this->actingAs($curator)->withUnencryptedCookie(LocaleCookie::NAME, Locale::English->value);

    foreach (['/__errors/admin/forbidden' => 403, '/__errors/admin/failure' => 500] as $uri => $code) {
        app()->forgetInstance(TranslationDomains::class);

        $response = $this->get($uri)
            ->assertStatus($code)
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/error')
                ->where('status', $code)
                ->where('locale', Locale::French->value));

        $translations = $response->inertiaProps('translations');

        // Le dictionnaire `admin`, en français : jamais une clé brute.
        expect($translations["admin.error.http.{$code}.title"] ?? null)
            ->toBe(__("admin.error.http.{$code}.title", [], Locale::French->value))
            ->not->toBe("admin.error.http.{$code}.title")
            ->and(array_key_exists('common.error.server_error.title', $translations))->toBeFalse();

        expect((string) $response->getContent())->toContain('<html lang="fr"');
    }
});

it('rend la page error en sombre, même levée dans une route de jeu, quel que soit le cookie d\'apparence', function () {
    // Tout le site est sombre (D56 du 02/10) : la page d'erreur l'est aussi,
    // levée depuis une route de jeu comme ailleurs, et un cookie `appearance`
    // hérité d'avant la décision n'y change rien.
    $game = ['web', 'translations:game,room,legal'];

    Route::middleware($game)->get('/__errors/game/missing', fn () => abort(404));
    Route::middleware($game)->get('/__errors/game/failure', fn () => abort(500));

    foreach (['/__errors/game/missing' => 404, '/__errors/game/failure' => 500] as $uri => $code) {
        foreach (['light', 'system', 'dark'] as $appearance) {
            app()->forgetInstance(TranslationDomains::class);

            $response = $this->withUnencryptedCookie('appearance', $appearance)
                ->get($uri)
                ->assertStatus($code)
                ->assertInertia(fn (Assert $page) => $page->component('error'));

            preg_match('/<html\b[^>]*>/i', (string) $response->getContent(), $match);
            $tag = $match[0] ?? '';

            expect($tag)->not->toBe('')
                ->not->toContain('data-appearance-forced')
                ->and(preg_match('/\sclass="[^"]*\bdark\b/', $tag))->toBe(1, "{$uri} ({$appearance}) : page error hors du sombre")
                ->and((string) $response->getContent())->not->toContain('const appearance');
        }
    }
});

it('renvoie à la page précédente avec un message traduit sur une page expirée en visite Inertia', function () {
    errorPagesEnforceCsrf();

    Route::middleware('web')->post('/__errors/expired', fn () => 'unreachable');

    $previous = route('legal.terms');
    $expected = __(ErrorPageStatus::PageExpired->descriptionKey(), [], Locale::French->value);

    $this->withUnencryptedCookie(LocaleCookie::NAME, Locale::French->value);

    // Visite Inertia sans jeton : retour à la page précédente, en 303 pour que
    // le navigateur la relise en GET quelle que soit la méthode d'origine.
    $this->withHeaders([...errorPagesInertiaHeaders(), 'Referer' => $previous])
        ->post('/__errors/expired')
        ->assertStatus(Response::HTTP_SEE_OTHER)
        ->assertRedirect($previous)
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', $expected);

    expect($expected)->not->toBe(ErrorPageStatus::PageExpired->descriptionKey());

    // La page d'arrivée porte le message, résolu côté serveur (destinataire
    // unique, spec 05 § Erreurs).
    $this->flushHeaders();
    app()->forgetInstance(TranslationDomains::class);

    $landing = $this->get($previous)->assertOk();

    expect($landing->inertiaPage()['flash']['toast']['message'] ?? null)->toBe($expected);

    // Sur un chargement complet, la même expiration rend la page error, 419.
    app()->forgetInstance(TranslationDomains::class);

    $this->post('/__errors/expired')
        ->assertStatus(419)
        ->assertInertia(fn (Assert $page) => $page
            ->component('error')
            ->where('status', 419)
            ->where('locale', Locale::French->value));
});

it("répond en JSON aux requêtes qui l'attendent, jamais par la page error", function () {
    errorPagesEnforceCsrf();

    Route::middleware('web')->get('/__errors/json/{status}', fn (string $status) => abort((int) $status));
    Route::middleware('web')->post('/__errors/json-expired', fn () => 'unreachable');

    $requests = [
        'URL inconnue' => [404, fn () => $this->getJson('/__errors/json-unknown-url')],
        'route api/*, sans Accept JSON' => [404, fn () => $this->get('/api/__errors/unknown')],
        'page expirée' => [419, fn () => $this->postJson('/__errors/json-expired')],
    ];

    foreach ([403, 404, 429, 500, 503] as $code) {
        $requests["abort {$code}"] = [$code, fn () => $this->getJson("/__errors/json/{$code}")];
    }

    foreach ($requests as $label => [$code, $send]) {
        app()->forgetInstance(TranslationDomains::class);

        /** @var TestResponse $response */
        $response = $send();

        $response->assertStatus($code);

        expect(str_contains((string) $response->headers->get('Content-Type'), 'application/json'))
            ->toBeTrue("{$label} : réponse hors JSON")
            ->and($response->json('message'))->toBeString()
            ->and($response->json('component'))->toBeNull()
            ->and($response->headers->has(Header::INERTIA))->toBeFalse()
            ->and(errorPagesIsInertiaPage($response))->toBeFalse();
    }
});
