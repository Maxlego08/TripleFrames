<?php

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Http\Controllers\Admin\ImportSearchController;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Recherche TMDB et import unitaire — spec 20 § 3.4, L20-16
|--------------------------------------------------------------------------
|
| La seule route du back-office qui appelle TMDB DANS la requête : elle a son
| propre limiteur, marque chaque résultat contre le catalogue local, et fait
| d'une panne un état traduit et rejouable — jamais une page d'erreur. TMDB
| est simulé (`Http::fake()`), et `Sleep::fake()` neutralise le retrait
| exponentiel du client sur les 429.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Sleep::fake();
    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Config::set('catalog.import.requests_per_second', 1000);

    $this->curator = User::factory()->curator()->create();
});

afterEach(function (): void {
    Sleep::fake(false);
});

/**
 * Une réponse TMDB simulée nourrie par une fixture committée.
 */
function tmdbSearchJson(string $fixture, int $status = 200): mixed
{
    return Http::response(TmdbFixture::json($fixture), $status, ['Content-Type' => 'application/json']);
}

/**
 * Le texte français d'une clé du domaine `admin`, présente au dictionnaire.
 */
function tmdbSearchText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

test('la recherche marque les films déjà au catalogue et les films retirés', function (): void {
    Http::fake(['*themoviedb.org/3/search/movie*' => tmdbSearchJson('search-page')]);

    $published = Movie::factory()->published()->create(['tmdb_id' => 987654]);
    $withdrawn = Movie::factory()->withdrawn()->create(['tmdb_id' => 987670]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/import/index', false)
            // L'écran d'import entier, par le présentateur commun.
            ->has('runs.data')
            ->has('seed_list')
            ->has('defaults')
            ->where('search_results.query', 'Orchard')
            ->where('search_results.status', ImportSearchController::READY)
            ->where('search_results.error_key', null)
            // Le résultat `adult` n'est même pas proposé.
            ->has('search_results.items', 3)
            ->where('search_results.items.0.tmdb_id', 987654)
            ->where('search_results.items.0.title', 'The Glass Orchard')
            ->where('search_results.items.0.title_original', 'ガラスの果樹園')
            ->where('search_results.items.0.release_year', 1998)
            ->where('search_results.items.0.original_language', 'ja')
            ->where('search_results.items.0.vote_count', 2410)
            ->where('search_results.items.0.catalog.movie_id', $published->id)
            ->where('search_results.items.0.catalog.availability', 'published')
            ->where('search_results.items.1.tmdb_id', 987656)
            ->where('search_results.items.1.catalog', null)
            ->where('search_results.items.2.tmdb_id', 987670)
            ->where('search_results.items.2.release_year', null)
            ->where('search_results.items.2.catalog.movie_id', $withdrawn->id)
            ->where('search_results.items.2.catalog.availability', 'withdrawn'));

    // Une requête de recherche, sans contenu adulte, et rien d'autre.
    Http::assertSentCount(1);
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), '/search/movie')
        && $request['query'] === 'Orchard'
        && $request['include_adult'] === 'false'
        && (int) $request['page'] === 1);

    // Les deux états ont leur libellé dans le dictionnaire.
    expect(tmdbSearchText('admin.import.search.state.withdrawn'))->toContain('réimport bloqué')
        ->and(tmdbSearchText('admin.import.search.state.absent'))->not->toBe('');
});

test('importer un résultat ouvre un collage d\'un seul identifiant, marqué exception', function (): void {
    // 987654 satisfait TOUT le filtre de goût : 2 410 votes, japonais, 1998.
    Http::fake([
        '*themoviedb.org/3/search/movie*' => tmdbSearchJson('search-page'),
        '*themoviedb.org/3/movie/987654*' => tmdbSearchJson('movie-987654'),
    ]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertInertia(fn (Assert $page) => $page->where('search_results.items.0.catalog', null));

    // « Importer » poste l'identifiant seul sur la voie de collage ; la file
    // est `sync`, le collage s'exécute pendant la requête.
    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '987654'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.import.show', ['importRun' => ImportRun::query()->latest('id')->value('id')]));

    $run = ImportRun::query()->sole();

    expect($run->run_kind)->toBe(ImportRunKind::Paste)
        ->and($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->actor_id)->toBe($this->curator->id)
        ->and($run->total_seen)->toBe(1)
        ->and($run->total_imported)->toBe(1);

    $movie = Movie::query()->where('tmdb_id', 987654)->sole();

    // Marqué exception par la VOIE, sans le moindre motif : c'est le résidu
    // assumé du compteur « entrés par exception » (§ 3.4).
    expect($movie->import_source)->toBe(ImportSource::Paste)
        ->and($movie->import_run_id)->toBe($run->id)
        ->and($movie->is_import_exception)->toBeTrue()
        ->and($movie->exception_for_language)->toBeFalse()
        ->and($movie->exception_for_vote_count)->toBeFalse()
        ->and($movie->exception_for_release_year)->toBeFalse();

    // Relancée, la recherche le dit déjà au catalogue.
    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('search_results.items.0.catalog.movie_id', $movie->id)
            ->where('search_results.items.0.catalog.availability', 'draft'));
});

