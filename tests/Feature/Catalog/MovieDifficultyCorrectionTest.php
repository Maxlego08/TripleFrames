<?php

use App\Actions\Curation\CorrectMovieDifficulty;
use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Models\AdminAction;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Catalog\MovieImporter;
use App\Support\Catalog\ThemeEvaluator;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Difficulté corrigée — spec 20 § 9.6, L20-28b ; spec 30 § 13.1, § 14.2
|--------------------------------------------------------------------------
|
| La correction écrit `movie_difficulty_override`, puis, dans la même
| transaction, la valeur effective et les thèmes de difficulté du film.
| Elle ne relance jamais la dérivation, survit au réimport et se
| journalise `movie.difficulty_corrected` quand elle change.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/** La ligne `movie_theme` active du film pour ce thème, ou `null`. */
function difficultyMembership(Movie $movie, Theme $theme): ?MovieTheme
{
    return MovieTheme::query()
        ->where('movie_id', $movie->id)
        ->where('theme_id', $theme->id)
        ->where('is_active', true)
        ->first();
}

test('une difficulté corrigée survit au réimport et resynchronise les thèmes de difficulté dans la même transaction', function (): void {
    $hard = Theme::factory()->difficulty(MovieDifficulty::Hard)->create();
    $easy = Theme::factory()->difficulty(MovieDifficulty::Easy)->create();

    app(MovieImporter::class)->import(
        TmdbMovie::fromArray(TmdbFixture::array('movie-987654')),
        ImportRun::factory()->discover()->create(),
        ImportFilter::default(),
    );

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();
    $movie->forceFill([
        'movie_difficulty_derived' => MovieDifficulty::Easy,
        'movie_difficulty' => MovieDifficulty::Easy,
    ])->save();
    app(ThemeEvaluator::class)->syncMovie($movie, ThemeKind::Difficulty);

    expect(difficultyMembership($movie, $easy))->not->toBeNull();

    // Une MÊME transaction : un geste dont la ligne de journal échoue (un
    // compte sans nom réel ne signe rien) n'écrit ni la correction, ni la
    // valeur effective, ni l'appartenance aux thèmes de difficulté.
    $unsigned = User::factory()->curator()->create();
    $unsigned->forceFill(['real_name' => null])->saveQuietly();

    expect(fn () => app(CorrectMovieDifficulty::class)->handle($movie, $unsigned, MovieDifficulty::Hard))
        ->toThrow(LogicException::class);

    $movie->refresh();

    expect($movie->movie_difficulty_override)->toBeNull()
        ->and($movie->movie_difficulty)->toBe(MovieDifficulty::Easy)
        ->and(difficultyMembership($movie, $hard))->toBeNull()
        ->and(difficultyMembership($movie, $easy))->not->toBeNull();

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', $movie))
        ->patch(route('admin.catalog.difficulty.update', $movie), ['movie_difficulty_override' => 'hard'])
        ->assertRedirect(route('admin.catalog.show', $movie))
        ->assertSessionHasNoErrors();

    $movie->refresh();

    expect($movie->movie_difficulty_override)->toBe(MovieDifficulty::Hard)
        ->and($movie->movie_difficulty)->toBe(MovieDifficulty::Hard)
        // La dérivation n'est jamais relancée par la correction.
        ->and($movie->movie_difficulty_derived)->toBe(MovieDifficulty::Easy)
        ->and(difficultyMembership($movie, $hard))->not->toBeNull()
        ->and(difficultyMembership($movie, $easy))->toBeNull();

    // Le réimport (resynchronisation) ne touche jamais la correction.
    app(MovieImporter::class)->import(
        TmdbMovie::fromArray(TmdbFixture::array('movie-987654-resynced')),
        ImportRun::factory()->state(['run_kind' => ImportRunKind::Resync])->running()->create(),
        ImportFilter::default(),
    );

    $movie->refresh();

    expect($movie->movie_difficulty_override)->toBe(MovieDifficulty::Hard)
        ->and($movie->movie_difficulty)->toBe(MovieDifficulty::Hard)
        ->and(difficultyMembership($movie, $hard))->not->toBeNull();
});

test('retirer la correction rend la main à la valeur dérivée', function (): void {
    $easy = Theme::factory()->difficulty(MovieDifficulty::Easy)->create();

    $movie = Movie::factory()->create([
        'movie_difficulty_derived' => MovieDifficulty::Easy,
        'movie_difficulty_override' => MovieDifficulty::VeryHard,
        'movie_difficulty' => MovieDifficulty::VeryHard,
    ]);

    $this->actingAs($this->curator)
        ->patch(route('admin.catalog.difficulty.update', $movie), ['movie_difficulty_override' => ''])
        ->assertRedirect(route('admin.catalog.show', $movie))
        ->assertSessionHasNoErrors();

    $movie->refresh();

    expect($movie->movie_difficulty_override)->toBeNull()
        ->and($movie->movie_difficulty)->toBe(MovieDifficulty::Easy)
        ->and(difficultyMembership($movie, $easy))->not->toBeNull();

    /** @var AdminAction $line */
    $line = AdminAction::query()->where('action', AdminActionType::MovieDifficultyCorrected->value)->sole();

    expect($line->subject_id)->toBe($movie->id)
        ->and($line->details?->values)->toBe(['from' => 'very_hard', 'to' => null]);
});

test('une correction inchangée n\'écrit rien au journal', function (): void {
    $movie = Movie::factory()->create([
        'movie_difficulty_override' => MovieDifficulty::Medium,
        'movie_difficulty' => MovieDifficulty::Medium,
    ]);

    $this->actingAs($this->curator)
        ->patch(route('admin.catalog.difficulty.update', $movie), ['movie_difficulty_override' => 'medium'])
        ->assertRedirect(route('admin.catalog.show', $movie));

    expect(AdminAction::query()->count())->toBe(0);
});

test('une valeur hors de la liste ou un envoi sans le champ est refusé', function (): void {
    $movie = Movie::factory()->create(['movie_difficulty_override' => MovieDifficulty::Medium]);

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', $movie))
        ->patch(route('admin.catalog.difficulty.update', $movie), ['movie_difficulty_override' => 'impossible'])
        ->assertSessionHasErrors('movie_difficulty_override');

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', $movie))
        ->patch(route('admin.catalog.difficulty.update', $movie), [])
        ->assertSessionHasErrors('movie_difficulty_override');

    expect($movie->refresh()->movie_difficulty_override)->toBe(MovieDifficulty::Medium);
});

test('un film retiré ne se corrige pas', function (): void {
    $movie = Movie::factory()->withdrawn()->create();

    $this->actingAs($this->curator)
        ->patch(route('admin.catalog.difficulty.update', $movie), ['movie_difficulty_override' => 'hard'])
        ->assertForbidden();
});
