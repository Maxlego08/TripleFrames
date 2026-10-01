<?php

use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Enums\ThemeMembershipState;
use App\Enums\TmdbTagKind;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Catalog\ThemeEvaluator;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| L'évaluateur d'appartenance film ↔ thème — spec 30 § 12.1 et § 13 (L30-8)
|--------------------------------------------------------------------------
|
| Une règle par nature, lue localement ; la négation et le nul selon la
| sémantique normative ; une seule écriture, idempotente, qui relit
| l'exception manuelle sous verrou et ne l'écrit jamais.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function themeEvaluator(): ThemeEvaluator
{
    return app(ThemeEvaluator::class);
}

function themeMembership(Movie $movie, Theme $theme): ?MovieTheme
{
    return MovieTheme::query()->where('movie_id', $movie->id)->where('theme_id', $theme->id)->first();
}

/**
 * Les lignes `movie_theme`, triées, sans identifiant ni horodatage.
 *
 * @return list<array<string, mixed>>
 */
function themeRows(): array
{
    return DB::table('movie_theme')
        ->orderBy('movie_id')
        ->orderBy('theme_id')
        ->get(['movie_id', 'theme_id', 'is_auto', 'manual_state', 'is_active', 'assigned_by_id', 'assigned_at'])
        ->map(static fn (object $row): array => array_map(
            static fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value,
            (array) $row,
        ))
        ->all();
}

it('un thème de genre ou de studio s\'évalue sur movie_tmdb_tag sans appel réseau', function (): void {
    $genre = Theme::factory()->genre(16)->create();
    $studio = Theme::factory()->studio(10342)->create();

    $animated = Movie::factory()->withGenre(16)->withCompany(10342)->create();
    $other = Movie::factory()->withGenre(18)->withCompany(7)->create();

    themeEvaluator()->syncMovie($animated);
    themeEvaluator()->syncMovie($other);

    expect(themeMembership($animated, $genre)?->is_active)->toBeTrue()
        ->and(themeMembership($animated, $studio)?->is_active)->toBeTrue()
        ->and(themeMembership($other, $genre))->toBeNull()
        ->and(themeMembership($other, $studio))->toBeNull();
});

it('un thème studio contient les films de l\'une quelconque de ses sociétés', function (): void {
    $dc = Theme::factory()->studio([429, 128064, 184898])->create();

    expect($dc->rule_value)->toBe('429,128064,184898');

    $old = Movie::factory()->withCompany(429)->create();
    $recent = Movie::factory()->withCompany(184898)->create();
    $marvel = Movie::factory()->withCompany(420)->create();

    expect(themeEvaluator()->syncTheme($dc))->toBe(2)
        ->and(themeMembership($old, $dc)?->is_active)->toBeTrue()
        ->and(themeMembership($recent, $dc)?->is_active)->toBeTrue()
        ->and(themeMembership($marvel, $dc))->toBeNull();

    // La négation s'applique à l'union.
    $notDc = Theme::factory()->studio([429, 184898])->negated()->create();

    themeEvaluator()->syncTheme($notDc);

    expect(themeMembership($marvel, $notDc)?->is_active)->toBeTrue()
        ->and(themeMembership($old, $notDc))->toBeNull()
        ->and(themeMembership($recent, $notDc))->toBeNull();
});

it('une règle studio mal formée ne désigne aucune société, niée ou non', function (): void {
    $broken = Theme::factory()->create(['theme_kind' => ThemeKind::Studio, 'rule_value' => '420, 429']);
    $brokenNegated = Theme::factory()->create(['theme_kind' => ThemeKind::Studio, 'rule_value' => '420,,429', 'rule_negated' => true]);

    $movie = Movie::factory()->withCompany(420)->create();

    themeEvaluator()->syncMovie($movie);

    expect(themeMembership($movie, $broken))->toBeNull()
        ->and(themeMembership($movie, $brokenNegated))->toBeNull();
});

