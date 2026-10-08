<?php

use App\Enums\MovieDifficulty;
use App\Jobs\Catalog\DeriveMovieDifficulty;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Support\Catalog\MovieDifficultyDeriver;
use Database\Seeders\PlatformDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| Difficulté dérivée — spec 30 § 14, lot L30-10
|--------------------------------------------------------------------------
|
| Décile de notoriété par langue originale, bonus de saga, cinq classes de
| deux déciles. Les films sont créés sans difficulté (`movie_difficulty*`
| nuls), avec des votes choisis pour que chaque décile se calcule à la main :
| dans un seau de dix films aux votes distincts, le film de rang r (0 = le
| moins voté) a le décile r + 1.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();

    Config::set('catalog.difficulty.min_language_sample', 5);
    Config::set('catalog.difficulty.saga_decile_bonus', 2);
});

/**
 * Un film réel sans difficulté, aux votes et à la langue donnés.
 *
 * @param  array<string, mixed>  $attributes
 */
function derivationMovie(int $votes, string $language = 'en', array $attributes = []): Movie
{
    return Movie::factory()->create(array_merge([
        'vote_count' => $votes,
        'original_language' => $language,
        'movie_difficulty' => null,
        'movie_difficulty_derived' => null,
        'movie_difficulty_override' => null,
    ], $attributes));
}

/**
 * Dix films d'une langue, votes 100, 200 … 1000 : déciles 1 à 10.
 *
 * @return list<Movie>
 */
function derivationLadder(string $language = 'en', int $step = 100): array
{
    return array_map(
        static fn (int $rank): Movie => derivationMovie($step * ($rank + 1), $language),
        range(0, 9),
    );
}

function derivedOf(Movie $movie): ?MovieDifficulty
{
    return Movie::query()->findOrFail($movie->id)->movie_difficulty_derived;
}

function deriveDifficulty(): int
{
    return app(MovieDifficultyDeriver::class)->derive();
}

test('un film est classé par décile de notoriété dans sa propre langue originale', function (): void {
    $english = derivationLadder('en', 1_000);
    // Le français est cent fois moins voté, et son film le plus voté reste très facile.
    $french = derivationLadder('fr', 10);

    expect(deriveDifficulty())->toBe(20);

    $expected = [
        MovieDifficulty::VeryHard, MovieDifficulty::VeryHard,
        MovieDifficulty::Hard, MovieDifficulty::Hard,
        MovieDifficulty::Medium, MovieDifficulty::Medium,
        MovieDifficulty::Easy, MovieDifficulty::Easy,
        MovieDifficulty::VeryEasy, MovieDifficulty::VeryEasy,
    ];

    foreach ([$english, $french] as $ladder) {
        foreach ($ladder as $rank => $movie) {
            $fresh = Movie::query()->findOrFail($movie->id);

            expect($fresh->movie_difficulty_derived)->toBe($expected[$rank])
                ->and($fresh->movie_difficulty)->toBe($expected[$rank]);
        }
    }

    // Le film français le plus voté (100 votes) est très facile ; l'anglais le moins voté (1 000) très difficile.
    expect(derivedOf($french[9]))->toBe(MovieDifficulty::VeryEasy)
        ->and(derivedOf($english[0]))->toBe(MovieDifficulty::VeryHard);
});

test('deux films à vote_count égal reçoivent la même classe', function (): void {
    derivationLadder();
    $twinA = derivationMovie(750);
    $twinB = derivationMovie(750);

    deriveDifficulty();

    expect(derivedOf($twinA))->toBe(derivedOf($twinB));
    // Douze films, sept sous 750 votes : d = intdiv(70, 12) + 1 = 6 → medium.
    expect(derivedOf($twinA))->toBe(MovieDifficulty::Medium);
});

