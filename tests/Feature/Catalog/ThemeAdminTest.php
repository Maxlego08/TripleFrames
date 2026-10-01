<?php

use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Jobs\Catalog\SyncThemeMembership;
use App\Models\AdminAction;
use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Models\TmdbCompany;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Admin\ThemeDesignation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Draw\PoolFixtures;

/*
|--------------------------------------------------------------------------
| Écran des thèmes du back-office — spec 20 § 9.6, ligne 28 (D43 du 01/10)
|--------------------------------------------------------------------------
|
| Chaque thème, publié ou non, avec sa règle résolue en noms et son nombre
| d'œuvres (le thème seul au `N` par défaut, `PoolReporter::themeWorks()`).
| Trois gestes : créer un thème de toute nature créable ou sans règle, la
| valeur de la règle prise parmi ce qui est présent au catalogue ; corriger
| règle, négation, libellés et ordre, jamais la nature ni la clé ; publier
| sous seuil, dépublier toujours. Les refus sont des erreurs traduites,
| jamais des 403. La file est `sync` dans la suite : un `SyncThemeMembership`
| envoyé après commit calcule réellement les appartenances.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/**
 * Un geste de l'écran, posté depuis l'écran.
 *
 * @param  array<string, mixed>  $payload
 * @param  array<string, int>  $parameters
 */
function themeAdminSend(string $method, string $route, array $payload, array $parameters = []): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.themes.index'))
        ->{$method}(route($route, $parameters), $payload);
}

/**
 * Une création valide, champs donnés en sus des libellés.
 *
 * @param  array<string, mixed>  $fields
 */
function themeAdminStore(array $fields, string $english = 'Science fiction', string $french = 'Science-fiction'): TestResponse
{
    return themeAdminSend('post', 'admin.themes.store', [
        'labels' => ['fr' => $french, 'en' => $english],
        ...$fields,
    ]);
}

/**
 * Une correction valide : l'état complet du thème, champs donnés en sus.
 *
 * @param  array<string, mixed>  $fields
 */
function themeAdminUpdate(Theme $theme, array $fields): TestResponse
{
    $theme->load('labels');

    $labels = [];

    foreach (Locale::cases() as $locale) {
        $labels[$locale->value] = $theme->labels->first(fn (ThemeLabel $label): bool => $label->locale === $locale)?->label ?? 'Libellé';
    }

    return themeAdminSend('patch', 'admin.themes.update', [
        'labels' => $labels,
        'sort_order' => $theme->sort_order,
        'rule_value' => $theme->rule_value,
        'rule_negated' => $theme->rule_negated,
        ...$fields,
    ], ['theme' => $theme->id]);
}

/** Le texte d'une clé du back-office, résolu en français, la clé vérifiée d'abord. */
function themeAdminText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

/**
 * `$count` films jouables, chacun une œuvre, membres actifs du thème.
 *
 * @return list<Movie>
 */
function themeAdminMembers(Theme $theme, int $count): array
{
    PoolFixtures::fakeFramesDisk();
    $movies = PoolFixtures::movies($count);

    foreach ($movies as $movie) {
        PoolFixtures::member($movie, $theme);
    }

    return $movies;
}

test('l’écran liste chaque thème avec son nombre d’œuvres, publié ou non', function (): void {
    TmdbCompany::factory()->create(['tmdb_id' => 420, 'name' => 'Marvel Studios']);
    $studio = Theme::factory()->unpublished()->studio(420)->create();
    $published = Theme::factory()->published()->create();
    themeAdminMembers($studio, 2);

    // Un film non jouable reste un film actif, jamais une œuvre.
    MovieTheme::factory()->for(Movie::factory()->create())->for($studio)->auto()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.themes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/themes/index')
            ->has('themes', 2)
            ->where('kinds', ['genre', 'decade', 'studio', 'saga', 'language'])
            ->where('publication.min_works', RoomSettingsBounds::DEFAULT_ROUNDS_COUNT)
            ->where('publication.frames_per_round', RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND)
            ->where('abilities.create', true)
            ->missing('rule_options')
            ->where('prefill', null)
            ->where('themes', fn ($themes): bool => collect($themes)->contains(
                fn (array $theme): bool => $theme['id'] === $studio->id
                    && $theme['works'] === 2
                    && $theme['active_films'] === 3
                    && $theme['is_published'] === false
                    && $theme['company_ids'] === [420]
                    && $theme['rule_items'] === [['value' => '420', 'name' => 'Marvel Studios']]
                    && $theme['abilities'] === ['update' => true, 'publish' => true],
            ) && collect($themes)->contains(
                fn (array $theme): bool => $theme['id'] === $published->id
                    && $theme['works'] === 0
                    && $theme['is_published'] === true,
            )));
});