it('un thème de décennie couvre les dix années qui commencent à rule_value', function (): void {
    $nineties = Theme::factory()->decade(1990)->create();

    $first = Movie::factory()->create(['release_year' => 1990]);
    $last = Movie::factory()->create(['release_year' => 1999]);
    $next = Movie::factory()->create(['release_year' => 2000]);
    $before = Movie::factory()->create(['release_year' => 1989]);

    themeEvaluator()->syncTheme($nineties);

    expect(themeMembership($first, $nineties)?->is_active)->toBeTrue()
        ->and(themeMembership($last, $nineties)?->is_active)->toBeTrue()
        ->and(themeMembership($next, $nineties))->toBeNull()
        ->and(themeMembership($before, $nineties))->toBeNull();
});

it('un thème de langue compare original_language à l\'identique', function (): void {
    $anime = Theme::factory()->language('ja')->create();

    $japanese = Movie::factory()->create(['original_language' => 'ja']);
    $upper = Movie::factory()->create(['original_language' => 'JA']);

    themeEvaluator()->syncTheme($anime);

    expect(themeMembership($japanese, $anime)?->is_active)->toBeTrue()
        ->and(themeMembership($upper, $anime))->toBeNull();
});

it('un thème de saga compare collection_id à rule_value', function (): void {
    $toyStory = Collection::factory()->named('Toy Story Collection')->create();
    $other = Collection::factory()->create();
    $saga = Theme::factory()->saga($toyStory->id)->create();

    $inSaga = Movie::factory()->inCollection($toyStory)->create();
    $elsewhere = Movie::factory()->inCollection($other)->create();
    $alone = Movie::factory()->create();

    themeEvaluator()->syncTheme($saga);

    expect(themeMembership($inSaga, $saga)?->is_active)->toBeTrue()
        ->and(themeMembership($elsewhere, $saga))->toBeNull()
        ->and(themeMembership($alone, $saga))->toBeNull();
});

it('un thème de difficulté suit la difficulté effective', function (): void {
    $hard = Theme::factory()->difficulty(MovieDifficulty::Hard)->create();

    $overridden = Movie::factory()->create([
        'movie_difficulty' => MovieDifficulty::Hard,
        'movie_difficulty_derived' => MovieDifficulty::Easy,
        'movie_difficulty_override' => MovieDifficulty::Hard,
    ]);
    $derivedHard = Movie::factory()->create([
        'movie_difficulty' => MovieDifficulty::Easy,
        'movie_difficulty_derived' => MovieDifficulty::Hard,
        'movie_difficulty_override' => MovieDifficulty::Easy,
    ]);

    themeEvaluator()->syncMovie($overridden, ThemeKind::Difficulty);
    themeEvaluator()->syncMovie($derivedHard, ThemeKind::Difficulty);

    expect(themeMembership($overridden, $hard)?->is_active)->toBeTrue()
        ->and(themeMembership($derivedHard, $hard))->toBeNull();
});

it('rule_negated inverse la règle, jamais sur une entrée nulle', function (): void {
    $international = Theme::factory()->language('en')->negated()->create();
    $notNineties = Theme::factory()->decade(1990)->negated()->create();
    $notSaga = Theme::factory()->saga(Collection::factory()->create()->id)->negated()->create();
    $notHard = Theme::factory()->difficulty(MovieDifficulty::Hard)->negated()->create();
    $notAnimation = Theme::factory()->genre(16)->negated()->create();

    $french = Movie::factory()->create(['original_language' => 'fr', 'release_year' => 2005, 'movie_difficulty' => MovieDifficulty::Easy]);
    $unknown = Movie::factory()->create([
        'original_language' => 'en',
        'release_year' => null,
        'collection_id' => null,
        'movie_difficulty' => null,
        'movie_difficulty_derived' => null,
    ]);

    themeEvaluator()->syncMovie($french);
    themeEvaluator()->syncMovie($unknown);

    expect(themeMembership($french, $international)?->is_active)->toBeTrue()
        ->and(themeMembership($french, $notNineties)?->is_active)->toBeTrue()
        ->and(themeMembership($french, $notHard)?->is_active)->toBeTrue()
        // Un film sans étiquette a un ensemble vide : la négation s'applique.
        ->and(themeMembership($french, $notAnimation)?->is_active)->toBeTrue()
        ->and(themeMembership($unknown, $international))->toBeNull()
        // Année, collection et difficulté inconnues : jamais satisfaites, niées ou non.
        ->and(themeMembership($unknown, $notNineties))->toBeNull()
        ->and(themeMembership($unknown, $notSaga))->toBeNull()
        ->and(themeMembership($unknown, $notHard))->toBeNull();
});

