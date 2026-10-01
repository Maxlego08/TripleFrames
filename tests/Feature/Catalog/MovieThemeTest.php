<?php

use App\Actions\Curation\SetMovieThemeMembership;
use App\Enums\ContentAvailability;
use App\Enums\ThemeMembershipState;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Bloc « Thèmes » de la fiche film — spec 20 § 9.6, spec 30 § 13.1
|--------------------------------------------------------------------------
|
| D43 du 01/10. Un curateur ajoute un film à tout thème, publié ou non, l'en
| retire, ou annule l'exception pour rendre la main à la règle. L'exception
| (`manual_state`, `assigned_by_id`, `assigned_at`) n'a qu'un écrivain,
| `SetMovieThemeMembership::apply()`, qui relit la ligne sous verrou et
| rejoue une fois une collision d'insertion ; `is_active` passe toujours par
| `MovieTheme::resolveIsActive()`. Le collage avec thèmes (tranche F) passe
| par la même porte, sans jamais changer un `removed` en `added` ni figer
| une ligne déjà active par la règle (critique C6).
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/**
 * Le geste d'appartenance, posté depuis la fiche du film.
 */
function movieThemePatch(Movie $movie, Theme $theme, ?string $state, ?User $actor = null): TestResponse
{
    return test()
        ->actingAs($actor ?? test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch(route('admin.catalog.themes.update', ['movie' => $movie->id]), [
            'theme_id' => $theme->id,
            'manual_state' => $state,
        ]);
}

/** La ligne d'appartenance du film pour ce thème, ou `null`. */
function movieThemeRow(Movie $movie, Theme $theme): ?MovieTheme
{
    return MovieTheme::query()
        ->where('movie_id', $movie->id)
        ->where('theme_id', $theme->id)
        ->first();
}

test('un curateur ajoute un thème non publié et le film y devient actif', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->unpublished()->create(['rule_value' => null]);

    movieThemePatch($movie, $theme, 'added')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $row = movieThemeRow($movie, $theme);

    expect($row)->not->toBeNull()
        ->and($row?->is_auto)->toBeFalse()
        ->and($row?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and($row?->is_active)->toBeTrue();
});

test('un curateur ajoute plusieurs thèmes au film en une seule requête', function (): void {
    $movie = Movie::factory()->create();
    $themes = Theme::factory()->count(3)->unpublished()->create(['rule_value' => null]);

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch(route('admin.catalog.themes.update', ['movie' => $movie->id]), [
            'theme_ids' => $themes->pluck('id')->all(),
            'manual_state' => 'added',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $rows = MovieTheme::query()
        ->where('movie_id', $movie->id)
        ->orderBy('theme_id')
        ->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('theme_id')->all())->toBe($themes->pluck('id')->sort()->values()->all())
        ->and($rows->every(fn (MovieTheme $row): bool => $row->manual_state === ThemeMembershipState::Added))
        ->toBeTrue()
        ->and($rows->every(fn (MovieTheme $row): bool => $row->is_active && $row->assigned_by_id === $this->curator->id))
        ->toBeTrue();
});

test('la sélection multiple est réservée à l’ajout et refuse les doublons', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create(['rule_value' => null]);
    $route = route('admin.catalog.themes.update', ['movie' => $movie->id]);

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch($route, [
            'theme_ids' => [$theme->id],
            'manual_state' => 'removed',
        ])
        ->assertSessionHasErrors('theme_ids');

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch($route, [
            'theme_ids' => [$theme->id, $theme->id],
            'manual_state' => 'added',
        ])
        ->assertSessionHasErrors('theme_ids.1');

    expect(MovieTheme::query()->count())->toBe(0);
});

test('un curateur retire un thème automatique et le film y devient inactif', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->auto()->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);

    movieThemePatch($movie, $theme, 'removed')->assertSessionHasNoErrors();

    $row = movieThemeRow($movie, $theme);

    expect($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($row?->is_active)->toBeFalse();
});

test('annuler une exception rend la main à la règle', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
        'is_auto' => true,
        'manual_state' => ThemeMembershipState::Removed,
        'is_active' => false,
        'assigned_by_id' => $this->curator->id,
        'assigned_at' => now(),
    ]);

    movieThemePatch($movie, $theme, null)->assertSessionHasNoErrors();

    $row = movieThemeRow($movie, $theme);

    expect($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBeNull()
        ->and($row?->is_active)->toBeTrue()
        ->and($row?->assigned_by_id)->toBeNull()
        ->and($row?->assigned_at)->toBeNull();
});

test('annuler une exception sur un thème non automatique supprime la ligne', function (): void {
    $theme = Theme::factory()->create(['rule_value' => null]);
    $movie = Movie::factory()->create();
    MovieTheme::factory()->manualAdded($this->curator)->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
        'is_auto' => false,
    ]);

    movieThemePatch($movie, $theme, null)->assertSessionHasNoErrors();

    expect(movieThemeRow($movie, $theme))->toBeNull();
});