test('une langue sous l\'échantillon minimal rejoint le seau commun', function (): void {
    // Quatre films coréens, sous l'échantillon de cinq : ils rejoignent le seau
    // commun, seul ici, donc classés entre eux.
    $korean = array_map(static fn (int $votes): Movie => derivationMovie($votes, 'ko'), [10, 20, 30, 40]);
    // Un film italien seul rejoint le même seau commun.
    $italian = derivationMovie(25, 'it');
    derivationLadder('en');

    deriveDifficulty();

    // Seau commun : 10, 20, 25, 30, 40 (n = 5). 40 → r = 4, d = 9 ; 10 → d = 1 ; 25 → r = 2, d = 5.
    expect(derivedOf($korean[3]))->toBe(MovieDifficulty::VeryEasy)
        ->and(derivedOf($korean[0]))->toBe(MovieDifficulty::VeryHard)
        ->and(derivedOf($italian))->toBe(MovieDifficulty::Medium);

    // À l'échantillon de quatre, le coréen forme son propre seau : 40 → r = 3, d = 8 → easy.
    Config::set('catalog.difficulty.min_language_sample', 4);
    deriveDifficulty();

    expect(derivedOf($korean[3]))->toBe(MovieDifficulty::Easy);
});

test('l\'appartenance à une saga retenue rapproche du plus facile sans dépasser very_easy', function (): void {
    $ladder = derivationLadder();
    $collection = Collection::factory()->create();
    // Saga non publiée : elle est « retenue » dès qu'elle existe.
    Theme::factory()->saga($collection->id)->unpublished()->create();

    // Rang 6 (décile 7, easy) et rang 9 (décile 10) dans la saga.
    $ladder[6]->forceFill(['collection_id' => $collection->id])->save();
    $ladder[9]->forceFill(['collection_id' => $collection->id])->save();
    // Une collection qu'aucune saga ne désigne ne donne rien.
    $ladder[2]->forceFill(['collection_id' => Collection::factory()->create()->id])->save();

    deriveDifficulty();

    expect(derivedOf($ladder[6]))->toBe(MovieDifficulty::VeryEasy)
        ->and(derivedOf($ladder[9]))->toBe(MovieDifficulty::VeryEasy)
        ->and(derivedOf($ladder[2]))->toBe(MovieDifficulty::Hard);

    // Le bonus à 9 porte même le moins voté au décile 10, jamais au-delà.
    $ladder[0]->forceFill(['collection_id' => $collection->id])->save();
    Config::set('catalog.difficulty.saga_decile_bonus', 9);
    deriveDifficulty();

    expect(derivedOf($ladder[0]))->toBe(MovieDifficulty::VeryEasy)
        ->and(MovieDifficultyDeriver::classFor(min(10, 10 + 9)))->toBe(MovieDifficulty::VeryEasy);
});

test('l\'override prime et survit à une nouvelle dérivation', function (): void {
    $ladder = derivationLadder();
    $ladder[9]->forceFill([
        'movie_difficulty_override' => MovieDifficulty::VeryHard,
        'movie_difficulty' => MovieDifficulty::VeryHard,
    ])->save();

    deriveDifficulty();

    $fresh = Movie::query()->findOrFail($ladder[9]->id);

    expect($fresh->movie_difficulty_derived)->toBe(MovieDifficulty::VeryEasy)
        ->and($fresh->movie_difficulty_override)->toBe(MovieDifficulty::VeryHard)
        ->and($fresh->movie_difficulty)->toBe(MovieDifficulty::VeryHard);

    // Une population qui change : la dérivée bouge, l'override tient.
    derivationMovie(5_000);
    derivationMovie(6_000);
    deriveDifficulty();

    $fresh->refresh();

    expect($fresh->movie_difficulty_derived)->toBe(MovieDifficulty::Easy)
        ->and($fresh->movie_difficulty_override)->toBe(MovieDifficulty::VeryHard)
        ->and($fresh->movie_difficulty)->toBe(MovieDifficulty::VeryHard);
});