it('un thème sans rule_value ne contient que ses ajouts manuels, même nié', function (): void {
    $manual = Theme::factory()->create(['theme_kind' => ThemeKind::Saga, 'rule_value' => null]);
    $manualNegated = Theme::factory()->create(['theme_kind' => ThemeKind::Studio, 'rule_value' => null, 'rule_negated' => true]);

    $added = Movie::factory()->create();
    $other = Movie::factory()->create();

    MovieTheme::factory()->manualAdded(User::factory()->create())->create([
        'movie_id' => $added->id,
        'theme_id' => $manual->id,
        'is_auto' => false,
    ]);

    themeEvaluator()->syncTheme($manual);
    themeEvaluator()->syncTheme($manualNegated);

    expect(themeMembership($added, $manual)?->is_active)->toBeTrue()
        ->and(themeMembership($added, $manual)?->is_auto)->toBeFalse()
        ->and(themeMembership($other, $manual))->toBeNull()
        ->and(MovieTheme::query()->where('theme_id', $manualNegated->id)->count())->toBe(0);
});

it('une exception manuelle prime sur la règle par resolveIsActive', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $curator = User::factory()->create();

    $removed = Movie::factory()->withGenre(16)->create();
    $added = Movie::factory()->withGenre(18)->create();

    MovieTheme::factory()->manualRemoved($curator)->create(['movie_id' => $removed->id, 'theme_id' => $theme->id, 'is_auto' => true]);
    MovieTheme::factory()->manualAdded($curator)->create(['movie_id' => $added->id, 'theme_id' => $theme->id, 'is_auto' => false]);

    themeEvaluator()->syncTheme($theme);

    expect(themeMembership($removed, $theme)?->is_auto)->toBeTrue()
        ->and(themeMembership($removed, $theme)?->is_active)->toBeFalse()
        ->and(themeMembership($added, $theme)?->is_auto)->toBeFalse()
        ->and(themeMembership($added, $theme)?->is_active)->toBeTrue();
});

