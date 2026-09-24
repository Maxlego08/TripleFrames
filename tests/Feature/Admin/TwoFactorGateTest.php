<?php

use App\Http\Middleware\EnsurePrivilegedTwoFactor;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceAdminLocale;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| La porte `/admin` et sa seconde authentification — spec 20 § 2.3-2.4, L20-2
|--------------------------------------------------------------------------
|
| Mécanisme de `10` A16 : un rôle privilégié implique une double
| authentification CONFIRMÉE. Valeur retenue : `curator` ET `admin`. Trois
| propriétés, et l'ordre compte autant que la règle :
|
| 1. un compte privilégié sans second facteur confirmé est RENVOYÉ vers
|    l'écran d'enrôlement — jamais un 403 muet, et jamais un 404 qui
|    trahirait l'existence d'une ligne (la garde précède `SubstituteBindings`) ;
| 2. un joueur reçoit 403 AVANT toute redirection d'enrôlement, qui
|    confirmerait l'existence de la porte (la garde suit `role`) ;
| 3. une écriture interceptée n'est jamais exécutée.
|
| Les fabriques `curator()` et `admin()` posent un second facteur confirmé par
| défaut, comme en production ; `withoutTwoFactor()`, enchaîné après le rôle,
| ferme la porte.
|
*/

beforeEach(function (): void {
    // Ces tests mesurent la porte, pas la présence d'un fichier dans le
    // manifeste Vite.
    $this->withoutVite();

    // Sans clé, les écritures d'import refusent avant toute autre
    // considération : le test de l'écriture interceptée mesurerait alors le
    // mauvais refus. Aucun appel TMDB n'a lieu dans une requête web.
    Config::set('services.tmdb.api_key', 'clef-de-test');
});

/**
 * Les écrans de lecture du back-office qu'un compte privilégié sans second
 * facteur ne doit pas atteindre, dont la fiche d'un film réel et celle d'un
 * film absent : les deux doivent répondre la MÊME redirection.
 *
 * @return array<string, string>
 */
function twoFactorGateReadUrls(Movie $movie): array
{
    return [
        'tableau de bord' => route('admin.dashboard'),
        'catalogue' => route('admin.catalog.index'),
        'fiche d’un film réel' => route('admin.catalog.show', $movie),
        'fiche d’un film absent' => route('admin.catalog.show', ['movie' => $movie->getKey() + 10_000]),
        'import' => route('admin.import.index'),
    ];
}

/**
 * Les middlewares réellement exécutés par une route, exclusions comprises :
 * `Route::gatherMiddleware()` ignore `withoutMiddleware`, le routeur non.
 *
 * @return list<string>
 */
function twoFactorGateResolvedMiddleware(string $name): array
{
    $route = Route::getRoutes()->getByName($name);

    expect($route)->toBeInstanceOf(RoutingRoute::class);

    /** @var RoutingRoute $route */
    return array_values(array_filter(
        app('router')->gatherRouteMiddleware($route),
        'is_string',
    ));
}

test('un curateur sans double authentification confirmée est renvoyé vers l\'écran d\'enrôlement', function (): void {
    $movie = Movie::factory()->create();

    // Aucun second facteur, puis un secret posé mais jamais confirmé : c'est
    // la confirmation, et elle seule, qui ouvre la porte.
    $accounts = [
        'sans second facteur' => User::factory()->curator()->withoutTwoFactor()->create(),
        'secret non confirmé' => User::factory()->curator()->withoutTwoFactor()
            ->state(['two_factor_secret' => encrypt('secret')])
            ->create(),
    ];

    foreach ($accounts as $label => $curator) {
        expect($curator->two_factor_confirmed_at)->toBeNull($label);

        foreach (twoFactorGateReadUrls($movie) as $screen => $url) {
            $response = $this->actingAs($curator)->get($url);

            expect($response->getStatusCode())->toBe(Response::HTTP_SEE_OTHER, "{$label}, {$screen}");
            $response->assertRedirect(route('admin.two_factor.required'));
        }
    }

    // Le rang de priorité : la garde précède la substitution de liaison, d'où
    // la même redirection sur un film réel et sur un film absent ci-dessus.
    $priority = app(Kernel::class)->getMiddlewarePriority();
    $gate = array_search(EnsurePrivilegedTwoFactor::class, $priority, true);
    $bindings = array_search(SubstituteBindings::class, $priority, true);

    expect($gate)->toBeInt()
        ->and($bindings)->toBeInt()
        ->and($gate)->toBeLessThan($bindings);
});

test('un administrateur sans double authentification confirmée est renvoyé vers l\'écran d\'enrôlement', function (): void {
    $movie = Movie::factory()->create();
    $admin = User::factory()->admin()->withoutTwoFactor()->create();

    foreach (twoFactorGateReadUrls($movie) as $screen => $url) {
        $response = $this->actingAs($admin)->get($url);

        expect($response->getStatusCode())->toBe(Response::HTTP_SEE_OTHER, $screen);
        $response->assertRedirect(route('admin.two_factor.required'));
    }
});

test('un compte privilégié à double authentification confirmée passe la porte', function (): void {
    $movie = Movie::factory()->create();

    // Le défaut des fabriques : un rôle privilégié naît avec un second facteur
    // confirmé, comme le compte du porteur en production.
    $accounts = [
        'curateur' => User::factory()->curator()->create(),
        'administrateur' => User::factory()->admin()->create(),
    ];

    foreach ($accounts as $label => $account) {
        expect($account->two_factor_confirmed_at)->not->toBeNull($label);

        foreach (['admin.dashboard', 'admin.catalog.index', 'admin.import.index'] as $name) {
            $this->actingAs($account)->get(route($name))->assertOk();
        }

        $this->actingAs($account)->get(route('admin.catalog.show', $movie))->assertOk();

        // Passé la porte, la liaison reprend son cours normal.
        $this->actingAs($account)
            ->get(route('admin.catalog.show', ['movie' => $movie->getKey() + 10_000]))
            ->assertNotFound();
    }
});

