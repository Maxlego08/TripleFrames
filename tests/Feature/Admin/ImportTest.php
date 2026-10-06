<?php

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Http\Controllers\Admin\ImportController;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\Theme;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Import — les deux voies, différées, et leur journal
|--------------------------------------------------------------------------
|
| Le test qui porte toute la règle 6 : **aucune route POST n'appelle TMDB dans
| la requête web**. Il est doublé par `Http::preventStrayRequests()`, posé pour
| toute la suite dans `tests/Pest.php` — un appel non simulé LÈVE au lieu de
| partir, donc un contrôleur qui se mettrait à appeler TMDB ferait tomber ces
| tests au rouge sans qu'on ait à le relire.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->curator = User::factory()->curator()->create();
    Config::set('services.tmdb.api_key', 'clef-de-test');
});

test('l’écran d’import rend ses deux voies, ses défauts et son historique', function (): void {
    ImportRun::factory()->count(3)->create();

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/import/index', false)
            ->has('runs.data', 3)
            ->has('runs.meta')
            ->missing('runs.links')
            ->where('defaults.min_vote_count', config('catalog.import_filter.min_vote_count'))
            ->where('defaults.languages', config('catalog.import_filter.languages'))
            ->where('defaults.min_release_year', config('catalog.import_filter.min_release_year'))
            ->where('defaults.pages_min', config('catalog.import.pages_min'))
            ->where('defaults.pages_max', config('catalog.import.pages_max'))
            ->where('defaults.pages_default', config('catalog.import.pages_default'))
            ->where('defaults.pages_choices', config('catalog.import.pages_choices'))
            ->where('defaults.paste_max_ids', config('catalog.import.paste_max_ids'))
            ->where('defaults.paste_max_themes', config('catalog.import.paste_max_themes'))
            ->has('defaults.language_choices')
            ->where('resumable', null)
            ->where('tmdb_configured', true));
});

test('sans clé TMDB, l’écran le dit', function (): void {
    Config::set('services.tmdb.api_key', '');
    Config::set('services.tmdb.read_access_token', '');

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('tmdb_configured', false));
});

test('un balayage discover en cours est proposé à la reprise', function (): void {
    $running = ImportRun::factory()->discover()->running()->create();
    ImportRun::factory()->paste()->running()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('resumable.id', $running->id));
});

test('le détail d’un balayage rend son résumé, son filtre figé et les films qu’il a fait entrer', function (): void {
    $run = ImportRun::factory()
        ->discover()
        ->running()
        ->widened()
        ->withFilter(100, 'fr,ko', 1930)
        ->create(['tmdb_page_cursor' => 7]);

    Movie::factory()->count(2)->create(['import_run_id' => $run->id]);
    Movie::factory()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.import.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/import/show', false)
            ->where('run.id', $run->id)
            ->where('run.run_kind', 'discover')
            ->where('run.status', 'running')
            ->where('run.is_widened', true)
            ->where('run.filter_min_vote_count', 100)
            ->where('run.filter_languages', 'fr,ko')
            ->where('run.filter_min_release_year', 1930)
            ->where('run.tmdb_page_cursor', 7)
            ->has('run.last_request_at')
            ->has('movies.data', 2)
            ->where('can_resume', true));
});

test('un collage n’est jamais reprenable depuis l’écran', function (): void {
    $run = ImportRun::factory()->paste()->running()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.import.show', $run))
        ->assertInertia(fn (Assert $page) => $page->where('can_resume', false));
});

test('le balayage discover ouvre un run « en file » et dispatche le job, sans toucher TMDB', function (): void {
    Bus::fake();

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_votes' => 200,
            'languages' => ['fr', 'ko'],
            'min_year' => 1930,
            'pages' => 3,
        ])
        ->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();

    expect($run->run_kind)->toBe(ImportRunKind::Discover)
        ->and($run->status)->toBe(ImportRunStatus::Running)
        ->and($run->started_at)->toBeNull()
        ->and($run->actor_id)->toBe($this->curator->id)
        // Le filtre est FIGÉ au démarrage, et `is_widened` avec lui.
        ->and($run->filter_min_vote_count)->toBe(200)
        ->and($run->filter_languages)->toBe('fr,ko')
        ->and($run->filter_min_release_year)->toBe(1930)
        ->and($run->is_widened)->toBeTrue();

    Bus::assertDispatched(
        RunCatalogImport::class,
        fn (RunCatalogImport $job): bool => $job->runId === $run->id
            && $job->kind === ImportRunKind::Discover
            && $job->pages === 3
            && $job->identifiers === [],
    );
});

