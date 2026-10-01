<?php

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ImportRunKind;
use App\Enums\ThemeMembershipState;
use App\Enums\UserRole;
use App\Jobs\Catalog\PreviewCatalogPaste;
use App\Models\AdminAction;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use App\Support\Catalog\MovieImporter;
use App\Support\Tmdb\TmdbMovie;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TmdbFixture;

/*
|--------------------------------------------------------------------------
| Thèmes choisis au collage — spec 20 § 3.3, spec 30 § 13.1, D43 du 01/10
|--------------------------------------------------------------------------
|
| La sélection est portée par `import_run.added_theme_ids`, écrite à
| l'ouverture et relue par la commande — y compris à la reprise. Chaque film
| importé ou déjà présent reçoit l'exception `added`, signée de l'auteur du
| collage, sauf un `removed` de curateur (jamais changé) et une ligne déjà
| active (jamais touchée). Aucun appel TMDB réel : fixtures seulement.
|
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    Sleep::fake();

    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Config::set('catalog.import.requests_per_second', 1000);

    Http::fake([
        '*themoviedb.org/3/movie/987654*' => pasteThemesJson('movie-987654'),
        '*themoviedb.org/3/movie/987655*' => pasteThemesJson('movie-987655-minimal'),
        '*themoviedb.org/3/movie/987660*' => pasteThemesJson('movie-987660-adult'),
    ]);

    $this->curator = User::factory()->curator()->create();
});

afterEach(function (): void {
    Sleep::fake(false);
});

function pasteThemesJson(string $fixture): mixed
{
    return Http::response(TmdbFixture::json($fixture), 200, ['Content-Type' => 'application/json']);
}

/** Un thème sans règle : seule l'exception manuelle y fait entrer un film. */
function pasteManualTheme(): Theme
{
    return Theme::factory()->create(['rule_value' => null]);
}

/**
 * Un collage ouvert comme le back-office l'ouvre, puis repris par la commande
 * comme le job la lance.
 *
 * @param  list<int>  $tmdbIds
 * @param  list<int>  $themeIds
 */
function pasteWithThemes(User $actor, array $tmdbIds, array $themeIds): ImportRun
{
    $run = ImportLauncher::open(ImportRunKind::Paste, ImportFilter::default(), $actor->id, $themeIds);

    Artisan::call('catalog:import-ids', [
        'ids' => array_map(strval(...), $tmdbIds),
        '--resume' => true,
        '--run' => $run->id,
    ]);

    return $run->refresh();
}

function pasteMembership(int $tmdbId, Theme $theme): ?MovieTheme
{
    return MovieTheme::query()
        ->where('theme_id', $theme->id)
        ->where('movie_id', Movie::query()->where('tmdb_id', $tmdbId)->value('id'))
        ->first();
}

test('les thèmes choisis au collage sont ajoutés aux films importés dans la transaction de leur import', function (): void {
    // La même transaction : une écriture de thème qui échoue annule le film.
    $failing = pasteManualTheme();
    MovieTheme::creating(function (MovieTheme $row) use ($failing): void {
        if ($row->theme_id === $failing->id) {
            throw new RuntimeException('panne simulée de l\'exception');
        }
    });

    expect(fn () => pasteWithThemes($this->curator, [987654], [$failing->id]))
        ->toThrow(RuntimeException::class);

    expect(Movie::query()->where('tmdb_id', 987654)->exists())->toBeFalse();

    $first = pasteManualTheme();
    $second = pasteManualTheme();

    $run = pasteWithThemes($this->curator, [987654, 987655], [$first->id, $second->id]);

    foreach ([987654, 987655] as $tmdbId) {
        foreach ([$first, $second] as $theme) {
            $row = pasteMembership($tmdbId, $theme);

            expect($row)->not->toBeNull()
                ->and($row?->manual_state)->toBe(ThemeMembershipState::Added)
                ->and($row?->is_auto)->toBeFalse()
                ->and($row?->is_active)->toBeTrue();
        }
    }

    expect($run->total_imported)->toBe(2)
        ->and($run->total_themes_applied)->toBe(4)
        ->and($run->total_themes_kept_removed)->toBe(0);
});