test('un film de démonstration n\'est ni compté ni recalculé', function (): void {
    $ladder = derivationLadder();
    $demo = Movie::factory()->demo()->create([
        'vote_count' => 999_999,
        'original_language' => 'en',
        'movie_difficulty' => MovieDifficulty::Hard,
        'movie_difficulty_derived' => MovieDifficulty::Hard,
    ]);

    deriveDifficulty();

    // Le film le plus voté reste au décile 10 : la démonstration n'est pas comptée.
    expect(derivedOf($ladder[9]))->toBe(MovieDifficulty::VeryEasy)
        ->and(derivedOf($ladder[0]))->toBe(MovieDifficulty::VeryHard)
        ->and(derivedOf($demo))->toBe(MovieDifficulty::Hard)
        ->and(Movie::query()->findOrFail($demo->id)->movie_difficulty)->toBe(MovieDifficulty::Hard);
});

test('un film retiré n\'entre pas dans la distribution', function (): void {
    $ladder = derivationLadder();
    $withdrawn = Movie::factory()->withdrawn()->create([
        'vote_count' => 50,
        'original_language' => 'en',
        'movie_difficulty' => null,
        'movie_difficulty_derived' => null,
    ]);

    deriveDifficulty();

    // Compté, le film retiré aurait poussé le moins voté du seau au décile 2.
    expect(derivedOf($ladder[1]))->toBe(MovieDifficulty::VeryHard)
        ->and(derivedOf($ladder[2]))->toBe(MovieDifficulty::Hard)
        ->and(derivedOf($withdrawn))->toBeNull();
});

test('un changement de difficulté effective met à jour les thèmes de difficulté dans la même transaction', function (): void {
    $ladder = derivationLadder();
    $veryEasy = Theme::factory()->difficulty(MovieDifficulty::VeryEasy)->create();

    $levels = [];

    DB::listen(static function ($query) use (&$levels): void {
        if (str_starts_with(strtolower($query->sql), 'update "movie" set "movie_difficulty_derived"')) {
            $levels['movie'][] = DB::transactionLevel();
        }

        if (str_starts_with(strtolower($query->sql), 'insert into "movie_theme"')) {
            $levels['movie_theme'][] = DB::transactionLevel();
        }
    });

    deriveDifficulty();

    expect(MovieTheme::query()->where('theme_id', $veryEasy->id)->where('is_active', true)->orderBy('movie_id')->pluck('movie_id')->all())
        ->toBe([$ladder[8]->id, $ladder[9]->id]);

    // L'appartenance s'écrit pendant que la transaction du film est ouverte.
    expect($levels['movie_theme'])->toHaveCount(2);

    foreach ($levels['movie_theme'] as $level) {
        expect($level)->toBeGreaterThanOrEqual(max($levels['movie']));
    }

    // Le film qui quitte very_easy quitte le thème.
    derivationMovie(10_000);
    derivationMovie(20_000);
    deriveDifficulty();

    expect(MovieTheme::query()->where('theme_id', $veryEasy->id)->where('is_active', true)->pluck('movie_id')->all())
        ->not->toContain($ladder[8]->id);
});

test('deux dérivations successives sur les mêmes données donnent le même résultat', function (): void {
    derivationLadder('en');
    derivationLadder('fr', 7);

    expect(deriveDifficulty())->toBe(20);

    $first = DB::table('movie')->orderBy('id')->get(['id', 'movie_difficulty', 'movie_difficulty_derived', 'updated_at'])->all();

    expect(deriveDifficulty())->toBe(0)
        ->and(DB::table('movie')->orderBy('id')->get(['id', 'movie_difficulty', 'movie_difficulty_derived', 'updated_at'])->all())->toEqual($first);
});

test('la dérivation refuse une configuration de difficulté hors bornes', function (string $key, mixed $value): void {
    derivationLadder();
    Config::set('catalog.difficulty.'.$key, $value);

    expect(static fn (): int => deriveDifficulty())->toThrow(InvalidArgumentException::class);
    expect(Movie::query()->whereNotNull('movie_difficulty_derived')->exists())->toBeFalse();
})->with([
    'échantillon nul' => ['min_language_sample', 0],
    'échantillon négatif' => ['min_language_sample', -3],
    'bonus négatif' => ['saga_decile_bonus', -1],
    'bonus au-delà de 9' => ['saga_decile_bonus', 10],
    'bonus non entier' => ['saga_decile_bonus', '2'],
]);