test('un joueur ne voit pas l’écran des thèmes', function (): void {
    $this->actingAs(User::factory()->player()->create())
        ->get(route('admin.themes.index'))
        ->assertForbidden();
});

test('un thème de saga se crée sur une collection importée et naît non publié', function (): void {
    $collection = Collection::factory()->create(['tmdb_id' => 131292, 'name' => 'Iron Man Collection']);
    $movie = Movie::factory()->inCollection($collection)->create();

    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => $collection->id], 'The Iron Man Saga', 'Iron Man')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.themes.index'));

    $theme = Theme::query()->where('theme_kind', ThemeKind::Saga)->sole();

    expect($theme->key)->toBe('saga.iron-man-saga')
        ->and($theme->rule_value)->toBe((string) $collection->id)
        ->and($theme->rule_negated)->toBeFalse()
        ->and($theme->is_published)->toBeFalse()
        ->and($theme->hasEveryLocaleLabel())->toBeTrue()
        // Fin du bloc des sagas, bloc vide : premier rang.
        ->and($theme->sort_order)->toBe(ThemeKind::Saga->sortBlock() * ThemeKind::SORT_BLOCK_WIDTH + 1);

    // La synchronisation, envoyée après commit, a rattaché le film.
    expect(MovieTheme::query()->where('theme_id', $theme->id)->where('movie_id', $movie->id)->value('is_active'))->toBeTrue();
});

test('une saga ne se crée que sur une collection déjà au catalogue', function (): void {
    $absent = (int) Collection::query()->max('id') + 1;

    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => $absent], 'Matrix', 'Matrix')
        ->assertSessionHasErrors('collection_id');

    expect(Theme::query()->where('theme_kind', ThemeKind::Saga)->exists())->toBeFalse();
});

test('un thème studio se crée sur plusieurs sociétés connues et affiche leur nom et leur nombre de films', function (): void {
    TmdbCompany::factory()->create(['tmdb_id' => 429, 'name' => 'DC Comics']);
    $first = Movie::factory()->withCompany(128_064)->create();
    $second = Movie::factory()->withCompany(429)->create();
    Movie::factory()->withCompany(429)->create();

    // Les options de règle, une nature à la fois, au seul rechargement partiel.
    themeAdminOptions('studio')
        ->assertOk()
        ->assertJsonPath('props.rule_options.kind', 'studio')
        ->assertJsonPath('props.rule_options.options', [
            ['value' => '429', 'name' => 'DC Comics', 'films' => 2, 'taken_by' => null],
            ['value' => '128064', 'name' => null, 'films' => 1, 'taken_by' => null],
        ]);

    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [128_064, 429]], 'DC', 'DC')
        ->assertSessionHasNoErrors();

    $theme = Theme::query()->where('key', 'studio.dc')->sole();

    // Forme canonique : triée, sans espace.
    expect($theme->rule_value)->toBe('429,128064')
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->where('is_active', true)->pluck('movie_id')->sort()->values()->all())
        ->toContain($first->id, $second->id);

    themeAdminOptions('studio')
        ->assertJsonPath('props.rule_options.options.0.taken_by', 'studio.dc');

    // Une société absente du catalogue est refusée à la création.
    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [999_001]], 'Absent studio', 'Studio absent')
        ->assertSessionHasErrors(['company_ids' => themeAdminText('admin.themes.company_absent', ['ids' => '999001'])]);

    // Au plus huit sociétés.
    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => range(1, 9)], 'Too many', 'Trop')
        ->assertSessionHasErrors('company_ids');
});

