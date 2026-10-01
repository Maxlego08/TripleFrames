<?php

use App\Actions\Curation\SaveMovieTitle;
use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use App\Models\Theme;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Journal des gestes de catalogue — D41 du 30/09
|--------------------------------------------------------------------------
|
| Titres, alias, groupes « même œuvre » et exceptions de thème (D43 du
| 01/10) écrivent désormais leur ligne
| `admin_action`, dans la transaction du geste, signée du nom réel ; un
| refus ou une annulation n'en écrit aucune. `details` garde ce que le
| geste détruit ou écrase en place.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original donné, ses clés projetées, avec un titre
 * français éventuel.
 */
function catalogJournalFilm(string $original, ?string $frenchTitle = null, ContentOrigin $origin = ContentOrigin::Tmdb): Movie
{
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => null,
    ]);

    if ($frenchTitle !== null) {
        MovieTitle::factory()->create([
            'movie_id' => $movie->id,
            'locale' => 'fr',
            'title' => $frenchTitle,
            'origin' => $origin,
        ]);
    }

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * Un geste de catalogue, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function catalogJournalSend(string $method, string $route, array $parameters, array $payload = [], ?User $actor = null): TestResponse
{
    return test()
        ->actingAs($actor ?? test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $parameters['movie']]))
        ->{$method}(route($route, $parameters), $payload);
}

/**
 * La seule ligne du journal pour cette action.
 */
function catalogJournalLine(AdminActionType $action): AdminAction
{
    return AdminAction::query()->where('action', $action->value)->sole();
}

test('corriger un titre écrit movie.title_saved signé du nom réel, avec l\'ancien texte', function (): void {
    $movie = catalogJournalFilm('Sen to Chihiro no Kamikakushi', 'Le Voyage de Chihiro');

    catalogJournalSend('put', 'admin.catalog.titles.update', ['movie' => $movie->id, 'locale' => 'fr'], [
        'title' => 'Le Voyage de Chihiro (2001)',
    ])->assertSessionHasNoErrors();

    $line = catalogJournalLine(AdminActionType::MovieTitleSaved);

    expect($line->actor_id)->toBe($this->curator->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->subject_type)->toBe(AdminActionSubject::Movie)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($line->details?->values)->toBe([
            'locale' => 'fr',
            'before' => 'Le Voyage de Chihiro',
            'before_origin' => 'tmdb',
            'after' => 'Le Voyage de Chihiro (2001)',
        ]);

    // Le même texte renvoyé ne change rien : aucune seconde ligne.
    catalogJournalSend('put', 'admin.catalog.titles.update', ['movie' => $movie->id, 'locale' => 'fr'], [
        'title' => 'Le Voyage de Chihiro (2001)',
    ])->assertSessionHasNoErrors();

    expect(AdminAction::query()->count())->toBe(1);

    // Un titre saisi dans une locale sans ligne : l'avant est nul.
    catalogJournalSend('put', 'admin.catalog.titles.update', ['movie' => $movie->id, 'locale' => 'en'], [
        'title' => 'Spirited Away',
    ])->assertSessionHasNoErrors();

    $created = AdminAction::query()->where('action', AdminActionType::MovieTitleSaved->value)->latest('id')->firstOrFail();

    expect($created->details?->values)->toBe([
        'locale' => 'en',
        'before' => null,
        'before_origin' => null,
        'after' => 'Spirited Away',
    ]);
});

test('une correction de titre annulée n\'écrit aucune ligne', function (): void {
    $movie = catalogJournalFilm('Parasite', 'Parasite');

    // La dernière écriture de la transaction échoue : le titre et la ligne
    // disparaissent ensemble.
    Event::listen('eloquent.saving: '.MovieProjection::class, function (): never {
        throw new RuntimeException('Panne simulée du recalcul de projection.');
    });

    expect(fn () => app(SaveMovieTitle::class)->handle($movie, $this->curator, Locale::French, 'Parasite (2019)'))
        ->toThrow(RuntimeException::class, 'Panne simulée');

    expect(MovieTitle::query()->where('movie_id', $movie->id)->where('locale', 'fr')->value('title'))->toBe('Parasite')
        ->and(AdminAction::query()->count())->toBe(0);
});

