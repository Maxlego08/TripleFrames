<?php

use App\Enums\ImportRunStatus;
use App\Enums\Locale;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\PastePreview;
use App\Support\Catalog\ImportDecision;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| L'aperçu à blanc d'un collage — spec 20 § 3.3, L20-16
|--------------------------------------------------------------------------
|
| La file est `sync` en test : le job `PreviewCatalogPaste` part pendant la
| requête qui l'ouvre, appelle `catalog:import-ids --preview=<jeton>`, et le
| résultat est en cache quand l'écran d'import se recharge. TMDB est simulé
| de bout en bout (`Http::fake()`, `preventStrayRequests()`), et
| `Sleep::fake()` neutralise l'étranglement comme le retrait exponentiel.
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
function pastePreviewJson(string $fixture, int $status = 200): mixed
{
    return Http::response(TmdbFixture::json($fixture), $status, ['Content-Type' => 'application/json']);
}

/**
 * Le texte français d'une clé du domaine `admin`, présente au dictionnaire.
 *
 * @param  array<string, string|int>  $replace
 */
function pastePreviewText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

/**
 * Un rechargement partiel de l'écran d'import, écrit à la main pour lire la
 * réponse brute — ce que fait `usePoll` à chaque tick.
 */
function pastePreviewPoll(User $user, string $version): TestResponse
{
    return test()
        ->actingAs($user)
        ->get(route('admin.import.index'), [
            Header::INERTIA => 'true',
            Header::VERSION => $version,
            Header::PARTIAL_COMPONENT => 'admin/import/index',
            Header::PARTIAL_ONLY => 'paste_preview',
        ]);
}

test('l\'aperçu d\'un collage n\'écrit aucune ligne import_run', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/987654*' => pastePreviewJson('movie-987654'),
        '*themoviedb.org/3/movie/987656*' => pastePreviewJson('movie-987656'),
    ]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => "987654\nhttps://www.themoviedb.org/movie/987656-northbound-nine"])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.import.index'));

    // Ni balayage, ni film, ni titre : la simulation n'a RIEN écrit, et
    // l'historique des balayages ne contient que des imports réels.
    expect(ImportRun::query()->count())->toBe(0)
        ->and(Movie::query()->count())->toBe(0);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/import/index', false)
            ->where('paste_preview.status', PastePreview::COMPLETED)
            ->where('paste_preview.identifiers', [987654, 987656])
            ->where('paste_preview.total', 2)
            ->where('paste_preview.processed', 2)
            ->where('paste_preview.rows.0.decision', ImportDecision::Simulated->value)
            ->where('paste_preview.rows.1.decision', ImportDecision::Simulated->value)
            // Un collage entre TOUJOURS par exception, même dans le filtre.
            ->where('paste_preview.rows.0.is_import_exception', true));

    // Et la commande n'a pas davantage ouvert de balayage en passant.
    expect(ImportRun::query()->count())->toBe(0);
});

test('l\'aperçu rend le sort de chaque identifiant et nomme les refus de contenu', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/987654*' => pastePreviewJson('movie-987654'),
        '*themoviedb.org/3/movie/987661*' => pastePreviewJson('movie-987661-fr-18'),
        '*themoviedb.org/3/movie/424242*' => pastePreviewJson('error-404', 404),
    ]);

    $known = Movie::factory()->published()->create(['tmdb_id' => 987656, 'title_original' => 'Northbound Nine']);
    $withdrawn = Movie::factory()->withdrawn('Retrait prononcé sur mise en demeure.')->create(['tmdb_id' => 987670]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => "987654\n987661\n987656\n987670\n424242"])
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($this->curator)->get(route('admin.import.index'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->where('paste_preview.status', PastePreview::COMPLETED)
        ->has('paste_preview.rows', 5)
        // Dans l'ordre du collage, un sort par identifiant.
        ->where('paste_preview.rows.0.tmdb_id', 987654)
        ->where('paste_preview.rows.0.decision', ImportDecision::Simulated->value)
        ->where('paste_preview.rows.0.release_year', 1998)
        ->where('paste_preview.rows.1.tmdb_id', 987661)
        ->where('paste_preview.rows.1.decision', ImportDecision::RefusedContent->value)
        ->where('paste_preview.rows.1.title_original', 'The Salted Mile')
        ->where('paste_preview.rows.1.reason_key', 'admin.catalog.import.refused.certification')
        ->where('paste_preview.rows.1.reason_replacements.country', 'FR')
        ->where('paste_preview.rows.2.decision', ImportDecision::Duplicate->value)
        ->where('paste_preview.rows.2.movie_id', $known->id)
        ->where('paste_preview.rows.2.title_original', 'Northbound Nine')
        ->where('paste_preview.rows.3.decision', ImportDecision::RefusedWithdrawn->value)
        ->where('paste_preview.rows.3.movie_id', $withdrawn->id)
        ->where('paste_preview.rows.3.availability', 'withdrawn')
        ->where('paste_preview.rows.4.decision', ImportDecision::NotFound->value)
        ->where('paste_preview.rows.4.title_original', null));

    // Le refus de contenu est NOMMÉ : la clé se rend en français, pays et
    // classification substitués — jamais une clé brute, jamais un JSON.
    $refused = $response->viewData('page')['props']['paste_preview']['rows'][1];

    expect(pastePreviewText($refused['reason_key'], $refused['reason_replacements']))
        ->toContain('-18')
        ->toContain('FR')
        ->and(pastePreviewText('admin.import.preview.decision.refused_content'))->toContain('contenu');

    // Les films connus n'ont coûté aucun appel de détail ; aucun n'a été
    // écrit, et le film retiré reste retiré.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/movie/987656'));

    expect(Movie::query()->count())->toBe(2)
        ->and(ImportRun::query()->count())->toBe(0);
});