test('la longueur de la liste de sociétés est bornée pour elle-même', function (): void {
    // Huit identifiants à sept chiffres : 63 caractères, la colonne en tient 64.
    $ids = [1_540_901, 1_540_902, 1_540_903, 1_540_904, 1_540_905, 1_540_906, 1_540_907, 1_540_908];
    $theme = Theme::factory()->studio(2)->create();

    themeAdminUpdate($theme, ['company_ids' => $ids])->assertSessionHasNoErrors();

    expect($theme->refresh()->rule_value)->toBe(implode(',', $ids))
        ->and(strlen((string) $theme->rule_value))->toBe(63);
});

test('un thème de genre, de décennie ou de langue se crée avec une règle validée selon sa nature', function (): void {
    Movie::factory()->withGenre(878)->create(['release_year' => 1994, 'original_language' => 'ja']);

    themeAdminStore(['theme_kind' => 'genre', 'rule_value' => 27], 'Horror', 'Horreur')
        ->assertSessionHasErrors(['rule_value' => themeAdminText('admin.themes.genre_absent')]);

    themeAdminStore(['theme_kind' => 'genre', 'rule_value' => 878], 'Sci-fi', 'SF')
        ->assertSessionHasNoErrors();

    themeAdminStore(['theme_kind' => 'decade', 'rule_value' => 1995], 'The 1990s', 'Années 1990')
        ->assertSessionHasErrors(['rule_value' => themeAdminText('admin.themes.decade_invalid')]);

    themeAdminStore(['theme_kind' => 'decade', 'rule_value' => 1990], 'The 1990s', 'Années 1990')
        ->assertSessionHasNoErrors();

    themeAdminStore(['theme_kind' => 'language', 'rule_value' => 'JA'], 'Japanese', 'Japonais')
        ->assertSessionHasErrors(['rule_value' => themeAdminText('admin.themes.language_invalid')]);

    themeAdminStore(['theme_kind' => 'language', 'rule_value' => 'ja', 'rule_negated' => true], 'Not Japanese', 'Hors japonais')
        ->assertSessionHasNoErrors();

    expect(Theme::query()->where('key', 'genre.sci-fi')->value('rule_value'))->toBe('878')
        ->and(Theme::query()->where('key', 'decade.1990s')->value('rule_value'))->toBe('1990')
        ->and(Theme::query()->where('key', 'language.not-japanese')->value('rule_negated'))->toBeTrue();
});

test('un thème sans règle ne contient que ses ajouts manuels', function (): void {
    Movie::factory()->withGenre(878)->count(2)->create();

    themeAdminStore(['theme_kind' => 'genre', 'manual' => true, 'rule_value' => 878], 'Favourites', 'Coups de cœur')
        ->assertSessionHasNoErrors();

    $theme = Theme::query()->where('key', 'genre.favourites')->sole();

    expect($theme->rule_value)->toBeNull()
        ->and(MovieTheme::query()->where('theme_id', $theme->id)->exists())->toBeFalse();

    // Un thème sans règle ne se nie pas.
    themeAdminStore(['theme_kind' => 'genre', 'manual' => true, 'rule_negated' => true], 'Everything', 'Tout')
        ->assertSessionHasErrors(['rule_negated' => themeAdminText('admin.themes.negation_forbidden')]);

    expect(Theme::query()->where('key', 'genre.everything')->exists())->toBeFalse();
});

test('une collection déjà désignée par une saga est refusée', function (): void {
    $collection = Collection::factory()->create();
    $existing = Theme::factory()->saga($collection->id)->create();

    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => $collection->id], 'Another saga', 'Autre saga')
        ->assertSessionHasErrors(['collection_id' => themeAdminText('admin.themes.collection_taken', ['key' => $existing->key])]);

    // Une saga ne se nie pas.
    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => Collection::factory()->create()->id, 'rule_negated' => true], 'Negated saga', 'Saga niée')
        ->assertSessionHasErrors('rule_negated');

    expect(Theme::query()->where('theme_kind', ThemeKind::Saga)->count())->toBe(1);
});