test('retirer un titre curé écrit movie.title_removed avec le texte supprimé', function (): void {
    $movie = catalogJournalFilm('Oldeuboi', 'Old Boy', ContentOrigin::Curator);
    $tmdb = catalogJournalFilm('Parasite', 'Parasite');

    // Un titre TMDB ne se retire pas : refus traduit, aucune ligne.
    catalogJournalSend('delete', 'admin.catalog.titles.destroy', ['movie' => $tmdb->id, 'locale' => 'fr'])
        ->assertSessionHasErrors('title');

    expect(AdminAction::query()->count())->toBe(0);

    catalogJournalSend('delete', 'admin.catalog.titles.destroy', ['movie' => $movie->id, 'locale' => 'fr'])
        ->assertSessionHasNoErrors();

    $line = catalogJournalLine(AdminActionType::MovieTitleRemoved);

    expect($line->subject_id)->toBe($movie->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->details?->values)->toBe(['locale' => 'fr', 'title' => 'Old Boy']);
});

test('ajouter puis retirer un alias écrit une ligne pour chaque geste, le texte gardé', function (): void {
    $movie = catalogJournalFilm('Sen to Chihiro no Kamikakushi');

    catalogJournalSend('post', 'admin.catalog.aliases.store', ['movie' => $movie->id], [
        'locale' => 'fr',
        'alias' => 'Chihiro',
    ])->assertSessionHasNoErrors();

    $alias = Alias::query()->where('movie_id', $movie->id)->sole();
    $added = catalogJournalLine(AdminActionType::MovieAliasAdded);

    expect($added->subject_id)->toBe($movie->id)
        ->and($added->details?->values)->toBe(['alias_id' => $alias->id, 'locale' => 'fr', 'alias' => 'Chihiro']);

    catalogJournalSend('delete', 'admin.catalog.aliases.destroy', ['movie' => $movie->id, 'alias' => $alias->id])
        ->assertSessionHasNoErrors();

    $removed = catalogJournalLine(AdminActionType::MovieAliasRemoved);

    expect(Alias::query()->whereKey($alias->id)->exists())->toBeFalse()
        ->and($removed->subject_id)->toBe($movie->id)
        ->and($removed->details?->values)->toBe([
            'alias_id' => $alias->id,
            'locale' => 'fr',
            'alias' => 'Chihiro',
            'origin' => 'curator',
        ]);
});

test('regrouper puis dissoudre écrit une ligne par film dont le groupe change', function (): void {
    $first = catalogJournalFilm('Old Boy');
    $second = catalogJournalFilm('Oldeuboi');

    catalogJournalSend('patch', 'admin.catalog.group.update', ['movie' => $first->id], [
        'with_movie_id' => $second->id,
        'label' => 'Old Boy, deux versions',
    ])->assertSessionHasNoErrors();

    $group = MovieGroup::query()->sole();
    $grouped = AdminAction::query()->where('action', AdminActionType::MovieGrouped->value)->orderBy('id')->get();

    expect($grouped)->toHaveCount(2)
        ->and($grouped->pluck('subject_id')->all())->toBe([$first->id, $second->id])
        ->and($grouped[0]->details?->values)->toBe([
            'outcome' => 'created',
            'group_id' => $group->id,
            'label' => 'Old Boy, deux versions',
            'with_movie_id' => $second->id,
        ])
        ->and($grouped[1]->details?->values['with_movie_id'])->toBe($first->id);

    // Le même regroupement renvoyé ne change rien : aucune ligne.
    catalogJournalSend('patch', 'admin.catalog.group.update', ['movie' => $first->id], [
        'with_movie_id' => $second->id,
    ])->assertSessionHasNoErrors();

    expect(AdminAction::query()->count())->toBe(2);

    // Retirer un film d'un groupe de deux le dissout : deux lignes, le
    // groupe supprimé gardé par `details`.
    catalogJournalSend('patch', 'admin.catalog.group.update', ['movie' => $first->id], ['leave' => true])
        ->assertSessionHasNoErrors();

    $ungrouped = AdminAction::query()->where('action', AdminActionType::MovieUngrouped->value)->orderBy('id')->get();

    expect(MovieGroup::query()->exists())->toBeFalse()
        ->and($ungrouped->pluck('subject_id')->all())->toBe([$first->id, $second->id]);

    foreach ($ungrouped as $line) {
        expect($line->details?->values)->toBe([
            'group_id' => $group->id,
            'group_label' => 'Old Boy, deux versions',
            'dissolved' => true,
        ]);
    }
});