test('un balayage au filtre par défaut n’est pas marqué élargi', function (): void {
    Bus::fake();

    $default = config('catalog.import_filter');

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_votes' => $default['min_vote_count'],
            'languages' => $default['languages'],
            'min_year' => $default['min_release_year'],
            'pages' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(ImportRun::query()->sole()->is_widened)->toBeFalse();
});

test('le collage lit les identifiants et les URL TMDB, et n’est jamais marqué élargi', function (): void {
    Bus::fake();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), [
            'ids' => "550\n# un commentaire\nhttps://www.themoviedb.org/movie/129-spirited-away\n550",
        ])
        ->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();

    expect($run->run_kind)->toBe(ImportRunKind::Paste)
        ->and($run->is_widened)->toBeFalse()
        // Le filtre est ENREGISTRÉ sans être appliqué : il documente le défaut
        // en vigueur au moment du geste (§ 9.2).
        ->and($run->filter_min_vote_count)->toBe(config('catalog.import_filter.min_vote_count'));

    Bus::assertDispatched(
        RunCatalogImport::class,
        // Ordre du collage conservé, doublon retiré.
        fn (RunCatalogImport $job): bool => $job->identifiers === [550, 129]
            && $job->kind === ImportRunKind::Paste,
    );
});

test('le collage sans thème reste valide', function (): void {
    Bus::fake();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550'])
        ->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();

    // NULL, jamais une chaîne vide : un collage sans thème n'en relit aucun.
    expect($run->added_theme_ids)->toBeNull()
        ->and($run->addedThemeIds())->toBe([]);
});

test('l\'écran d\'import propose la liste des thèmes', function (): void {
    $published = Theme::factory()->decade(1990)->create();
    $unpublished = Theme::factory()->decade(1940)->unpublished()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('themes', 2)
            ->where('themes', fn ($themes): bool => collect($themes)->pluck('id')->sort()->values()->all()
                === collect([$published->id, $unpublished->id])->sort()->values()->all())
            ->has('themes.0', fn (Assert $theme) => $theme
                ->has('id')
                ->has('key')
                ->has('label')
                ->has('kind')
                ->has('is_published')));
});

test('le détail d\'un collage avec thèmes rend les thèmes appliqués et ses deux compteurs', function (): void {
    $theme = Theme::factory()->decade(1990)->unpublished()->create();
    $missing = Theme::factory()->decade(1980)->create();
    $missingId = $missing->id;
    $missing->labels()->delete();
    $missing->delete();

    $run = ImportRun::factory()->paste()->create([
        'added_theme_ids' => $theme->id.','.$missingId,
        'total_themes_applied' => 3,
        'total_themes_kept_removed' => 1,
    ]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Un thème disparu entre-temps est ignoré à la lecture.
            ->has('run.added_themes', 1)
            ->where('run.added_themes.0.id', $theme->id)
            ->where('run.added_themes.0.is_published', false)
            ->where('run.total_themes_applied', 3)
            ->where('run.total_themes_kept_removed', 1));
});

test('un collage illisible et un collage trop long sont refusés séparément', function (): void {
    Bus::fake();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => 'rien que du texte'])
        ->assertSessionHasErrors('ids');

    $max = (int) config('catalog.import.paste_max_ids');

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => implode("\n", range(1, $max + 1))])
        ->assertSessionHasErrors('ids');

    Bus::assertNothingDispatched();
    expect(ImportRun::query()->count())->toBe(0);
});

test('les bornes du balayage sont celles de la configuration, jamais des littéraux', function (): void {
    Bus::fake();

    $payload = ['min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970];

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [...$payload, 'pages' => config('catalog.import.pages_max') + 1])
        ->assertSessionHasErrors('pages');

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [...$payload, 'min_year' => 1000])
        ->assertSessionHasErrors('min_year');

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [...$payload, 'languages' => ['francais']])
        ->assertSessionHasErrors('languages.0');

    Config::set('catalog.import.pages_max', 9);

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [...$payload, 'pages' => 9])
        ->assertSessionHasNoErrors();
});

test('sans clé TMDB, aucune des deux voies n’ouvre de balayage', function (): void {
    Bus::fake();

    Config::set('services.tmdb.api_key', '');
    Config::set('services.tmdb.read_access_token', '');

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1,
        ])
        ->assertRedirect();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550'])
        ->assertRedirect();

    Bus::assertNothingDispatched();
    expect(ImportRun::query()->count())->toBe(0);
});

