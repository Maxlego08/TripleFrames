<?php

use App\Enums\ContentOrigin;
use App\Enums\ImportRunKind;
use App\Enums\Locale;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use App\Support\Catalog\MovieImporter;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Le titre de la langue originale — spec 10 § 3.4, D52 du 02/10
|--------------------------------------------------------------------------
|
| TMDB rend vide la traduction de la langue originale d'un film. Quand cette
| langue est activée, l'import écrit `title_original` comme titre de cette
| locale ; jamais pour une langue non activée, jamais par-dessus une ligne
| existante. `catalog:original-titles` rattrape le catalogue importé avant,
| derrière l'instantané de la règle 12 — joué ici par un double qui note les
| lignes `movie_title` présentes au moment de l'instantané.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * Une fiche TMDB lue depuis une fixture, sa traduction `$locale` remplacée par
 * `$title` (chaîne vide : la forme que TMDB rend pour la langue originale).
 */
function originalTitleTmdb(string $fixture, ?string $locale = null, ?string $title = null): TmdbMovie
{
    $payload = TmdbFixture::array($fixture);

    if ($locale !== null) {
        /** @var array{translations: list<array{iso_639_1: string, data: array{title: string}}>} $translations */
        $translations = $payload['translations'];

        foreach ($translations['translations'] as $index => $translation) {
            if ($translation['iso_639_1'] === $locale) {
                $translations['translations'][$index]['data']['title'] = (string) $title;
            }
        }

        $payload['translations'] = $translations;
    }

    return TmdbMovie::fromArray($payload);
}

function originalTitleImport(TmdbMovie $tmdb, ImportRunKind $kind = ImportRunKind::Paste): Movie
{
    $run = ImportRun::factory()->create([
        'run_kind' => $kind,
        'is_widened' => false,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ]);

    app(MovieImporter::class)->import($tmdb, $run, ImportFilter::default());

    return Movie::query()->where('tmdb_id', $tmdb->tmdbId)->firstOrFail();
}

/**
 * @return array<string, array{string, ContentOrigin}>
 */
function originalTitleRows(Movie $movie): array
{
    return MovieTitle::query()
        ->where('movie_id', $movie->id)
        ->orderBy('locale')
        ->get()
        ->mapWithKeys(static fn (MovieTitle $title): array => [$title->locale => [$title->title, $title->origin]])
        ->all();
}

function originalTitleMask(Movie $movie): int
{
    return MovieProjection::query()->findOrFail($movie->id)->title_locale_mask;
}

/**
 * @return object{calls: list<int>}
 */
function originalTitleSnapshotDouble(int $exitCode): object
{
    $journal = new class
    {
        /** @var list<int> lignes `movie_title` présentes à chaque appel */
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
            if ($this->option('if-pending')) {
                throw new LogicException('catalog:original-titles ne passe jamais --if-pending.');
            }

            $this->journal->calls[] = MovieTitle::query()->count();

            return $this->exitCode;
        }
    };

    app(ConsoleKernel::class)->registerCommand($command);

    return $journal;
}

function originalTitleText(string $key, array $replacements = []): string
{
    $text = __($key, $replacements, Locale::French->value);

    return is_string($text) ? $text : $key;
}

/*
|--------------------------------------------------------------------------
| À l'import
|--------------------------------------------------------------------------
*/

it('écrit le titre original en anglais pour un film anglophone dont TMDB rend la traduction anglaise vide', function (): void {
    $movie = originalTitleImport(originalTitleTmdb('movie-987656', 'en', ''));

    expect(originalTitleRows($movie))->toBe([
        'en' => ['Northbound Nine', ContentOrigin::Tmdb],
        'fr' => ['Neuf vers le nord : la traversée', ContentOrigin::Tmdb],
    ])
        ->and(originalTitleMask($movie))->toBe(Locale::English->maskBit() | Locale::French->maskBit());
});

it('écrit le titre original en français pour un film francophone sans aucune traduction', function (): void {
    $movie = originalTitleImport(originalTitleTmdb('movie-987655-minimal'));

    expect(originalTitleRows($movie))->toBe([
        'fr' => ['Les Falaises de Quintane', ContentOrigin::Tmdb],
    ])
        ->and(originalTitleMask($movie))->toBe(Locale::French->maskBit());
});

it('n’écrit aucun titre pour une langue originale non activée', function (): void {
    $movie = originalTitleImport(originalTitleTmdb('movie-987664-ko'));

    // Le coréen n'est pas activé : seule la traduction française de TMDB entre,
    // et jamais une ligne `ko` ni une ligne `en` recopiée du titre original.
    expect(originalTitleRows($movie))->toBe([
        'fr' => ['La colline de Quintane', ContentOrigin::Tmdb],
    ])
        ->and(originalTitleMask($movie))->toBe(Locale::French->maskBit());
});