test('l\'aperçu expire et n\'est lisible que par son auteur', function (): void {
    Bus::fake();

    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => '987654'])
        ->assertRedirect(route('admin.import.index'));

    $response = $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('paste_preview.status', PastePreview::PENDING)
            ->where('paste_preview.identifiers', [987654]));

    $token = $response->viewData('page')['props']['paste_preview']['token'];

    expect($token)->toBeString()
        ->and(PastePreview::isToken((string) $token))->toBeTrue();

    // Un autre curateur ne le lit ni par l'écran, ni par son jeton : la clé
    // du cache porte l'identifiant de l'auteur.
    $other = User::factory()->admin()->create();

    $this->actingAs($other)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('paste_preview', null));

    expect(PastePreview::find($other->id, (string) $token))->toBeNull()
        ->and(PastePreview::find($this->curator->id, (string) $token))->not->toBeNull();

    // Passé sa durée de vie, il n'existe plus, pour personne.
    $this->travel(Config::integer('catalog.import.preview_ttl_minutes') + 1)->minutes();

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('paste_preview', null));

    expect(PastePreview::find($this->curator->id, (string) $token))->toBeNull();
});

test('le sondage de l\'aperçu ne reçoit jamais de 429', function (): void {
    Bus::fake();

    // `admin.import.index` ne porte AUCUN limiteur : ni `admin-import`, écrit
    // pour les écritures, ni celui de la recherche.
    $middleware = Route::getRoutes()->getByName('admin.import.index')?->gatherMiddleware() ?? [];

    expect(array_filter($middleware, static fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'throttle')))
        ->toBe([]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => '987654'])
        ->assertRedirect(route('admin.import.index'));

    $version = (string) $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->viewData('page')['version'];

    // Bien au-delà de chacun des deux limiteurs de l'import, dans la même
    // minute : chaque tick répond, et ne rend que l'aperçu.
    $ticks = max(12, Config::integer('catalog.curation.rate_limits.search')) + 1;

    for ($tick = 0; $tick < $ticks; $tick++) {
        pastePreviewPoll($this->curator, $version)
            ->assertOk()
            ->assertJsonPath('props.paste_preview.status', PastePreview::PENDING)
            ->assertJsonMissingPath('props.runs');
    }
});

test('l\'import qui suit un aperçu dans le même processus importe vraiment', function (): void {
    Http::fake([
        '*themoviedb.org/3/movie/987654*' => pastePreviewJson('movie-987654'),
    ]);

    // Un worker `queue:work` est un processus long : `Artisan::call` y sert la
    // MÊME instance de `catalog:import-ids` à l'aperçu puis à l'import réel. La
    // file `sync` des tests fait de même dans l'application du test. L'aperçu
    // ne doit rien laisser derrière lui : sans remise à zéro, l'import se
    // croyait simulation et échouait en `simulation_resume`, sans rien écrire
    // (répétition de la mise en service du 28/09).
    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => '987654'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.import.index'));

    expect(ImportRun::query()->count())->toBe(0);

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '987654'])
        ->assertSessionHasNoErrors();

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    expect($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->total_imported)->toBe(1)
        ->and(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue();
});