test('une correction manuelle concurrente de la dérivation n\'est jamais écrasée', function (): void {
    $ladder = derivationLadder();
    $target = $ladder[9];

    // La correction tombe APRÈS la lecture initiale de la dérivation, juste
    // avant son écriture : la dérivation ne l'a jamais vue.
    $corrected = false;

    Movie::retrieved(static function (Movie $movie) use ($target, &$corrected): void {
        if ($corrected || $movie->id !== $target->id) {
            return;
        }

        $corrected = true;

        DB::table('movie')->where('id', $target->id)->update([
            'movie_difficulty_override' => MovieDifficulty::Hard->value,
            'movie_difficulty' => MovieDifficulty::Hard->value,
        ]);
    });

    deriveDifficulty();

    $fresh = Movie::query()->findOrFail($target->id);

    expect($corrected)->toBeTrue()
        ->and($fresh->movie_difficulty_derived)->toBe(MovieDifficulty::VeryEasy)
        ->and($fresh->movie_difficulty_override)->toBe(MovieDifficulty::Hard)
        ->and($fresh->movie_difficulty)->toBe(MovieDifficulty::Hard);
});

/**
 * @return object{calls: list<int>}
 */
function derivationSnapshotDouble(int $exitCode): object
{
    $journal = new class
    {
        /** @var list<int> films à difficulté dérivée au moment de l'instantané */
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
                throw new LogicException('catalog:themes ne passe jamais --if-pending.');
            }

            $this->journal->calls[] = Movie::query()->whereNotNull('movie_difficulty_derived')->count();

            return $this->exitCode;
        }
    };

    app(ConsoleKernel::class)->registerCommand($command);

    return $journal;
}

test('catalog:themes prend un instantané vérifié avant d\'écrire dans movie et n\'écrit rien sans lui', function (): void {
    derivationLadder();

    $failed = derivationSnapshotDouble(1);
    $this->artisan('catalog:themes')->assertFailed();

    expect($failed->calls)->toBe([0])
        ->and(Movie::query()->whereNotNull('movie_difficulty_derived')->exists())->toBeFalse();

    $succeeded = derivationSnapshotDouble(0);
    $this->artisan('catalog:themes')->assertSuccessful();

    // L'instantané précède la dérivation, qui écrit ensuite les dix films.
    expect($succeeded->calls)->toBe([0])
        ->and(Movie::query()->whereNotNull('movie_difficulty_derived')->count())->toBe(10);
});

test('catalog:themes dérive avant d\'évaluer les thèmes de difficulté', function (): void {
    derivationSnapshotDouble(0);
    $ladder = derivationLadder();
    $veryEasy = Theme::factory()->difficulty(MovieDifficulty::VeryEasy)->create();

    $this->artisan('catalog:themes')->assertSuccessful();

    expect(MovieTheme::query()->where('theme_id', $veryEasy->id)->where('is_active', true)->orderBy('movie_id')->pluck('movie_id')->all())
        ->toBe([$ladder[8]->id, $ladder[9]->id]);
});

test('l\'insertion d\'une saga par le seeder relance la dérivation', function (): void {
    Queue::fake();

    $this->seed(PlatformDataSeeder::class);

    Queue::assertPushed(DeriveMovieDifficulty::class, 1);

    // Rejoué, le seeder n'insère rien : aucune dérivation.
    Queue::fake();
    $this->seed(PlatformDataSeeder::class);

    Queue::assertNotPushed(DeriveMovieDifficulty::class);
});

test('le job dérive tout le catalogue sur la file par défaut', function (): void {
    $ladder = derivationLadder();

    $job = new DeriveMovieDifficulty;

    expect($job->queue)->toBeNull()
        ->and($job->uniqueId())->toBe((new DeriveMovieDifficulty)->uniqueId());

    dispatch($job);

    expect(derivedOf($ladder[9]))->toBe(MovieDifficulty::VeryEasy);
});