test('une société déjà désignée par un thème studio est refusée, fût-ce dans une liste', function (): void {
    Movie::factory()->withCompany(6125)->create();
    Movie::factory()->withCompany(42)->create();
    $disney = Theme::factory()->studio([2, 6125])->create();

    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [6125]], 'Walt Disney Animation', 'Disney animation')
        ->assertSessionHasErrors(['company_ids' => themeAdminText('admin.themes.company_taken', ['key' => $disney->key])]);

    // 42 n'est pas 420 ni 2 : la liste se découpe, jamais un LIKE.
    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [42]], 'Studio 42', 'Studio 42')
        ->assertSessionHasNoErrors();

    // La correction relit la même garde, hors du thème corrigé.
    $other = Theme::query()->where('key', 'studio.studio-42')->sole();

    themeAdminUpdate($other, ['company_ids' => [42, 2]])
        ->assertSessionHasErrors(['company_ids' => themeAdminText('admin.themes.company_taken', ['key' => $disney->key])]);

    // Corriger un thème sur ses propres sociétés, plus une absente du
    // catalogue, est admis : c'est la correction de Disney.
    themeAdminUpdate($disney, ['company_ids' => [2, 6125, 171_656]])->assertSessionHasNoErrors();

    expect($disney->refresh()->rule_value)->toBe('2,6125,171656');
});

test('une société déjà désignée par un thème studio est refusée, même sous une création simultanée', function (): void {
    Sleep::fake(syncWithCarbon: true);
    Queue::fake();
    Movie::factory()->withCompany(420)->create();

    // Une création concurrente tient le verrou de désignation : la seconde
    // attend, puis renonce en refus traduit, sans rien écrire.
    $held = Cache::lock(ThemeDesignation::LOCK, 10);
    expect($held->get())->toBeTrue();

    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [420]], 'Marvel', 'Marvel')
        ->assertSessionHasErrors(['theme_kind' => themeAdminText('admin.themes.busy')]);

    expect(Theme::query()->exists())->toBeFalse()
        ->and(AdminAction::query()->exists())->toBeFalse();

    // La première a désigné la société pendant ce temps, puis rendu le verrou.
    $first = Theme::factory()->studio(420)->create();
    $held->release();

    // La garde est relue sous le verrou, dans la transaction du geste : la
    // validation de la requête ne la voit pas, l'action la refuse.
    themeAdminStore(['theme_kind' => 'studio', 'company_ids' => [420]], 'Marvel', 'Marvel')
        ->assertSessionHasErrors(['company_ids' => themeAdminText('admin.themes.company_taken', ['key' => $first->key])]);

    expect(Theme::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('la clé est dérivée du libellé anglais sans article et refusée si elle existe', function (): void {
    $collection = Collection::factory()->create();
    $other = Collection::factory()->create();

    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => $collection->id], 'The Lord of the Rings', 'Le Seigneur des Anneaux')
        ->assertSessionHasNoErrors();

    expect(Theme::query()->where('key', 'saga.lord-of-the-rings')->exists())->toBeTrue();

    themeAdminStore(['theme_kind' => 'saga', 'collection_id' => $other->id], 'Lord of the Rings', 'LOTR')
        ->assertSessionHasErrors(['labels.en' => themeAdminText('admin.themes.key_taken', ['key' => 'saga.lord-of-the-rings'])]);

    themeAdminStore(['theme_kind' => 'decade', 'rule_value' => 1980], '!!!', 'Années 80')
        ->assertSessionHasErrors(['labels.en' => themeAdminText('admin.themes.key_invalid')]);

    expect(Theme::query()->count())->toBe(1);
});

test('la nature difficulté ne se crée jamais, en erreur de validation et non en 403', function (): void {
    themeAdminStore(['theme_kind' => 'difficulty', 'rule_value' => 'hard'], 'Hard', 'Difficile')
        ->assertRedirect(route('admin.themes.index'))
        ->assertSessionHasErrors(['theme_kind' => themeAdminText('admin.themes.kind_forbidden')]);

    expect(Theme::query()->exists())->toBeFalse();
});