test('un second balayage de la même nature est refusé tant qu’un worker tient le premier', function (): void {
    Bus::fake();

    // Un balayage que le worker vient de toucher : c'est ÇA, une concurrence.
    ImportRun::factory()->discover()->running()->create([
        'last_request_at' => CarbonImmutable::now(),
    ]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1,
        ])
        ->assertRedirect();

    Bus::assertNothingDispatched();

    // La voie de collage, elle, reste ouverte : deux curseurs concurrents sur
    // la même nature seraient le problème, pas deux natures distinctes.
    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550'])
        ->assertSessionHasNoErrors();

    Bus::assertDispatchedTimes(RunCatalogImport::class, 1);
});

test('un balayage suspendu n’interdit pas d’en lancer un autre', function (): void {
    Bus::fake();

    // Sortie de l'impasse : un `discover` ne se clôt de lui-même qu'une fois
    // TOUTES les langues du filtre épuisées — plusieurs centaines de pages —
    // et reste donc `running` entre deux invocations. Le traiter comme
    // « occupé » condamnerait le formulaire après un seul usage.
    ImportRun::factory()->discover()->running()->create([
        'started_at' => CarbonImmutable::now()->subHour(),
        'last_request_at' => CarbonImmutable::now()->subHour(),
    ]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_votes' => 500, 'languages' => ['fr'], 'min_year' => 1970, 'pages' => 1,
        ])
        ->assertSessionHasNoErrors();

    expect(ImportRun::query()->count())->toBe(2);
    Bus::assertDispatchedTimes(RunCatalogImport::class, 1);
});

test('un balayage encore « en file » n’est ni proposé ni accepté à la reprise', function (): void {
    Bus::fake();

    // `status = running` ET `started_at = null` : le job dort dans la file.
    // Le reprendre ferait avaler un second dispatch par `ShouldBeUnique`
    // pendant que l'écran annoncerait « balayage repris ».
    $queued = ImportRun::factory()->discover()->running()->create(['started_at' => null]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page->where('resumable', null));

    $this->actingAs($this->curator)
        ->get(route('admin.import.show', $queued))
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_resume', false)
            ->where('run.is_queued', true)
            ->where('tmdb_configured', true));

    $this->actingAs($this->curator)
        ->post(route('admin.import.resume', $queued))
        ->assertRedirect();

    Bus::assertNothingDispatched();
});

test('la reprise repart au PLAFOND de pages, pas au plancher du formulaire', function (): void {
    Bus::fake();

    $run = ImportRun::factory()->discover()->running()->create();

    $this->actingAs($this->curator)
        ->post(route('admin.import.resume', $run));

    Bus::assertDispatched(
        RunCatalogImport::class,
        fn (RunCatalogImport $job): bool => $job->pages === ImportController::defaults()['pages_max']
            && $job->pages === (int) config('catalog.import.pages_max'),
    );
});

test('la reprise redispatche le MÊME balayage, sans en ouvrir un second', function (): void {
    Bus::fake();

    $run = ImportRun::factory()->discover()->running()->create(['tmdb_page_cursor' => 4]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.resume', $run))
        ->assertRedirect(route('admin.import.show', ['importRun' => $run->id]));

    expect(ImportRun::query()->count())->toBe(1);

    Bus::assertDispatched(
        RunCatalogImport::class,
        fn (RunCatalogImport $job): bool => $job->runId === $run->id,
    );
});

test('la reprise d’un collage en cours est refusée, avec son motif', function (): void {
    Bus::fake();

    $run = ImportRun::factory()->paste()->running()->create();

    $this->actingAs($this->curator)
        ->post(route('admin.import.resume', $run))
        ->assertRedirect();

    Bus::assertNothingDispatched();
});

test('le job porte les gardes qui protègent les quatre compteurs', function (): void {
    $run = ImportRun::factory()->discover()->running()->create();
    $job = RunCatalogImport::discover($run, 2);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->uniqueId())->toBe('catalog-import-'.$run->id)
        // File par DÉFAUT, jamais une file de jeu.
        ->and($job->queue)->toBeNull();
});

test('un job perdu clôt le balayage en échec plutôt que de le laisser « en cours » pour l’éternité', function (): void {
    $run = ImportRun::factory()->discover()->running()->create();

    RunCatalogImport::discover($run, 1)->failed(new RuntimeException('worker tué'));

    $run->refresh();

    expect($run->status)->toBe(ImportRunStatus::Failed)
        ->and($run->finished_at)->not->toBeNull();
});

test('un job perdu ne réécrit jamais un balayage déjà terminé', function (): void {
    $run = ImportRun::factory()->discover()->completed()->create();
    $finishedAt = $run->finished_at;

    RunCatalogImport::discover($run, 1)->failed(null);

    $run->refresh();

    expect($run->status)->toBe(ImportRunStatus::Completed)
        ->and($run->finished_at?->toIso8601String())->toBe($finishedAt?->toIso8601String());
});