test('le geste d\'un administrateur est tracé comme celui d\'un curateur', function (): void {
    $admin = User::factory()->admin()->create();
    $movie = catalogJournalFilm('Parasite');

    catalogJournalSend('post', 'admin.catalog.aliases.store', ['movie' => $movie->id], [
        'locale' => 'en',
        'alias' => 'Gisaengchung',
    ], $admin)->assertSessionHasNoErrors();

    $line = catalogJournalLine(AdminActionType::MovieAliasAdded);

    expect($line->actor_id)->toBe($admin->id)
        ->and($line->actor_name)->toBe($admin->real_name);
});

test('le geste de thème écrit movie.theme_set avec l\'état avant et après', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create(['rule_value' => null]);

    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => 'added',
    ])->assertSessionHasNoErrors();

    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => 'removed',
    ])->assertSessionHasNoErrors();

    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => null,
    ])->assertSessionHasNoErrors();

    $lines = AdminAction::query()->where('action', AdminActionType::MovieThemeSet->value)->orderBy('id')->get();

    expect($lines)->toHaveCount(3)
        ->and($lines->pluck('subject_id')->unique()->all())->toBe([$movie->id])
        ->and($lines[0]->subject_type)->toBe(AdminActionSubject::Movie)
        ->and($lines[0]->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($lines[0]->actor_id)->toBe($this->curator->id)
        ->and($lines[0]->actor_name)->toBe($this->curator->real_name)
        // `toEqual` : MySQL réordonne les clés d'une colonne JSON.
        ->and($lines->map(fn (AdminAction $line): ?array => $line->details?->values)->all())->toEqual([
            ['theme_key' => $theme->key, 'from' => null, 'to' => 'added'],
            ['theme_key' => $theme->key, 'from' => 'added', 'to' => 'removed'],
            ['theme_key' => $theme->key, 'from' => 'removed', 'to' => null],
        ]);
});

test('un geste de thème sans changement n\'écrit rien au journal', function (): void {
    $movie = Movie::factory()->create();
    $theme = Theme::factory()->create();

    // Annuler une exception qui n'existe pas : rien ne change.
    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => null,
    ])->assertSessionHasNoErrors();

    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => 'removed',
    ])->assertSessionHasNoErrors();

    // Le même retrait renvoyé : une seule ligne en tout.
    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => 'removed',
    ])->assertSessionHasNoErrors();

    expect(AdminAction::query()->where('action', AdminActionType::MovieThemeSet->value)->count())->toBe(1);
});

test('un geste de thème refusé n\'écrit rien au journal', function (): void {
    $movie = Movie::factory()->withdrawn()->create();
    $theme = Theme::factory()->create();

    catalogJournalSend('patch', 'admin.catalog.themes.update', ['movie' => $movie->id], [
        'theme_id' => $theme->id,
        'manual_state' => 'added',
    ])->assertForbidden();

    expect(AdminAction::query()->count())->toBe(0);
});