test('la nature et la clé d’un thème ne se modifient jamais', function (): void {
    $theme = Theme::factory()->genre(878)->create();

    themeAdminUpdate($theme, ['theme_kind' => 'decade', 'rule_value' => 1990])
        ->assertSessionHasErrors(['theme_kind' => themeAdminText('admin.themes.kind_immutable')]);

    themeAdminUpdate($theme, ['key' => 'genre.renamed'])
        ->assertSessionHasErrors(['key' => themeAdminText('admin.themes.key_immutable')]);

    $theme->refresh();

    expect($theme->theme_kind)->toBe(ThemeKind::Genre)
        ->and($theme->key)->toBe('genre.tag-878')
        ->and($theme->rule_value)->toBe('878');
});

test('corriger la règle relance la synchronisation après commit', function (): void {
    $theme = Theme::factory()->genre(878)->create();
    $scifi = Movie::factory()->withGenre(878)->create();
    $horror = Movie::factory()->withGenre(27)->create();
    MovieTheme::factory()->for($scifi)->for($theme)->auto()->create();

    themeAdminUpdate($theme, ['rule_value' => 27])->assertSessionHasNoErrors();

    $active = MovieTheme::query()->where('theme_id', $theme->id)->where('is_active', true)->pluck('movie_id')->all();

    expect($theme->refresh()->rule_value)->toBe('27')
        ->and($active)->toBe([$horror->id]);

    // Une négation changée seule relance aussi ; passer à « sans règle »
    // aussi. Deux thèmes : le verrou d'unicité du job, tenu jusqu'à son
    // traitement, garderait un seul job en attente par thème.
    Queue::fake();
    $negated = Theme::factory()->genre(12)->create();
    $manual = Theme::factory()->genre(14)->create();

    themeAdminUpdate($negated, ['rule_negated' => true])->assertSessionHasNoErrors();
    themeAdminUpdate($manual, ['manual' => true])->assertSessionHasNoErrors();

    expect($negated->refresh()->rule_negated)->toBeTrue()
        ->and($manual->refresh()->rule_value)->toBeNull();

    Queue::assertPushed(SyncThemeMembership::class, fn (SyncThemeMembership $job): bool => $job->themeId === $negated->id);
    Queue::assertPushed(SyncThemeMembership::class, fn (SyncThemeMembership $job): bool => $job->themeId === $manual->id);
});

test('modifier un libellé ou l’ordre seuls ne relance aucune synchronisation', function (): void {
    Queue::fake();
    $theme = Theme::factory()->genre(878)->create();

    themeAdminUpdate($theme, ['labels' => ['fr' => 'Science-fiction', 'en' => 'Science fiction'], 'sort_order' => 42])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message', themeAdminText('admin.themes.flash.updated', ['key' => $theme->key]));

    Queue::assertNotPushed(SyncThemeMembership::class);

    $theme->refresh()->load('labels');

    expect($theme->sort_order)->toBe(42)
        ->and($theme->labels->firstWhere('locale', Locale::French)?->label)->toBe('Science-fiction')
        ->and($theme->labels->firstWhere('locale', Locale::English)?->label)->toBe('Science fiction');
});