test('l\'exception est signée du curateur et horodatée', function (): void {
    $this->freezeSecond();
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create();

    movieThemePatch($movie, $theme, 'added')->assertSessionHasNoErrors();

    $row = movieThemeRow($movie, $theme);

    expect($row?->assigned_by_id)->toBe($this->curator->id)
        ->and($row?->assigned_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());
});

test('le champ manual_state doit être présent : un envoi qui ne le nomme pas ne vaut jamais annulation', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create(['rule_value' => null]);
    MovieTheme::factory()->manualAdded($this->curator)->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch(route('admin.catalog.themes.update', ['movie' => $movie->id]), ['theme_id' => $theme->id])
        ->assertSessionHasErrors('manual_state');

    expect(movieThemeRow($movie, $theme)?->manual_state)->toBe(ThemeMembershipState::Added);
});

test('un thème inexistant ou un état hors liste est refusé sans écriture', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create();

    $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch(route('admin.catalog.themes.update', ['movie' => $movie->id]), [
            'theme_id' => $theme->id + 1000,
            'manual_state' => 'added',
        ])
        ->assertSessionHasErrors('theme_id');

    movieThemePatch($movie, $theme, 'maybe')->assertSessionHasErrors('manual_state');

    expect(MovieTheme::query()->count())->toBe(0);
});

test('un film retiré juridiquement refuse tout geste de thème', function (): void {
    $movie = Movie::factory()->withdrawn()->create();
    $theme = Theme::factory()->create();

    movieThemePatch($movie, $theme, 'added')->assertForbidden();

    expect(MovieTheme::query()->count())->toBe(0);
});

test('un film retiré entre la garde et le verrou est refusé sous verrou', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create();

    // La garde de route a vu un film curable ; le retrait arrive avant le
    // verrou : l'action relit `curate` sous verrou et refuse.
    Movie::query()->whereKey($movie->id)->update(['availability' => ContentAvailability::Withdrawn->value]);

    expect(fn () => app(SetMovieThemeMembership::class)->handle($this->curator, $movie, $theme, ThemeMembershipState::Added))
        ->toThrow(AuthorizationException::class);

    expect(MovieTheme::query()->count())->toBe(0);
});

test('un joueur ne pose aucune exception', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create();

    movieThemePatch($movie, $theme, 'added', User::factory()->create())->assertForbidden();

    expect(MovieTheme::query()->count())->toBe(0);
});

test('une collision d\'insertion avec l\'évaluateur est rejouée une fois sans perdre la règle', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();

    // L'évaluateur crée la ligne automatique entre la lecture verrouillée du
    // geste et son insertion.
    $fired = false;

    DB::listen(static function (QueryExecuted $query) use (&$fired, $movie, $theme): void {
        if ($fired || preg_match('/from [`"]movie_theme[`"]/', $query->sql) !== 1 || ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
            return;
        }

        $fired = true;

        DB::table('movie_theme')->insert([
            'movie_id' => $movie->id,
            'theme_id' => $theme->id,
            'is_auto' => true,
            'manual_state' => null,
            'is_active' => true,
            'assigned_by_id' => null,
            'assigned_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $outcome = app(SetMovieThemeMembership::class)->handle($this->curator, $movie, $theme, ThemeMembershipState::Removed);

    $row = movieThemeRow($movie, $theme);

    expect($fired)->toBeTrue()
        ->and($outcome)->toBe(SetMovieThemeMembership::REMOVED)
        ->and(MovieTheme::query()->count())->toBe(1)
        ->and($row?->is_auto)->toBeTrue()
        ->and($row?->manual_state)->toBe(ThemeMembershipState::Removed)
        ->and($row?->is_active)->toBeFalse();
});

test('le collage ne change jamais un removed en added', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->create([
        'movie_id' => $movie->id,
        'theme_id' => $theme->id,
        'is_auto' => true,
        'manual_state' => ThemeMembershipState::Removed,
        'is_active' => false,
        'assigned_by_id' => $this->curator->id,
        'assigned_at' => now()->subDay(),
    ]);
    $before = movieThemeRow($movie, $theme)?->getAttributes();
    $paster = User::factory()->curator()->create();

    $outcome = DB::transaction(fn (): string => app(SetMovieThemeMembership::class)
        ->applyPasteAddition($movie, $theme, $paster->id, CarbonImmutable::now()));

    expect($outcome)->toBe(SetMovieThemeMembership::PASTE_KEPT_REMOVED)
        ->and(movieThemeRow($movie, $theme)?->getAttributes())->toBe($before);
});

test('le collage ne touche pas une ligne déjà active par la seule règle', function (): void {
    $theme = Theme::factory()->genre(16)->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->auto()->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);
    $before = movieThemeRow($movie, $theme)?->getAttributes();

    $outcome = DB::transaction(fn (): string => app(SetMovieThemeMembership::class)
        ->applyPasteAddition($movie, $theme, $this->curator->id, CarbonImmutable::now()));

    expect($outcome)->toBe(SetMovieThemeMembership::PASTE_ALREADY_ACTIVE)
        ->and(movieThemeRow($movie, $theme)?->getAttributes())->toBe($before)
        ->and(movieThemeRow($movie, $theme)?->manual_state)->toBeNull();
});

test('le collage ajoute un film absent du thème, signé de l\'auteur du collage', function (): void {
    $theme = Theme::factory()->create(['rule_value' => null]);
    $movie = Movie::factory()->create();

    $outcome = DB::transaction(fn (): string => app(SetMovieThemeMembership::class)
        ->applyPasteAddition($movie, $theme, $this->curator->id, CarbonImmutable::now()));

    $row = movieThemeRow($movie, $theme);

    expect($outcome)->toBe(SetMovieThemeMembership::PASTE_APPLIED)
        ->and($row?->manual_state)->toBe(ThemeMembershipState::Added)
        ->and($row?->is_active)->toBeTrue()
        ->and($row?->assigned_by_id)->toBe($this->curator->id);
});

test('la fiche expose chaque appartenance avec son thème, sa nature et sa publication', function (): void {
    $theme = Theme::factory()->genre(16)->unpublished()->create();
    $movie = Movie::factory()->withGenre(16)->create();
    MovieTheme::factory()->auto()->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('themes', 1)
            ->where('themes.0.theme_id', $theme->id)
            ->where('themes.0.key', $theme->key)
            ->where('themes.0.kind', 'genre')
            ->where('themes.0.is_published', false)
            ->where('themes.0.is_auto', true)
            ->where('themes.0.manual_state', null)
            ->where('themes.0.is_active', true)
            ->where('available_themes', fn ($themes): bool => collect($themes)->contains('id', $theme->id))
            ->where('abilities.editThemes', true));
});

