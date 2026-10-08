<?php

use App\Actions\Curation\DeleteAlias;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\User;
use App\Support\Catalog\MovieImporter;
use App\Support\Catalog\ResyncSnapshot;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Resynchronisation TMDB depuis l'écran — spec 20 § 3.7, L20-24
|--------------------------------------------------------------------------
|
| L'écran de différences relit la fiche TMDB d'un film (appel interactif,
| simulé ici par `Http::fake()`), l'applique puis l'annule : il ne montre que
| la liste close du § 9.3 et n'écrit rien. Le lancement ouvre un balayage
| `resync` mis en file, jamais un appel TMDB dans la requête POST.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Config::set('services.tmdb.api_key', 'clef-de-test');
    Config::set('catalog.import.requests_per_second', 0);

    $this->curator = User::factory()->curator()->create();
});

/** Une fiche TMDB complète, lue depuis une fixture anonymisée. */
function resyncScreenTmdb(string $fixture): TmdbMovie
{
    return TmdbMovie::fromArray(TmdbFixture::array($fixture));
}

/** Le film de la fixture `movie-987654`, importé par un balayage. */
function resyncScreenMovie(): Movie
{
    $run = ImportRun::factory()->discover()->create();

    app(MovieImporter::class)->import(resyncScreenTmdb('movie-987654'), $run, ImportFilter::default());

    return Movie::query()->where('tmdb_id', 987654)->firstOrFail();
}

/** La fiche TMDB que l'écran relira. */
function resyncScreenFake(string $fixture): void
{
    Http::fake([
        '*themoviedb.org/3/movie/987654*' => Http::response(TmdbFixture::json($fixture), 200, ['Content-Type' => 'application/json']),
    ]);
}

test('l\'écran de différences ne propose que la liste close des écrasables', function (): void {
    $movie = resyncScreenMovie();

    // Une correction de curateur : jamais réécrite, nommée par l'écran.
    MovieTitle::query()
        ->where('movie_id', $movie->id)
        ->where('locale', 'en')
        ->update(['title' => 'Titre corrigé à la main', 'origin' => ContentOrigin::Curator->value]);

    resyncScreenFake('movie-987654-resynced');

    $journal = AdminAction::query()->count();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.resync.show', ['movies' => [$movie->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/resync', false)
            ->where('eligible_count', 1)
            ->where('movies.0.ineligible', null)
            ->where('preview.status', 'ready')
            ->where('preview.rows', fn ($rows): bool => collect($rows)->pluck('field')->all() === ResyncSnapshot::FIELDS)
            ->where('preview.changed', fn ($changed): bool => collect($changed)->contains('title_original')
                && collect($changed)->contains('vote_count')
                && collect($changed)->every(fn (string $field): bool => in_array($field, ResyncSnapshot::FIELDS, true)))
            ->where('preview.curator_title_locales', ['en'])
            ->where('preview.content_flag_blocked', false));

    // Appliquée puis annulée : rien n'a été écrit, pas même le journal.
    $movie->refresh();

    expect($movie->title_original)->toBe('ガラスの果樹園')
        ->and($movie->vote_count)->toBe(2410)
        ->and(AdminAction::query()->count())->toBe($journal)
        ->and(MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'en')->value('title'))
        ->toBe('Titre corrigé à la main');
});

test('une certification restrictive bloque le film et propose une dépublication sans la prononcer', function (): void {
    $movie = resyncScreenMovie();
    $movie->forceFill([
        'availability' => ContentAvailability::Published,
        'first_published_at' => now(),
        'content_flag' => ContentFlag::Clear,
    ])->save();

    resyncScreenFake('movie-987654-resynced-blocked');

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.resync.show', ['movies' => [$movie->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.content_flag_blocked', true)
            ->where('preview.propose_unpublish', true));

    // La resynchronisation réelle, signée du curateur qui l'a lancée.
    $run = ImportRun::factory()->resync()->running()->actedBy($this->curator)->create();

    app(MovieImporter::class)->import(resyncScreenTmdb('movie-987654-resynced-blocked'), $run, ImportFilter::default());

    $movie->refresh();

    expect($movie->content_flag)->toBe(ContentFlag::Blocked)
        ->and($movie->availability)->toBe(ContentAvailability::Published);

    /** @var AdminAction $line */
    $line = AdminAction::query()->where('action', AdminActionType::MovieResynced->value)->sole();

    expect($line->subject_id)->toBe($movie->id)
        ->and($line->actor_id)->toBe($this->curator->id)
        ->and($line->details?->values['import_run_id'])->toBe($run->id)
        ->and($line->details?->values['content_flag_blocked'])->toBeTrue()
        ->and($line->details?->values['changed'])->toContain('content_flag', 'movie_certification');

    expect(AdminAction::query()->where('action', AdminActionType::MovieUnpublished->value)->exists())->toBeFalse();
});

