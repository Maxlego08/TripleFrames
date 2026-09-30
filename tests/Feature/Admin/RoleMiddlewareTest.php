<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| `role:<seuil>` — le garde de la PORTE
|--------------------------------------------------------------------------
|
| Les routes de ce fichier sont déclarées ici et nulle part ailleurs : le
| middleware doit être éprouvé pour lui-même, sans dépendre des écrans
| d'administration ni de leurs contrôleurs. Elles reprennent exactement la
| forme du groupe de `routes/admin.php`.
|
| Trois propriétés à prouver, et la troisième est celle qu'on oublie :
|
| 1. un invité est REDIRIGÉ vers la connexion, jamais renvoyé en 403 — un 403
|    à un invité lui apprend qu'il existe quelque chose derrière ;
| 2. un compte sous le seuil reçoit 403 ;
| 3. un compte sous le seuil reçoit 403 **même sur un identifiant qui
|    n'existe pas**. Sans le rang de priorité, `SubstituteBindings` répondrait
|    404 avant que le seuil ne tombe, et le simple couple 403/404 énumérerait
|    la table `movie`.
|
*/

beforeEach(function (): void {
    // Sans `auth` : c'est le middleware de rôle lui-même qui doit distinguer
    // « non connecté » de « pas assez de droits ».
    Route::middleware(['web', 'role:curator'])
        ->get('_tests/porte-curation', fn (): string => 'ouvert')
        ->name('tests.porte-curation');

    Route::middleware(['web', 'role:admin'])
        ->get('_tests/porte-administration', fn (): string => 'ouvert')
        ->name('tests.porte-administration');

    // La forme exacte du groupe d'administration : seuil de rôle sur le
    // groupe, policy sur la ressource, liaison implicite entre les deux.
    Route::middleware(['web', 'auth', 'role:curator', 'can:view,movie'])
        ->get('_tests/fiche/{movie}', fn (Movie $movie): string => 'fiche')
        ->name('tests.fiche');

    Route::middleware(['web', 'role:curateur'])
        ->get('_tests/faute-de-frappe', fn (): string => 'ouvert')
        ->name('tests.faute-de-frappe');

    // Les noms sont posés APRÈS l'entrée de la route dans la collection :
    // `RouteCollection::addLookups()` n'a donc rien vu passer, et
    // `route('tests.…')` lèverait « Route not defined ». Le noyau fait ce même
    // geste une fois les fichiers de routes chargés ; ici, il nous revient.
    Route::getRoutes()->refreshNameLookups();
});

test('un invité est redirigé vers la connexion, et surtout pas renvoyé en 403', function (): void {
    $response = $this->get(route('tests.porte-curation'));

    $response->assertRedirect(route('login'));
    expect($response->getStatusCode())->toBe(302);
});

test('un joueur connecté reçoit 403', function (): void {
    $player = User::factory()->player()->create();

    $this->actingAs($player)
        ->get(route('tests.porte-curation'))
        ->assertForbidden();
});

test('un curateur passe le seuil de curation', function (): void {
    $curator = User::factory()->curator()->create();

    $this->actingAs($curator)
        ->get(route('tests.porte-curation'))
        ->assertOk();
});

test('un administrateur passe le seuil de curation parce qu’il est au-dessus, jamais par une exception écrite pour lui', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('tests.porte-curation'))
        ->assertOk();
});

test('le seuil d’administration : player refusé, curator refusé, admin accepté', function (): void {
    $player = User::factory()->player()->create();
    $curator = User::factory()->curator()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($player)->get(route('tests.porte-administration'))->assertForbidden();
    $this->actingAs($curator)->get(route('tests.porte-administration'))->assertForbidden();
    $this->actingAs($admin)->get(route('tests.porte-administration'))->assertOk();
});

test('un compte sous le seuil ne peut pas distinguer un film qui existe d’un film qui n’existe pas', function (): void {
    $movie = Movie::factory()->create();
    $player = User::factory()->player()->create();

    $this->actingAs($player)
        ->get(route('tests.fiche', ['movie' => $movie->getKey()]))
        ->assertForbidden();

    $this->actingAs($player)
        ->get(route('tests.fiche', ['movie' => $movie->getKey() + 10_000]))
        ->assertForbidden();
});

test('au-dessus du seuil, la liaison reprend son cours normal : 200 sur un film réel, 404 sur un film absent', function (): void {
    $movie = Movie::factory()->create();
    $curator = User::factory()->curator()->create();

    $this->actingAs($curator)
        ->get(route('tests.fiche', ['movie' => $movie->getKey()]))
        ->assertOk();

    $this->actingAs($curator)
        ->get(route('tests.fiche', ['movie' => $movie->getKey() + 10_000]))
        ->assertNotFound();
});

test('un invité sur une route à liaison est redirigé, sans que la ressource ne soit cherchée', function (): void {
    $movie = Movie::factory()->create();

    $this->get(route('tests.fiche', ['movie' => $movie->getKey()]))
        ->assertRedirect(route('login'));

    $this->get(route('tests.fiche', ['movie' => $movie->getKey() + 10_000]))
        ->assertRedirect(route('login'));
});

test('un paramètre de rôle inconnu lève, au lieu de fermer ou d’ouvrir en silence', function (): void {
    $this->withoutExceptionHandling();

    $admin = User::factory()->admin()->create();

    expect(fn () => $this->actingAs($admin)->get(route('tests.faute-de-frappe')))
        ->toThrow(InvalidArgumentException::class);
});

test('le rang de priorité place le seuil de rôle après l’authentification et avant la substitution de liaison', function (): void {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    $authenticate = array_search(AuthenticatesRequests::class, $priority, true);
    $role = array_search(EnsureUserHasRole::class, $priority, true);
    $bindings = array_search(SubstituteBindings::class, $priority, true);

    expect($authenticate)->toBeInt()
        ->and($role)->toBeInt()
        ->and($bindings)->toBeInt()
        ->and($authenticate)->toBeLessThan($role)
        ->and($role)->toBeLessThan($bindings);
});
