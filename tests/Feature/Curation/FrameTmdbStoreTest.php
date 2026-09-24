<?php

use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Policies\FramePolicy;
use App\Policies\MoviePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ajouter une variante depuis TMDB — contrat C9, spec 20 § 5.3
|--------------------------------------------------------------------------
|
| La garde d'ajout nomme la CLASSE en premier argument :
| `can:create,App\Models\Frame,movie`. Le middleware `can` résout la policy
| d'après ce premier argument ; écrite `can:create,movie`, elle résoudrait
| `MoviePolicy::create()`, qui refuse toujours parce que la création d'un
| film est un acte d'import, et toute la voie TMDB répondrait 403 (V-17).
|
| La route réelle `admin.catalog.frames.tmdb.store` naît avec le lot L20-7 ;
| sa garde exacte est alors tenue par la matrice des capacités
| (`tests/Datasets/AdminRoutes.php`, `AuthorizationMatrixTest`). La
| résolution, elle, se prouve dès la policy, sur deux routes de test à la
| forme du groupe `/admin` : la bonne garde et la mauvaise.
|
*/

test('la garde d\'ajout passe par FramePolicy et jamais par MoviePolicy::create', function (): void {
    Route::middleware(['web', 'auth', 'role:curator', 'can:create,'.Frame::class.',movie'])
        ->post('_tests/catalog/{movie}/frames/tmdb', fn (Movie $movie): string => 'ajout')
        ->name('tests.frames.tmdb.store');

    Route::middleware(['web', 'auth', 'role:curator', 'can:create,movie'])
        ->post('_tests/catalog/{movie}/frames/wrong-guard', fn (Movie $movie): string => 'ajout')
        ->name('tests.frames.wrong_guard');

    // Noms posés après l'entrée des routes dans la collection : le noyau fait
    // ce geste une fois les fichiers de routes chargés, ici il nous revient.
    Route::getRoutes()->refreshNameLookups();

    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();

    // La policy résolue par le premier argument de la garde.
    expect(Gate::getPolicyFor(Frame::class))->toBeInstanceOf(FramePolicy::class)
        ->and(Gate::getPolicyFor($movie))->toBeInstanceOf(MoviePolicy::class)
        ->and(Gate::forUser($curator)->allows('create', [Frame::class, $movie]))->toBeTrue()
        ->and(Gate::forUser($curator)->allows('create', $movie))->toBeFalse()
        ->and(Gate::forUser($curator)->allows('create', Movie::class))->toBeFalse();

    // La bonne garde ouvre l'ajout au curateur ; la mauvaise le lui ferme.
    $this->actingAs($curator)
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertOk();

    $this->actingAs($curator)
        ->post(route('tests.frames.wrong_guard', $movie))
        ->assertForbidden();

    // Et c'est bien FramePolicy qui juge : son refus d'état passe par la même
    // garde — la banque d'un film suspendu ou retiré ne grossit pas.
    foreach ([Movie::factory()->suspended()->create(), Movie::factory()->withdrawn()->create()] as $closed) {
        $this->actingAs($curator)
            ->post(route('tests.frames.tmdb.store', $closed))
            ->assertForbidden();
    }

    // Un joueur ne la franchit jamais, un administrateur la franchit parce
    // qu'il est au-dessus du seuil.
    $this->actingAs(User::factory()->player()->create())
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertForbidden();

    $this->actingAs(User::factory()->admin()->create())
        ->post(route('tests.frames.tmdb.store', $movie))
        ->assertOk();
});