test('un joueur reçoit 403 avant toute redirection d\'enrôlement', function (): void {
    $movie = Movie::factory()->create();

    // Un joueur n'a jamais de second facteur exigé : s'il était renvoyé vers
    // l'enrôlement, il apprendrait qu'une porte existe derrière `/admin`.
    $player = User::factory()->player()->create();

    expect($player->two_factor_confirmed_at)->toBeNull();

    foreach ([...twoFactorGateReadUrls($movie), 'enrôlement' => route('admin.two_factor.required')] as $screen => $url) {
        expect($this->actingAs($player)->get($url)->getStatusCode())->toBe(Response::HTTP_FORBIDDEN, $screen);
    }

    $this->actingAs($player)
        ->post(route('admin.import.discover'), [])
        ->assertForbidden();

    // L'ordre qui le garantit : `role` avant `admin.2fa` dans le groupe, et
    // devant elle dans la liste de priorité.
    $middleware = Route::getRoutes()->getByName('admin.dashboard')?->gatherMiddleware() ?? [];
    $role = array_search('role:curator', $middleware, true);
    $gate = array_search('admin.2fa', $middleware, true);

    expect($role)->toBeInt()
        ->and($gate)->toBeInt()
        ->and($role)->toBeLessThan($gate);

    $priority = app(Kernel::class)->getMiddlewarePriority();

    expect(array_search(EnsureUserHasRole::class, $priority, true))
        ->toBeLessThan(array_search(EnsurePrivilegedTwoFactor::class, $priority, true));
});

test('l\'écran d\'enrôlement reste accessible sans double authentification et mène à la sécurité du compte', function (): void {
    $curator = User::factory()->curator()->withoutTwoFactor()->create();

    // La page s'affiche, en français et dans le domaine `admin`, et dit que la
    // porte est fermée.
    $response = $this->actingAs($curator)
        ->get(route('admin.two_factor.required'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/two-factor-required')
            ->where('two_factor_confirmed', false)
            ->where('locale', 'fr'));

    expect($response->inertiaProps('translations'))->toBeArray()
        ->toHaveKeys(['admin.two_factor.heading', 'admin.two_factor.action.open_security']);

    // La route est retirée de la garde et d'elle seule : la porte (`role`) et
    // la langue du back-office s'y appliquent toujours.
    $resolved = twoFactorGateResolvedMiddleware('admin.two_factor.required');

    expect($resolved)->not->toContain(EnsurePrivilegedTwoFactor::class)
        ->and($resolved)->toContain(EnsureUserHasRole::class.':curator', ForceAdminLocale::class)
        ->and(twoFactorGateResolvedMiddleware('admin.dashboard'))->toContain(EnsurePrivilegedTwoFactor::class);

    // La page mène à la sécurité du compte par un lien Wayfinder.
    $page = (string) file_get_contents(resource_path('js/pages/admin/two-factor-required.tsx'));

    expect($page)->toContain("from '@/routes/security'")
        ->and($page)->toContain('securityEdit()');

    // Et le chemin d'enrôlement n'est pas fermé par la garde qu'il sert à
    // ouvrir : la page de sécurité et les deux gestes Fortify vivent hors de
    // `/admin`.
    $this->actingAs($curator)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();

    foreach (['security.edit', 'two-factor.enable', 'two-factor.confirm'] as $name) {
        expect(twoFactorGateResolvedMiddleware($name))->not->toContain(EnsurePrivilegedTwoFactor::class);
    }

    // Une fois le second facteur confirmé, l'écran dit que la porte est ouverte.
    $curator->forceFill(['two_factor_confirmed_at' => now()])->save();

    $this->actingAs($curator)
        ->get(route('admin.two_factor.required'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('two_factor_confirmed', true));

    $this->actingAs($curator)->get(route('admin.dashboard'))->assertOk();
});

test('une écriture interceptée n\'est jamais exécutée', function (): void {
    Bus::fake();

    $curator = User::factory()->curator()->withoutTwoFactor()->create();
    $running = ImportRun::factory()->discover()->running()->create();
    $before = $running->fresh()?->toArray();

    $writes = [
        'balayage discover' => fn () => $this->actingAs($curator)->post(route('admin.import.discover'), [
            'min_votes' => 200,
            'languages' => ['fr'],
            'min_year' => 1980,
            'pages' => 1,
        ]),
        'collage' => fn () => $this->actingAs($curator)->post(route('admin.import.ids'), [
            'ids' => "550\n129",
        ]),
        'reprise' => fn () => $this->actingAs($curator)->post(route('admin.import.resume', $running)),
    ];

    foreach ($writes as $label => $write) {
        $response = $write();

        expect($response->getStatusCode())->toBe(Response::HTTP_SEE_OTHER, $label);
        $response->assertRedirect(route('admin.two_factor.required'))
            ->assertSessionHasNoErrors();
    }

    // Rien n'a été écrit, rien n'a été confié à la file.
    expect(ImportRun::query()->count())->toBe(1)
        ->and($running->fresh()?->toArray())->toBe($before);

    Bus::assertNothingDispatched();
});
