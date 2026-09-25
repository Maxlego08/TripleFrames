<?php

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Enums\Locale;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\SeedList;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| La liste d'amorçage, lot par lot — spec 20 § 3.5, L20-16
|--------------------------------------------------------------------------
|
| Le fichier versionné du dépôt ne porte encore aucun identifiant (étape 54
| de REPRISE) : chaque test écrit SA liste dans un fichier temporaire, hors
| du dépôt, et y pointe `catalog.import.seed_list_path`. Les identifiants
| sont inventés. Aucun job ne part (`Bus::fake()`), aucun appel TMDB n'a
| lieu : la requête ne fait qu'ouvrir un collage.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');
    Config::set('catalog.import.paste_max_ids', 4);

    $this->curator = User::factory()->curator()->create();
    $this->seedFiles = [];
});

afterEach(function (): void {
    foreach ($this->seedFiles as $path) {
        File::delete($path);
    }
});

/**
 * Écrit une liste d'amorçage temporaire et y pointe la configuration.
 *
 * @param  list<string>  $lines
 */
function seedListWrite(array $lines): void
{
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-seed-'.bin2hex(random_bytes(6)).'.txt';

    File::put($path, implode("\n", $lines)."\n");

    test()->seedFiles = [...test()->seedFiles, $path];

    Config::set('catalog.import.seed_list_path', $path);
}

/**
 * Le texte français d'une clé du domaine `admin`, présente au dictionnaire.
 */
function seedListText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

/**
 * Le dernier collage dispatché : ses identifiants, dans l'ordre du job.
 *
 * @return list<int>
 */
function seedListLastBatch(): array
{
    /** @var list<int> $identifiers */
    $identifiers = [];

    Bus::assertDispatched(RunCatalogImport::class, function (RunCatalogImport $job) use (&$identifiers): bool {
        $identifiers = $job->identifiers;

        return $job->kind === ImportRunKind::Paste;
    });

    return $identifiers;
}

test('la liste d\'amorçage n\'ouvre jamais deux collages à la fois et le clic suivant reprend au premier identifiant manquant', function (): void {
    seedListWrite(['# Canon de fixture', '101', '102, 103', '104', '105', '106', '107', '108', '109', '110']);

    // Déjà au catalogue, et un film RETIRÉ : les deux comptent comme présents.
    Movie::factory()->create(['tmdb_id' => 102]);
    Movie::factory()->withdrawn()->create(['tmdb_id' => 104]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.seed_list'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.import.show', ['importRun' => ImportRun::query()->latest('id')->value('id')]));

    $first = ImportRun::query()->sole();

    expect($first->run_kind)->toBe(ImportRunKind::Paste)
        ->and($first->actor_id)->toBe($this->curator->id)
        ->and(seedListLastBatch())->toBe([101, 103, 105, 106]);

    // Un second clic pendant que le premier collage est en file : refusé par
    // le verrou, message traduit, et aucun second collage.
    $this->actingAs($this->curator)
        ->post(route('admin.import.seed_list'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', seedListText('admin.error.import_already_running'));

    expect(ImportRun::query()->count())->toBe(1);
    Bus::assertDispatchedTimes(RunCatalogImport::class, 1);

    // Le collage aboutit : 101, 103 et 106 entrent, 105 est refusé et n'entre
    // jamais. Le clic suivant reprend au premier identifiant MANQUANT.
    $first->forceFill(['status' => ImportRunStatus::Completed, 'finished_at' => now()])->save();

    foreach ([101, 103, 106] as $tmdbId) {
        Movie::factory()->create(['tmdb_id' => $tmdbId]);
    }

    $this->actingAs($this->curator)
        ->post(route('admin.import.seed_list'))
        ->assertSessionHasNoErrors();

    expect(ImportRun::query()->count())->toBe(2);
    Bus::assertDispatchedTimes(RunCatalogImport::class, 2);

    expect(seedListLastBatch())->toBe([105, 107, 108, 109]);
});

test('un collage de la liste porte au plus paste_max_ids identifiants, dans l\'ordre du fichier', function (): void {
    Config::set('catalog.import.paste_max_ids', 3);

    seedListWrite([
        '# L’ordre du FICHIER, jamais un tri',
        'https://www.themoviedb.org/movie/905-un-slug',
        '12',
        '330 # commentaire en fin de ligne',
        '7',
        '12',
        '64',
    ]);

    $this->actingAs($this->curator)
        ->post(route('admin.import.seed_list'))
        ->assertSessionHasNoErrors();

    expect(seedListLastBatch())->toBe([905, 12, 330])
        ->and(SeedList::summary()['next_batch'])->toBe([905, 12, 330])
        ->and(SeedList::summary()['remaining'])->toBe(5);

    // Le même plafond que le collage web, lu au même endroit.
    Config::set('catalog.import.paste_max_ids', 50);

    expect(SeedList::nextBatch())->toBe([905, 12, 330, 7, 64]);
});

test('le bouton est inactif tant qu\'un collage est ouvert', function (): void {
    seedListWrite(['201', '202']);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_list.state', SeedList::READY)
            ->where('seed_list.total', 2)
            ->where('seed_list.remaining', 2)
            ->where('seed_list.next_batch', [201, 202])
            ->where('seed_list.busy_run_id', null));

    // Un collage en file — celui d'un « Importer » de la recherche, par
    // exemple : le bouton attend, et l'écran lie ce collage.
    $open = ImportRun::factory()->paste()->running()->create(['started_at' => null]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_list.state', SeedList::BUSY)
            ->where('seed_list.busy_run_id', $open->id));

    expect(seedListText('admin.import.seed_list.busy'))->not->toBe('');

    // Le collage fini, le bouton redevient utile.
    $open->forceFill(['status' => ImportRunStatus::Completed, 'finished_at' => now()])->save();

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_list.state', SeedList::READY)
            ->where('seed_list.busy_run_id', null));
});

test('une liste sans identifiant désactive le bouton', function (): void {
    seedListWrite([
        '# Liste d’amorçage — sections sans identifiant',
        '# --- 1. Canon Disney antérieur à 1970',
        '',
        '# --- 2. Classiques antérieurs à 1970',
    ]);

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('seed_list.state', SeedList::EMPTY)
            ->where('seed_list.total', 0)
            ->where('seed_list.next_batch', []));

    // Le serveur refuse de même un envoi forcé, par un message traduit.
    $this->actingAs($this->curator)
        ->post(route('admin.import.seed_list'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', seedListText('admin.import.seed_list.empty'));

    Bus::assertNothingDispatched();
    expect(ImportRun::query()->count())->toBe(0);

    // Un fichier absent vaut une liste vide, jamais une page d'erreur.
    Config::set('catalog.import.seed_list_path', 'database/data/absent-seed-list.txt');

    $this->actingAs($this->curator)
        ->get(route('admin.import.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('seed_list.state', SeedList::EMPTY));
});
