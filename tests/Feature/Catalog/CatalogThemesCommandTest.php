<?php

use App\Enums\Locale;
use App\Enums\ThemeMembershipState;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| `catalog:themes` — spec 30 § 13.2 (L30-8), règle 12
|--------------------------------------------------------------------------
|
| Le rattrapage complet, joué à la main par le porteur. `backup:snapshot`
| n'est jamais joué pour de vrai ici (sa propre suite le couvre, `mysqldump`
| compris) : un double note ce que `movie_theme` contenait au moment de
| l'instantané, puis rend le code voulu.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * @return object{calls: list<int>}
 */
function catalogThemesSnapshotDouble(int $exitCode): object
{
    $journal = new class
    {
        /** @var list<int> lignes `movie_theme` présentes à chaque appel */
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
            // Sans `--if-pending` : la commande exige un instantané à chaque passage.
            if ($this->option('if-pending')) {
                throw new LogicException('catalog:themes ne passe jamais --if-pending.');
            }

            $this->journal->calls[] = MovieTheme::query()->count();

            return $this->exitCode;
        }
    };

    app(ConsoleKernel::class)->registerCommand($command);

    return $journal;
}

function catalogThemesText(string $key, array $replacements = []): string
{
    $text = __($key, $replacements, Locale::French->value);

    return is_string($text) ? $text : $key;
}

it('la commande rattrape les appartenances de tout le catalogue', function (): void {
    $snapshot = catalogThemesSnapshotDouble(0);

    $animation = Theme::factory()->genre(16)->create();
    $unpublished = Theme::factory()->decade(1990)->unpublished()->create();

    $first = Movie::factory()->withGenre(16)->create(['release_year' => 1994]);
    $second = Movie::factory()->withGenre(18)->create(['release_year' => 1997]);

    $this->artisan('catalog:themes')
        ->expectsOutputToContain(catalogThemesText('admin.console.themes.done', ['themes' => 2, 'changed' => 3]))
        ->assertSuccessful();

    // L'instantané précède toute écriture.
    expect($snapshot->calls)->toBe([0])
        ->and(MovieTheme::query()->where('theme_id', $animation->id)->pluck('movie_id')->all())->toBe([$first->id])
        ->and(MovieTheme::query()->where('theme_id', $unpublished->id)->orderBy('movie_id')->pluck('movie_id')->all())
        ->toBe([$first->id, $second->id]);
});

it('catalog:themes est idempotente', function (): void {
    catalogThemesSnapshotDouble(0);

    Theme::factory()->genre(16)->create();
    Theme::factory()->language('en')->negated()->create();
    $movie = Movie::factory()->withGenre(16)->create(['original_language' => 'fr']);

    $this->artisan('catalog:themes')->assertSuccessful();

    $rows = DB::table('movie_theme')->orderBy('id')->get()->all();

    $this->artisan('catalog:themes')
        ->expectsOutputToContain(catalogThemesText('admin.console.themes.done', ['themes' => 2, 'changed' => 0]))
        ->assertSuccessful();

    expect(DB::table('movie_theme')->orderBy('id')->get()->all())->toEqual($rows)
        ->and(MovieTheme::query()->where('movie_id', $movie->id)->count())->toBe(2);
});

it('catalog:themes n\'écrit aucune ligne movie_theme si l\'instantané échoue', function (): void {
    $snapshot = catalogThemesSnapshotDouble(1);

    $theme = Theme::factory()->genre(16)->create();
    $curator = User::factory()->create();
    $stale = Movie::factory()->withGenre(18)->create();
    $missing = Movie::factory()->withGenre(16)->create();

    // Un état à corriger : une ligne périmée, une exception, une ligne manquante.
    MovieTheme::factory()->auto()->create(['movie_id' => $stale->id, 'theme_id' => $theme->id]);
    MovieTheme::factory()->manualRemoved($curator)->create([
        'movie_id' => Movie::factory()->withGenre(16)->create()->id,
        'theme_id' => $theme->id,
        'is_auto' => false,
    ]);

    $before = DB::table('movie_theme')->orderBy('id')->get()->all();

    $this->artisan('catalog:themes')
        ->expectsOutputToContain(catalogThemesText('admin.console.themes.snapshot_failed'))
        ->assertFailed();

    expect($snapshot->calls)->toBe([2])
        ->and(DB::table('movie_theme')->orderBy('id')->get()->all())->toEqual($before)
        ->and(MovieTheme::query()->where('movie_id', $missing->id)->exists())->toBeFalse()
        ->and(MovieTheme::query()->where('manual_state', ThemeMembershipState::Removed->value)->value('is_auto'))->toBeFalsy();
});