test('la publication est refusée sous le seuil d’œuvres et accordée au seuil', function (): void {
    $theme = Theme::factory()->unpublished()->create();
    themeAdminMembers($theme, RoomSettingsBounds::DEFAULT_ROUNDS_COUNT - 1);

    themeAdminSend('post', 'admin.themes.publish', ['is_published' => true], ['theme' => $theme->id])
        ->assertSessionHasErrors(['is_published' => themeAdminText('admin.themes.too_small', [
            'count' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT - 1,
            'min' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
        ])]);

    expect($theme->refresh()->is_published)->toBeFalse();

    themeAdminMembers($theme, 1);

    themeAdminSend('post', 'admin.themes.publish', ['is_published' => true], ['theme' => $theme->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.themes.index'));

    expect($theme->refresh()->is_published)->toBeTrue();
});

test('la publication est refusée s’il manque un libellé', function (): void {
    $theme = Theme::factory()->unpublished()->withoutLabels()->create();
    ThemeLabel::factory()->for($theme)->locale(Locale::French)->create();

    themeAdminSend('post', 'admin.themes.publish', ['is_published' => true], ['theme' => $theme->id])
        ->assertSessionHasErrors(['is_published' => themeAdminText('admin.themes.labels_missing')]);

    expect($theme->refresh()->is_published)->toBeFalse();
});

test('la dépublication est toujours permise', function (): void {
    $theme = Theme::factory()->published()->create();

    themeAdminSend('post', 'admin.themes.publish', ['is_published' => false], ['theme' => $theme->id])
        ->assertSessionHasNoErrors();

    expect($theme->refresh()->is_published)->toBeFalse();
});

test('les options de règle ne sont servies qu’au rechargement partiel', function (): void {
    $collection = Collection::factory()->create(['name' => 'Toy Story Collection']);
    Movie::factory()->inCollection($collection)->count(2)->create();
    Movie::factory()->withGenre(16)->create(['release_year' => 1995, 'original_language' => 'en']);

    $this->actingAs($this->curator)
        ->get(route('admin.themes.index', ['rule_kind' => 'saga']))
        ->assertInertia(fn (Assert $page) => $page->missing('rule_options'));

    themeAdminOptions('saga')
        ->assertJsonPath('props.rule_options.options', [
            ['value' => (string) $collection->id, 'name' => 'Toy Story Collection', 'films' => 2, 'taken_by' => null],
        ]);

    expect(collect(themeAdminOptions('decade')->json('props.rule_options.options'))->pluck('value')->all())->toContain('1990')
        ->and(collect(themeAdminOptions('language')->json('props.rule_options.options'))->pluck('value')->all())->toContain('en');

    themeAdminOptions('genre')->assertJsonPath('props.rule_options.options.0', [
        'value' => '16', 'name' => null, 'films' => 1, 'taken_by' => null,
    ]);

    // Une nature inconnue ne rend rien, sans erreur.
    themeAdminOptions('nope')->assertOk()->assertJsonPath('props.rule_options', null);
});

test('le préremplissage depuis la fiche film est facultatif et jamais une erreur', function (): void {
    $collection = Collection::factory()->create(['name' => 'Iron Man Collection']);

    $this->actingAs($this->curator)
        ->get(route('admin.themes.index', ['create' => 'saga', 'collection_id' => $collection->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('prefill', [
            'kind' => 'saga',
            'collection_id' => $collection->id,
            'collection_name' => 'Iron Man Collection',
        ]));

    foreach ([['create' => 'saga', 'collection_id' => 'abc'], ['create' => 'genre', 'collection_id' => $collection->id], ['collection_id' => 999_999]] as $query) {
        $this->actingAs($this->curator)
            ->get(route('admin.themes.index', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prefill', null));
    }
});

test('un thème créé sans ordre se range en fin de bloc de sa nature', function (): void {
    Theme::factory()->genre(12)->sortedAt(105)->create();
    Theme::factory()->genre(14)->sortedAt(103)->create();
    // Hors bloc : ne compte pas.
    Theme::factory()->genre(16)->sortedAt(40)->create();
    Movie::factory()->withGenre(878)->create();

    themeAdminStore(['theme_kind' => 'genre', 'rule_value' => 878], 'Sci-fi', 'SF')->assertSessionHasNoErrors();
    themeAdminStore(['theme_kind' => 'genre', 'rule_value' => 878, 'sort_order' => 7], 'Sci-fi bis', 'SF bis')->assertSessionHasNoErrors();

    expect(Theme::query()->where('key', 'genre.sci-fi')->value('sort_order'))->toBe(106)
        ->and(Theme::query()->where('key', 'genre.sci-fi-bis')->value('sort_order'))->toBe(7);
});

/**
 * Le rechargement partiel des options de règle d'une nature.
 */
function themeAdminOptions(string $kind): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.themes.index'))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.themes.index', ['rule_kind' => $kind]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/themes/index',
            Header::PARTIAL_ONLY => 'rule_options',
        ]);
}
