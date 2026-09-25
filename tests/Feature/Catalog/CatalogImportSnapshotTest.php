<?php

use App\Enums\ImportRunKind;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Support\Admin\ImportLauncher;
use App\Support\Catalog\ImportSnapshotGuard;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Garde d'instantané des imports lancés à la main — règle 12, I-9
|--------------------------------------------------------------------------
|
| Spec 100 § 11.4 et § 13.1, exigence adressée à la spec 20 : lancées à la
| main hors `local` et `testing`, `catalog:import-discover` et
| `catalog:import-ids` (`--resync` compris) appellent `backup:snapshot` avant
| leur première écriture et s'arrêtent sans rien écrire sur un code non nul.
| Le job `RunCatalogImport` du back-office — le chemin d'import ordinaire —
| en est dispensé.
|
| L'environnement est basculé en `production` le temps du test : l'instance
| d'application est jetée à la fin, rien ne fuit vers le test suivant.
| `backup:snapshot` n'est jamais joué pour de vrai ici — sa propre suite le
| couvre, `mysqldump` compris — : il est remplacé par un double qui note ce
| que la base contenait AU MOMENT de l'instantané, puis rend le code voulu.
|
*/

beforeEach(function (): void {
    Sleep::fake();

    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Config::set('catalog.import.requests_per_second', 1000);

    Http::fake([
        '*themoviedb.org/3/discover/movie*' => Http::response(TmdbFixture::json('discover-page-single'), 200, ['Content-Type' => 'application/json']),
        '*themoviedb.org/3/movie/987654*' => Http::response(TmdbFixture::json('movie-987654'), 200, ['Content-Type' => 'application/json']),
        '*themoviedb.org/3/movie/987656*' => Http::response(TmdbFixture::json('movie-987656'), 200, ['Content-Type' => 'application/json']),
        '*themoviedb.org/3/movie/987655*' => Http::response(TmdbFixture::json('movie-987655-minimal'), 200, ['Content-Type' => 'application/json']),
    ]);

    $this->app->detectEnvironment(fn (): string => 'production');
});

afterEach(function (): void {
    Sleep::fake(false);
});

/**
 * Remplace `backup:snapshot` par un double : il rend `$exitCode` et note,
 * à chaque appel, le nombre de films et de balayages présents en base.
 *
 * @return object{calls: list<array{movies: int, runs: int}>}
 */
function importSnapshotDouble(int $exitCode): object
{
    $journal = new class
    {
        /** @var list<array{movies: int, runs: int}> */
        public array $calls = [];
    };

    $command = new class($journal, $exitCode) extends Command
    {
        protected $signature = 'backup:snapshot {--if-pending}';

        protected $description = 'Double de test de l’instantané bloquant';

        public function __construct(private readonly object $journal, private readonly int $exitCode)
        {
            parent::__construct();
        }

        public function handle(): int
        {
            $this->journal->calls[] = [
                'movies' => Movie::query()->count(),
                'runs' => ImportRun::query()->count(),
            ];

            return $this->exitCode;
        }
    };

    app(ConsoleKernel::class)->registerCommand($command);

    return $journal;
}

test('prend un instantané bloquant avant toute écriture d\'une commande d\'import lancée à la main hors local et testing', function (): void {
    $snapshot = importSnapshotDouble(0);

    expect(app()->environment('production'))->toBeTrue()
        ->and(ImportSnapshotGuard::required(simulation: false))->toBeTrue();

    $this->artisan('catalog:import-ids', ['ids' => ['987654']])->assertSuccessful();

    // Un instantané, pris AVANT la première écriture : ni film ni balayage
    // n'existaient encore quand il a été demandé.
    expect($snapshot->calls)->toBe([['movies' => 0, 'runs' => 0]])
        ->and(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue();

    // `--resync`, qui supprime et réécrit les titres et alias d'origine
    // TMDB, et le balayage `discover` y passent aussi.
    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resync' => true])->assertSuccessful();
    $this->artisan('catalog:import-discover', ['--pages' => 1])->assertSuccessful();

    expect($snapshot->calls)->toHaveCount(3)
        ->and($snapshot->calls[1])->toBe(['movies' => 1, 'runs' => 1])
        ->and($snapshot->calls[2])->toBe(['movies' => 1, 'runs' => 2]);

    // Une simulation n'écrit rien : aucun instantané pour elle.
    $this->artisan('catalog:import-ids', ['ids' => ['987656'], '--dry-run' => true])->assertSuccessful();

    expect($snapshot->calls)->toHaveCount(3);

    // Le chemin d'import ORDINAIRE — le job du back-office — en est dispensé,
    // et la dispense ne survit pas à l'appel.
    $run = ImportLauncher::open(ImportRunKind::Paste, ImportFilter::default(), null);

    RunCatalogImport::paste($run, [987655])->handle();

    expect($snapshot->calls)->toHaveCount(3)
        ->and(Movie::query()->where('tmdb_id', 987655)->exists())->toBeTrue()
        ->and(ImportSnapshotGuard::onOrdinaryPath())->toBeFalse()
        ->and(ImportSnapshotGuard::required(simulation: false))->toBeTrue();
});

test('n\'écrit rien dans le catalogue quand l\'instantané échoue', function (): void {
    $snapshot = importSnapshotDouble(1);

    $this->artisan('catalog:import-ids', ['ids' => ['987654', '987656']])
        ->expectsOutputToContain('sans rien écrire')
        ->assertFailed();

    $this->artisan('catalog:import-ids', ['ids' => ['987654'], '--resync' => true])->assertFailed();
    $this->artisan('catalog:import-discover', ['--pages' => 1])->assertFailed();

    // Trois refus, et rien d'écrit : ni film, ni balayage, ni appel TMDB.
    expect($snapshot->calls)->toHaveCount(3)
        ->and(Movie::query()->count())->toBe(0)
        ->and(ImportRun::query()->count())->toBe(0);

    Http::assertNothingSent();

    // La reprise d'un balayage suspendu non plus : rien n'est estampillé.
    $suspended = ImportRun::factory()->discover()->running()->create(['started_at' => null]);

    $this->artisan('catalog:import-discover', ['--resume' => true, '--run' => $suspended->id])->assertFailed();

    expect($suspended->fresh()?->started_at)->toBeNull();
});