test('les thèmes choisis au collage sont ajoutés aux films déjà présents', function (): void {
    $theme = pasteManualTheme();
    $existing = Movie::factory()->create(['tmdb_id' => 987654]);

    $run = pasteWithThemes($this->curator, [987654], [$theme->id]);

    $row = pasteMembership(987654, $theme);

    expect($row?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and($row?->is_active)->toBeTrue()
        ->and($run->total_skipped)->toBe(1)
        ->and($run->total_themes_applied)->toBe(1)
        ->and(Movie::query()->count())->toBe(1)
        ->and($existing->refresh()->import_run_id)->toBeNull();

    // Un doublon ne coûte aucun appel de détail.
    Http::assertNothingSent();
});

test('aucun thème n\'est appliqué à un film refusé par le filtre de contenu ni à un film retiré', function (): void {
    $theme = pasteManualTheme();
    $withdrawn = Movie::factory()->withdrawn()->create(['tmdb_id' => 987654]);

    $run = pasteWithThemes($this->curator, [987660, 987654], [$theme->id]);

    expect(Movie::query()->where('tmdb_id', 987660)->exists())->toBeFalse()
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse()
        ->and(MovieTheme::query()->where('movie_id', $withdrawn->id)->exists())->toBeFalse()
        ->and($run->total_refused_content)->toBe(1)
        ->and($run->total_themes_applied)->toBe(0);
});

test('l\'exception ajoutée est signée de l\'auteur du collage', function (): void {
    $theme = pasteManualTheme();
    Movie::factory()->create(['tmdb_id' => 987655]);

    pasteWithThemes($this->curator, [987654, 987655], [$theme->id]);

    foreach ([987654, 987655] as $tmdbId) {
        $row = pasteMembership($tmdbId, $theme);

        expect($row?->assigned_by_id)->toBe($this->curator->id)
            ->and($row?->assigned_at)->not->toBeNull();
    }

    // Le geste est le collage, journalisé à son ouverture par le back-office :
    // aucune ligne `movie.theme_set` par film (spec 20 § 2.7).
    expect(AdminAction::query()->where('action', AdminActionType::MovieThemeSet->value)->exists())->toBeFalse();
});

test('un removed posé par un curateur n\'est jamais changé en added, et il est compté à part', function (): void {
    $theme = pasteManualTheme();
    $other = User::factory()->curator()->create();
    $movie = Movie::factory()->create(['tmdb_id' => 987654]);

    MovieTheme::factory()->notAuto()->manualRemoved($other)->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
    ]);

    $run = pasteWithThemes($this->curator, [987654], [$theme->id]);

    $row = pasteMembership(987654, $theme);

    expect($row?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($row?->is_active)->toBeFalse()
        ->and($row?->assigned_by_id)->toBe($other->id)
        ->and($run->total_themes_applied)->toBe(0)
        ->and($run->total_themes_kept_removed)->toBe(1);
});

test('une ligne déjà active par la règle n\'est pas touchée par le collage', function (): void {
    $theme = Theme::factory()->decade(1990)->create();
    $movie = Movie::factory()->create(['tmdb_id' => 987654, 'release_year' => 1995]);

    MovieTheme::factory()->auto()->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
    ]);

    $run = pasteWithThemes($this->curator, [987654], [$theme->id]);

    $row = pasteMembership(987654, $theme);

    // Aucun `added` superflu : une correction ultérieure de la règle doit
    // pouvoir en sortir le film.
    expect($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBeNull()
        ->and($row?->assigned_by_id)->toBeNull()
        ->and($row?->is_active)->toBeTrue()
        ->and($run->total_themes_applied)->toBe(0)
        ->and($run->total_themes_kept_removed)->toBe(0);
});

test('une simulation ou un aperçu n\'applique aucun thème', function (): void {
    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');

    $theme = pasteManualTheme();
    Movie::factory()->create(['tmdb_id' => 987654]);

    // L'aperçu à blanc ignore la sélection : ni balayage ni appartenance.
    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => '987654', 'theme_ids' => [$theme->id]])
        ->assertSessionHasNoErrors();

    Bus::assertDispatched(PreviewCatalogPaste::class);

    // La simulation en console non plus : elle n'ouvre aucun balayage, donc
    // ne porte aucune sélection, et n'écrit rien sur un doublon.
    Artisan::call('catalog:import-ids', ['ids' => ['987654', '987655'], '--dry-run' => true]);

    expect(ImportRun::query()->count())->toBe(0)
        ->and(MovieTheme::query()->count())->toBe(0)
        ->and(Movie::query()->where('tmdb_id', 987655)->exists())->toBeFalse();
});

