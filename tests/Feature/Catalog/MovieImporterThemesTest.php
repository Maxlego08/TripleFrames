<?php

use App\Enums\ImportRunKind;
use App\Enums\ThemeMembershipState;
use App\Models\Collection;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Catalog\MovieImporter;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| L'appartenance aux thèmes à l'import — spec 30 § 13.2 (L30-8)
|--------------------------------------------------------------------------
|
| Synchrone, dans la transaction qui écrit le film, sur les étiquettes de
| l'appel de détail. Aucun réseau : les fiches viennent des fixtures.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function themeImportRun(ImportRunKind $kind): ImportRun
{
    return ImportRun::factory()->create([
        'run_kind' => $kind,
        'is_widened' => false,
        'total_seen' => 0,
        'total_imported' => 0,
        'total_skipped' => 0,
        'total_refused_content' => 0,
    ]);
}

function themeImport(string $fixture, ImportRunKind $kind = ImportRunKind::Discover, bool $dryRun = false): void
{
    app(MovieImporter::class)->import(
        TmdbMovie::fromArray(TmdbFixture::array($fixture)),
        themeImportRun($kind),
        ImportFilter::default(),
        $dryRun,
    );
}

function themeImportRow(Theme $theme): ?MovieTheme
{
    return MovieTheme::query()
        ->where('theme_id', $theme->id)
        ->where('movie_id', Movie::query()->where('tmdb_id', 987654)->value('id'))
        ->first();
}

it('un film importé appartient à ses thèmes dès sa création', function (): void {
    // La collection existe déjà (créée par le seeder de plateforme) : l'import
    // la réutilise, et la saga qui la désigne reçoit le film.
    $collection = Collection::factory()->create(['tmdb_id' => 445566]);

    $animation = Theme::factory()->genre(16)->create();
    $drama = Theme::factory()->genre(18)->create();
    $action = Theme::factory()->genre(28)->create();
    $studio = Theme::factory()->studio([7712, 999_999])->unpublished()->create();
    $nineties = Theme::factory()->decade(1990)->create();
    $anime = Theme::factory()->language('ja')->create();
    $international = Theme::factory()->language('en')->negated()->create();
    $saga = Theme::factory()->saga($collection->id)->unpublished()->create();

    // Les lignes s'écrivent AVANT la projection, dans la transaction du film :
    // aucune fenêtre où le film manquerait au vivier d'un thème qu'il satisfait.
    $order = [];

    DB::listen(static function (QueryExecuted $query) use (&$order): void {
        if (preg_match('/^insert into "(movie_theme|movie_projection)"/i', trim($query->sql), $match) === 1) {
            $order[] = $match[1];
        }
    });

    themeImport('movie-987654');

    expect(array_values(array_unique($order)))->toBe(['movie_theme', 'movie_projection']);

    foreach ([$animation, $drama, $studio, $nineties, $anime, $international, $saga] as $theme) {
        expect(themeImportRow($theme)?->is_active)->toBeTrue($theme->key)
            ->and(themeImportRow($theme)?->is_auto)->toBeTrue($theme->key);
    }

    expect(themeImportRow($action))->toBeNull()
        ->and(MovieTheme::query()->count())->toBe(7);
});

it('la resynchronisation recalcule l\'appartenance et préserve l\'exception manuelle', function (): void {
    $curator = User::factory()->create();
    $animation = Theme::factory()->genre(16)->create();
    $drama = Theme::factory()->genre(18)->create();
    $family = Theme::factory()->genre(10751)->create();
    $studio = Theme::factory()->studio(7712)->create();
    $manual = Theme::factory()->create(['rule_value' => null]);

    themeImport('movie-987654');

    $movie = Movie::query()->where('tmdb_id', 987654)->firstOrFail();

    // Le curateur sort le film du thème « animation » et l'ajoute au thème manuel.
    MovieTheme::query()->where('movie_id', $movie->id)->where('theme_id', $animation->id)->update([
        'manual_state' => ThemeMembershipState::Removed->value,
        'is_active' => false,
        'assigned_by_id' => $curator->id,
        'assigned_at' => now(),
    ]);
    MovieTheme::factory()->manualAdded($curator)->create(['movie_id' => $movie->id, 'theme_id' => $manual->id, 'is_auto' => false]);

    // La fiche relue : genres 16 et 10751, une seule société (7711).
    themeImport('movie-987654-resynced', ImportRunKind::Resync);

    expect(themeImportRow($animation)?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and(themeImportRow($animation)?->is_auto)->toBeTrue()
        ->and(themeImportRow($animation)?->is_active)->toBeFalse()
        ->and(themeImportRow($animation)?->assigned_by_id)->toBe($curator->id)
        ->and(themeImportRow($drama))->toBeNull()
        ->and(themeImportRow($studio))->toBeNull()
        ->and(themeImportRow($family)?->is_active)->toBeTrue()
        ->and(themeImportRow($manual)?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and(themeImportRow($manual)?->is_active)->toBeTrue();
});

it('une simulation n\'écrit aucune appartenance', function (): void {
    Theme::factory()->genre(16)->create();
    Theme::factory()->language('ja')->create();

    themeImport('movie-987654', dryRun: true);

    expect(Movie::query()->count())->toBe(0)
        ->and(MovieTheme::query()->count())->toBe(0);
});

it('l\'évaluation ne lit pas les étiquettes en base à l\'import', function (): void {
    Theme::factory()->genre(16)->create();
    Theme::factory()->studio(7711)->create();

    $reads = 0;

    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        $sql = strtolower(trim($query->sql));

        if (str_starts_with($sql, 'select') && str_contains($sql, 'from "movie_tmdb_tag"')) {
            $reads++;
        }
    });

    themeImport('movie-987654');

    expect($reads)->toBe(0)
        ->and(MovieTheme::query()->where('is_active', true)->count())->toBe(2);
});