test('un alias TMDB supprimé à la main réapparaît signalé', function (): void {
    $movie = resyncScreenMovie();

    /** @var Alias $alias */
    $alias = Alias::query()
        ->where('movie_id', $movie->id)
        ->where('origin', ContentOrigin::Tmdb->value)
        ->where('alias', 'Le Verger')
        ->firstOrFail();

    app(DeleteAlias::class)->handle($movie, $this->curator, $alias);

    resyncScreenFake('movie-987654');

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.resync.show', ['movies' => [$movie->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preview.status', 'ready')
            ->where('preview.reappeared_aliases', ['fr : Le Verger'])
            ->where('preview.changed', ['alias']));
});

test('le lancement met en file un balayage resync sans appel TMDB et écarte les films non éligibles', function (): void {
    Bus::fake();
    Http::fake();

    $eligible = Movie::factory()->published()->create(['tmdb_id' => 4242]);
    $demo = Movie::factory()->demo()->create();
    $withdrawn = Movie::factory()->withdrawn()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.resync.show', ['movies' => [$eligible->id, $demo->id, $withdrawn->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('eligible_count', 1)
            ->where('movies.1.ineligible', 'demo')
            ->where('movies.2.ineligible', 'withdrawn')
            ->where('preview', null));

    $response = $this->actingAs($this->curator)
        ->post(route('admin.catalog.resync.store'), ['movies' => [$eligible->id, $demo->id, $withdrawn->id]]);

    /** @var ImportRun $run */
    $run = ImportRun::query()->sole();

    $response->assertRedirect(route('admin.import.show', ['importRun' => $run->id]));

    expect($run->run_kind)->toBe(ImportRunKind::Resync)
        ->and($run->status)->toBe(ImportRunStatus::Running)
        ->and($run->actor_id)->toBe($this->curator->id);

    Bus::assertDispatched(RunCatalogImport::class, fn (RunCatalogImport $job): bool => $job->runId === $run->id
        && $job->kind === ImportRunKind::Resync
        && $job->identifiers === [4242]);

    Http::assertNothingSent();
});

test('une seconde resynchronisation est refusée tant que la première est en cours', function (): void {
    Bus::fake();

    $movie = Movie::factory()->create();
    ImportRun::factory()->resync()->running()->create(['started_at' => null]);

    $this->actingAs($this->curator)
        ->post(route('admin.catalog.resync.store'), ['movies' => [$movie->id]])
        ->assertRedirect();

    expect(ImportRun::query()->count())->toBe(1);

    Bus::assertNotDispatched(RunCatalogImport::class);
});

test('une resynchronisation n\'écrit movie.resynced que pour un film qui change', function (): void {
    $movie = resyncScreenMovie();
    $importer = app(MovieImporter::class);

    $first = ImportRun::factory()->resync()->running()->actedBy($this->curator)->create();
    $importer->import(resyncScreenTmdb('movie-987654-resynced'), $first, ImportFilter::default());

    expect(AdminAction::query()->where('action', AdminActionType::MovieResynced->value)->count())->toBe(1);

    // La même lecture une seconde fois : rien ne change, rien n'est écrit.
    $second = ImportRun::factory()->resync()->running()->actedBy($this->curator)->create();
    $importer->import(resyncScreenTmdb('movie-987654-resynced'), $second, ImportFilter::default());

    expect(AdminAction::query()->where('action', AdminActionType::MovieResynced->value)->count())->toBe(1);

    // Sans auteur (console sans --actor) : aucune ligne, la console n'est
    // admise que pour trois gestes.
    $console = ImportRun::factory()->resync()->running()->actedBy(null)->create();
    $importer->import(resyncScreenTmdb('movie-987654'), $console, ImportFilter::default());

    expect(AdminAction::query()->where('action', AdminActionType::MovieResynced->value)->count())->toBe(1)
        ->and($movie->refresh()->vote_count)->toBe(2410);
});

test('la fiche propose la resynchronisation sauf pour un film retiré ou de démonstration', function (): void {
    $movie = Movie::factory()->create();
    $demo = Movie::factory()->demo()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.resync', true));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $demo))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.resync', false));
});