it('une ligne automatique qui cesse de matcher est supprimée, une exception removed est conservée', function (): void {
    $theme = Theme::factory()->decade(1990)->create();
    $curator = User::factory()->create();

    $leaving = Movie::factory()->create(['release_year' => 1995]);
    $removed = Movie::factory()->create(['release_year' => 1995]);

    themeEvaluator()->syncTheme($theme);

    MovieTheme::query()->where('movie_id', $removed->id)->update([
        'manual_state' => ThemeMembershipState::Removed->value,
        'is_active' => false,
        'assigned_by_id' => $curator->id,
        'assigned_at' => now(),
    ]);

    $leaving->forceFill(['release_year' => 2005])->save();
    $removed->forceFill(['release_year' => 2005])->save();

    themeEvaluator()->syncTheme($theme);

    expect(themeMembership($leaving, $theme))->toBeNull()
        ->and(themeMembership($removed, $theme)?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and(themeMembership($removed, $theme)?->is_auto)->toBeFalse()
        ->and(themeMembership($removed, $theme)?->is_active)->toBeFalse();
});

it('une exception ajoutée reste active quand la règle cesse de correspondre', function (): void {
    $theme = Theme::factory()->language('ja')->create();
    $movie = Movie::factory()->create(['original_language' => 'ja']);

    themeEvaluator()->syncMovie($movie);

    MovieTheme::query()->where('movie_id', $movie->id)->update(['manual_state' => ThemeMembershipState::Added->value]);

    $movie->forceFill(['original_language' => 'ko'])->save();
    themeEvaluator()->syncMovie($movie);

    expect(themeMembership($movie, $theme)?->is_auto)->toBeFalse()
        ->and(themeMembership($movie, $theme)?->is_active)->toBeTrue();
});

it('une resynchronisation réécrit is_auto sans toucher manual_state', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $curator = User::factory()->create();
    $movie = Movie::factory()->withGenre(18)->create();

    $row = MovieTheme::factory()->manualAdded($curator)->create(['movie_id' => $movie->id, 'theme_id' => $theme->id, 'is_auto' => false]);
    $assignedAt = $row->fresh()?->assigned_at;

    themeEvaluator()->syncMovie($movie, tagKeys: [ThemeEvaluator::tagKey(TmdbTagKind::Genre, 16) => true]);

    $after = themeMembership($movie, $theme);

    expect($after?->is_auto)->toBeTrue()
        ->and($after?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and($after?->assigned_by_id)->toBe($curator->id)
        ->and($after?->assigned_at?->equalTo($assignedAt))->toBeTrue();
});

it('l\'évaluateur n\'écrit jamais l\'exception manuelle ni son auteur', function (): void {
    Theme::factory()->genre(16)->create();
    Theme::factory()->decade(1990)->create();
    $movie = Movie::factory()->withGenre(16)->create(['release_year' => 1994]);

    themeEvaluator()->syncMovie($movie);

    expect(MovieTheme::query()->count())->toBe(2)
        ->and(MovieTheme::query()->whereNotNull('manual_state')->count())->toBe(0)
        ->and(MovieTheme::query()->whereNotNull('assigned_by_id')->count())->toBe(0)
        ->and(MovieTheme::query()->whereNotNull('assigned_at')->count())->toBe(0);
});

it('un thème non publié est évalué et sa publication ne demande aucun recalcul', function (): void {
    $theme = Theme::factory()->genre(16)->unpublished()->create();
    $movie = Movie::factory()->withGenre(16)->create();

    themeEvaluator()->syncMovie($movie);

    expect(themeMembership($movie, $theme)?->is_active)->toBeTrue();

    $theme->is_published = true;
    $theme->save();

    // Rejouée après la publication, l'évaluation n'a rien à changer.
    expect(themeEvaluator()->syncTheme($theme))->toBe(0);
});

it('l\'évaluation par film et l\'évaluation par thème produisent les mêmes lignes', function (): void {
    $collection = Collection::factory()->create();

    $themes = [
        Theme::factory()->genre(16)->create(),
        Theme::factory()->studio([3, 10342])->create(),
        Theme::factory()->decade(1990)->create(),
        Theme::factory()->saga($collection->id)->create(),
        Theme::factory()->language('en')->negated()->create(),
        Theme::factory()->difficulty(MovieDifficulty::Hard)->create(),
    ];

    $movies = [
        Movie::factory()->withGenre(16)->withCompany(3)->inCollection($collection)->create(['release_year' => 1995, 'original_language' => 'en']),
        Movie::factory()->withGenre(18)->withCompany(10342)->create(['release_year' => 2001, 'original_language' => 'ja', 'movie_difficulty' => MovieDifficulty::Hard]),
        Movie::factory()->create(['release_year' => null, 'original_language' => 'fr', 'movie_difficulty' => null]),
    ];

    foreach ($movies as $movie) {
        themeEvaluator()->syncMovie($movie);
    }

    $byMovie = themeRows();

    MovieTheme::query()->delete();

    foreach ($themes as $theme) {
        themeEvaluator()->syncTheme($theme);
    }

    expect(themeRows())->toBe($byMovie)
        ->and($byMovie)->not->toBe([]);
});

it('réévaluer deux fois ne change rien', function (): void {
    Theme::factory()->genre(16)->create();
    Theme::factory()->decade(1990)->create();
    Movie::factory()->withGenre(16)->count(3)->create(['release_year' => 1993]);

    $first = 0;

    foreach (Theme::query()->get() as $theme) {
        $first += themeEvaluator()->syncTheme($theme);
    }

    $rows = themeRows();
    $second = 0;

    foreach (Theme::query()->get() as $theme) {
        $second += themeEvaluator()->syncTheme($theme);
    }

    expect($first)->toBe(6)
        ->and($second)->toBe(0)
        ->and(themeRows())->toBe($rows);
});

it('la synchronisation d\'un thème ne touche que ses propres lignes', function (): void {
    $animation = Theme::factory()->genre(16)->create();
    $drama = Theme::factory()->genre(18)->create();
    $movie = Movie::factory()->withGenre(18)->create();

    // Une ligne périmée de l'AUTRE thème : seule sa propre synchronisation la retire.
    MovieTheme::factory()->auto()->create(['movie_id' => $movie->id, 'theme_id' => $animation->id]);

    themeEvaluator()->syncTheme($drama);

    expect(themeMembership($movie, $drama)?->is_active)->toBeTrue()
        ->and(themeMembership($movie, $animation))->not->toBeNull();
});

it('une écriture de l\'évaluateur relit l\'exception sous verrou et ne réactive jamais un film retiré', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $curator = User::factory()->create();
    $movie = Movie::factory()->withGenre(16)->create();

    $row = MovieTheme::factory()->notAuto()->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);

    // Le geste du curateur se glisse APRÈS la lecture des films par
    // `syncTheme()` et AVANT son écriture : la mise à jour perdue du § 13.1.
    $fired = false;

    DB::listen(static function (QueryExecuted $query) use (&$fired, $row, $curator): void {
        if ($fired || ! str_contains($query->sql, 'from "movie_tmdb_tag"')) {
            return;
        }

        $fired = true;

        DB::table('movie_theme')->where('id', $row->id)->update([
            'manual_state' => ThemeMembershipState::Removed->value,
            'is_active' => false,
            'assigned_by_id' => $curator->id,
            'assigned_at' => now(),
        ]);
    });

    themeEvaluator()->syncTheme($theme);

    $after = themeMembership($movie, $theme);

    expect($fired)->toBeTrue()
        ->and($after?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($after?->is_auto)->toBeTrue()
        ->and($after?->is_active)->toBeFalse();
});