test('le bouton saga n\'apparaît que pour une collection sans thème', function (): void {
    $free = Collection::factory()->named('Collection libre')->create();
    $designated = Collection::factory()->named('Collection désignée')->create();
    $saga = Theme::factory()->saga($designated->id)->create();

    $withoutSaga = Movie::factory()->inCollection($free)->create();
    $withSaga = Movie::factory()->inCollection($designated)->create();
    $alone = Movie::factory()->create(['collection_id' => null]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $withoutSaga))
        ->assertInertia(fn (Assert $page) => $page
            ->where('collection.id', $free->id)
            ->where('collection.name', 'Collection libre')
            ->where('collection.saga', null));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $withSaga))
        ->assertInertia(fn (Assert $page) => $page
            ->where('collection.id', $designated->id)
            ->where('collection.saga.id', $saga->id)
            ->where('collection.saga.key', $saga->key));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $alone))
        ->assertInertia(fn (Assert $page) => $page->where('collection', null));

    // Le lien ouvre l'écran des thèmes pré-rempli.
    $this->actingAs($this->curator)
        ->get(route('admin.themes.index', ['create' => 'saga', 'collection_id' => $free->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('prefill.kind', 'saga')
            ->where('prefill.collection_id', $free->id));
});

test('la fiche expose les thèmes disponibles en nombre de requêtes constant', function (): void {
    $collection = Collection::factory()->create();
    $movie = Movie::factory()->inCollection($collection)->create();
    // Un thème dès le départ : sans aucun thème, Eloquent saute le
    // chargement des libellés, et l'écart mesurerait l'amorçage, pas le volume.
    Theme::factory()->create();

    $this->actingAs($this->curator);

    DB::enableQueryLog();
    $this->get(route('admin.catalog.show', $movie))->assertOk();
    $few = count(DB::getQueryLog());

    foreach (range(1, 6) as $index) {
        $theme = Theme::factory()->create();
        MovieTheme::factory()->manualAdded($this->curator)->create(['movie_id' => $movie->id, 'theme_id' => $theme->id]);
    }

    Theme::factory()->count(4)->create();
    Theme::factory()->saga($collection->id)->create();

    DB::flushQueryLog();
    $this->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('themes', 6)
            ->has('available_themes', 12)
            ->whereNot('collection.saga', null));
    $many = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($many)->toBe($few);
});

test('le filtre thème de la liste ne garde que les films actifs dans le thème', function (): void {
    $theme = Theme::factory()->create(['rule_value' => null]);
    $active = Movie::factory()->create(['title_original' => 'Dans le thème']);
    $removed = Movie::factory()->create(['title_original' => 'Retiré du thème']);
    Movie::factory()->create(['title_original' => 'Hors thème']);

    MovieTheme::factory()->manualAdded($this->curator)->create(['movie_id' => $active->id, 'theme_id' => $theme->id]);
    MovieTheme::factory()->manualRemoved($this->curator)->create(['movie_id' => $removed->id, 'theme_id' => $theme->id]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['theme_id' => $theme->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.theme_id', $theme->id)
            ->has('movies.data', 1)
            ->where('movies.data.0.id', $active->id)
            ->where('theme_options', fn ($options): bool => collect($options)->contains('id', $theme->id)));
});