test('importer pendant un collage ouvert est refusé par un message traduit', function (): void {
    Bus::fake();

    // Un collage en file — celui de la liste d'amorçage, par exemple.
    $open = ImportRun::factory()->paste()->running()->create(['started_at' => null]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('seed_list.busy_run_id', $open->id));

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '987654'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', tmdbSearchText('admin.error.import_already_running'));

    Bus::assertNothingDispatched();
    expect(ImportRun::query()->count())->toBe(1);

    // Le collage fini, le même geste redevient utile.
    $open->forceFill(['status' => ImportRunStatus::Completed, 'finished_at' => now()])->save();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '987654'])
        ->assertSessionHasNoErrors();

    Bus::assertDispatched(RunCatalogImport::class, fn (RunCatalogImport $job): bool => $job->identifiers === [987654]);
});

test('la recherche a son propre limiteur, distinct de celui des imports', function (): void {
    Bus::fake();
    Http::fake(['*themoviedb.org/3/search/movie*' => tmdbSearchJson('search-page')]);

    // Déclaré sur la route, et le seul : ni `admin-import` ici, ni aucun
    // limiteur sur l'écran d'import que sonde l'aperçu.
    $throttles = static fn (string $name): array => array_values(array_filter(
        Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [],
        static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle'),
    ));

    expect($throttles('admin.import.search'))->toBe(['throttle:admin-tmdb-search'])
        ->and($throttles('admin.import.ids'))->toBe(['throttle:admin-import'])
        ->and($throttles('admin.import.index'))->toBe([]);

    // Sa valeur vient de la configuration, jamais d'un littéral.
    Config::set('catalog.curation.rate_limits.search', 2);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->curator)
            ->get(route('admin.import.search', ['q' => 'Orchard']))
            ->assertOk();
    }

    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertTooManyRequests();

    // Les recherches épuisées n'ont rien consommé du quota des imports…
    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '987654'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.import.show', ['importRun' => ImportRun::query()->latest('id')->value('id')]));

    // … et le limiteur est PAR COMPTE : un autre curateur cherche encore.
    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertOk();
});

test('un 429 de TMDB sur la recherche donne un message traduit et Réessayer', function (): void {
    Http::fake(['*themoviedb.org/3/search/movie*' => Http::sequence()
        // Le client retente trois fois un 429 avant d'abandonner.
        ->push(TmdbFixture::json('error-429'), 429, ['Content-Type' => 'application/json'])
        ->push(TmdbFixture::json('error-429'), 429, ['Content-Type' => 'application/json'])
        ->push(TmdbFixture::json('error-429'), 429, ['Content-Type' => 'application/json'])
        ->push(TmdbFixture::json('search-page'), 200, ['Content-Type' => 'application/json']),
    ]);

    // Jamais une page d'erreur : l'écran d'import, et un état.
    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/import/index', false)
            ->where('search_results.status', ImportSearchController::RATE_LIMITED)
            ->where('search_results.error_key', 'admin.tmdb.error.rate_limited_interactive')
            ->where('search_results.items', []));

    // Le message est une phrase française, et le bouton « Réessayer » a le
    // sien ; aucun des deux ne renvoie au balayage ni à « Reprendre ».
    expect(tmdbSearchText('admin.tmdb.error.rate_limited_interactive'))->toContain('réessayez')
        ->and(tmdbSearchText('admin.import.search.retry'))->toBe('Réessayer')
        ->and(tmdbSearchText('admin.tmdb.error.rate_limited_interactive'))->not->toContain('Reprendre');

    // « Réessayer » rappelle TMDB : le quota rechargé, les résultats viennent.
    $this->actingAs($this->curator)
        ->get(route('admin.import.search', ['q' => 'Orchard']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('search_results.status', ImportSearchController::READY)
            ->has('search_results.items', 3));
});