it('n’écrase jamais une traduction TMDB non vide de la langue originale', function (): void {
    $movie = originalTitleImport(originalTitleTmdb('movie-987656', 'en', 'Northbound Nine: The Crossing'));

    expect(originalTitleRows($movie)['en'])->toBe(['Northbound Nine: The Crossing', ContentOrigin::Tmdb]);
});

it('la resynchronisation réécrit le titre de la langue originale et garde une correction du curateur', function (): void {
    $movie = originalTitleImport(originalTitleTmdb('movie-987656', 'en', ''));

    MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'en')->update([
        'title' => 'Northbound 9',
        'origin' => ContentOrigin::Curator->value,
    ]);

    originalTitleImport(originalTitleTmdb('movie-987656', 'en', ''), ImportRunKind::Resync);

    expect(originalTitleRows($movie)['en'])->toBe(['Northbound 9', ContentOrigin::Curator]);

    MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'en')->delete();

    originalTitleImport(originalTitleTmdb('movie-987656', 'en', ''), ImportRunKind::Resync);

    expect(originalTitleRows($movie)['en'])->toBe(['Northbound Nine', ContentOrigin::Tmdb])
        ->and(MovieTitle::query()->where('movie_id', $movie->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Le rattrapage `catalog:original-titles`
|--------------------------------------------------------------------------
*/

it('la commande rattrape les films sans titre dans leur langue originale activée, et reprojette', function (): void {
    $snapshot = originalTitleSnapshotDouble(0);

    $english = Movie::factory()->create(['title_original' => 'Finding Things', 'original_language' => 'en']);
    MovieTitle::factory()->for($english)->create(['locale' => 'fr', 'title' => 'Le Monde des choses', 'origin' => ContentOrigin::Tmdb]);
    $french = Movie::factory()->create(['title_original' => 'La Grande Vadrouille', 'original_language' => 'fr']);
    $korean = Movie::factory()->create(['title_original' => '기생충', 'original_language' => 'ko']);
    $curated = Movie::factory()->create(['title_original' => 'Northbound Nine', 'original_language' => 'en']);
    MovieTitle::factory()->for($curated)->create(['locale' => 'en', 'title' => 'Northbound 9', 'origin' => ContentOrigin::Curator]);

    // Projections d'avant D52, écrites sans la ligne de langue originale.
    $this->artisan('catalog:reproject')->assertSuccessful();

    expect(originalTitleMask($english))->toBe(Locale::French->maskBit())
        ->and(originalTitleMask($french))->toBe(0);

    $this->artisan('catalog:original-titles')
        ->expectsOutputToContain(originalTitleText('admin.console.original_titles.done', ['count' => 2]))
        ->assertSuccessful();

    // L'instantané précède toute écriture : il a vu les deux lignes d'avant.
    expect($snapshot->calls)->toBe([2])
        ->and(originalTitleRows($english))->toBe([
            'en' => ['Finding Things', ContentOrigin::Tmdb],
            'fr' => ['Le Monde des choses', ContentOrigin::Tmdb],
        ])
        ->and(originalTitleMask($english))->toBe(Locale::English->maskBit() | Locale::French->maskBit())
        ->and(originalTitleRows($french))->toBe(['fr' => ['La Grande Vadrouille', ContentOrigin::Tmdb]])
        ->and(originalTitleMask($french))->toBe(Locale::French->maskBit())
        ->and(originalTitleRows($korean))->toBe([])
        ->and(originalTitleRows($curated))->toBe(['en' => ['Northbound 9', ContentOrigin::Curator]]);

    // Idempotente : rejouée, elle n'écrit plus rien.
    $this->artisan('catalog:original-titles')
        ->expectsOutputToContain(originalTitleText('admin.console.original_titles.done', ['count' => 0]))
        ->assertSuccessful();

    expect(MovieTitle::query()->count())->toBe(4);
});

it('la simulation compte les films à rattraper sans instantané ni écriture', function (): void {
    $snapshot = originalTitleSnapshotDouble(0);

    Movie::factory()->create(['original_language' => 'en']);
    Movie::factory()->create(['original_language' => 'fr']);
    Movie::factory()->create(['original_language' => 'ja']);

    $this->artisan('catalog:original-titles', ['--dry-run' => true])
        ->expectsOutputToContain(originalTitleText('admin.console.original_titles.dry_run', ['count' => 2]))
        ->assertSuccessful();

    expect($snapshot->calls)->toBe([])
        ->and(MovieTitle::query()->count())->toBe(0);
});

it('un instantané refusé arrête la commande sans rien écrire', function (): void {
    $snapshot = originalTitleSnapshotDouble(1);

    Movie::factory()->create(['original_language' => 'en']);

    $this->artisan('catalog:original-titles')
        ->expectsOutputToContain(originalTitleText('admin.console.original_titles.snapshot_failed'))
        ->assertFailed();

    expect($snapshot->calls)->toBe([0])
        ->and(MovieTitle::query()->count())->toBe(0);
});