it('une collision d\'insertion sur movie_theme_uq est rejouée une fois sans perdre l\'exception', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $curator = User::factory()->create();
    $movie = Movie::factory()->withGenre(16)->create();

    // Un chemin concurrent crée la ligne (un retrait) entre la lecture
    // verrouillée et l'insertion de l'évaluateur.
    $fired = false;

    DB::listen(static function (QueryExecuted $query) use (&$fired, $movie, $theme, $curator): void {
        if ($fired || ! str_contains($query->sql, 'from "movie_theme"') || ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
            return;
        }

        $fired = true;

        DB::table('movie_theme')->insert([
            'movie_id' => $movie->id,
            'theme_id' => $theme->id,
            'is_auto' => false,
            'manual_state' => ThemeMembershipState::Removed->value,
            'is_active' => false,
            'assigned_by_id' => $curator->id,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    themeEvaluator()->syncMovie($movie);

    $after = themeMembership($movie, $theme);

    expect($fired)->toBeTrue()
        ->and(MovieTheme::query()->count())->toBe(1)
        ->and($after?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($after?->is_auto)->toBeTrue()
        ->and($after?->is_active)->toBeFalse();
});

it('le seeder de démonstration délègue à l\'évaluateur unique', function (): void {
    $source = (string) file_get_contents(database_path('seeders/DemoCatalogueSeeder.php'));

    expect($source)->toContain('ThemeEvaluator::class)->syncMovie(')
        ->not->toContain('function ruleMatches')
        ->not->toContain('function linkThemes')
        ->not->toContain('new MovieTheme');
});