test('la reprise d\'un collage relit ses thèmes depuis le run', function (): void {
    $theme = pasteManualTheme();

    // Un collage interrompu après son premier identifiant : la commande de
    // reprise ne reçoit que la liste — les thèmes, elle les lit sur la ligne.
    $run = ImportLauncher::open(ImportRunKind::Paste, ImportFilter::default(), $this->curator->id, [$theme->id]);
    $run->forceFill(['total_seen' => 1, 'total_skipped' => 1])->save();

    Artisan::call('catalog:import-ids', [
        'ids' => ['987654', '987655'],
        '--resume' => true,
        '--run' => $run->id,
    ]);

    expect(pasteMembership(987655, $theme)?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and(Movie::query()->where('tmdb_id', 987654)->exists())->toBeFalse()
        ->and($run->refresh()->added_theme_ids)->toBe((string) $theme->id)
        ->and($run->total_themes_applied)->toBe(1);
});

test('un balayage discover n\'applique aucun thème', function (): void {
    $theme = pasteManualTheme();

    // Le lanceur n'écrit la sélection que sur un collage.
    $opened = ImportLauncher::open(ImportRunKind::Discover, ImportFilter::default(), $this->curator->id, [$theme->id]);

    expect($opened->added_theme_ids)->toBeNull();

    // Et l'importeur l'ignore hors collage, même posée à la main.
    $run = ImportRun::factory()->discover()->create(['added_theme_ids' => (string) $theme->id]);

    app(MovieImporter::class)->import(
        TmdbMovie::fromArray(TmdbFixture::array('movie-987654')),
        $run,
        ImportFilter::default(),
    );

    expect(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue()
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse();
});

test('un thème inexistant est refusé', function (): void {
    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');

    $theme = pasteManualTheme();

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550', 'theme_ids' => [$theme->id, 999_999]])
        ->assertSessionHasErrors('theme_ids.1');

    expect(ImportRun::query()->count())->toBe(0)
        ->and(AdminAction::query()->count())->toBe(0);
});

test('au-delà du plafond de thèmes le collage est refusé', function (): void {
    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');
    Config::set('catalog.import.paste_max_themes', 2);

    $ids = [pasteManualTheme()->id, pasteManualTheme()->id, pasteManualTheme()->id];

    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550', 'theme_ids' => $ids])
        ->assertSessionHasErrors('theme_ids');

    expect(ImportRun::query()->count())->toBe(0);

    // Au plafond exactement, le collage passe.
    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => '550', 'theme_ids' => array_slice($ids, 0, 2)])
        ->assertSessionHasNoErrors();

    expect(ImportRun::query()->sole()->addedThemeIds())->toBe(array_slice($ids, 0, 2));
});

test('un auteur supprimé ou rétrogradé en cours de collage ne pose aucune exception', function (): void {
    $theme = pasteManualTheme();
    Movie::factory()->create(['tmdb_id' => 987655]);

    // La policy `curate` est relue à chaque écriture, pour l'auteur du
    // collage : rétrogradé en joueur, il ne signe plus rien.
    $run = ImportLauncher::open(ImportRunKind::Paste, ImportFilter::default(), $this->curator->id, [$theme->id]);
    $this->curator->forceFill(['role' => UserRole::Player])->save();

    Artisan::call('catalog:import-ids', ['ids' => ['987654', '987655'], '--resume' => true, '--run' => $run->id]);

    expect(Movie::query()->where('tmdb_id', 987654)->exists())->toBeTrue()
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse()
        ->and($run->refresh()->total_themes_applied)->toBe(0);

    // Supprimé : `actor_id` passe à NULL, et aucune exception n'est posée
    // sans signature.
    $orphan = ImportRun::factory()->paste()->create([
        'actor_id' => null,
        'added_theme_ids' => (string) $theme->id,
    ]);

    app(MovieImporter::class)->applyRunThemesToExisting(Movie::query()->where('tmdb_id', 987655)->sole(), $orphan);

    expect(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse()
        ->and($orphan->total_themes_applied)->toBe(0);
});

test('un film retiré après la déduplication ne reçoit aucun thème', function (): void {
    $theme = pasteManualTheme();
    $movie = Movie::factory()->create(['tmdb_id' => 987655]);
    $run = ImportLauncher::open(ImportRunKind::Paste, ImportFilter::default(), $this->curator->id, [$theme->id]);

    // Le modèle lu par la déduplication est périmé : le film a été retiré
    // entre-temps, et c'est la ligne relue sous verrou qui décide.
    Movie::query()->whereKey($movie->id)->update([
        'availability' => ContentAvailability::Withdrawn->value,
        'availability_changed_at' => now(),
        'availability_reason' => 'Retrait prononcé sur mise en demeure.',
    ]);

    app(MovieImporter::class)->applyRunThemesToExisting($movie, $run);

    expect(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse()
        ->and($run->total_themes_applied)->toBe(0);
});
