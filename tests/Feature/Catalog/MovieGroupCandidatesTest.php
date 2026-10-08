<?php

use App\Models\Collection;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Curation\MovieGroupCandidates;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Candidats `movie_group` par proximité — spec 20 § 9.4 [J2], L20-27
|--------------------------------------------------------------------------
|
| Titre proche (distance ≤ tolérance de la plus courte forme, chiffres
| identiques) ou même collection. Le back-office suggère, il ne regroupe
| jamais seul, et le calcul n'est payé qu'au rechargement partiel qui le
| demande.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original donné, ses clés projetées.
 *
 * @param  array<string, mixed>  $attributes
 */
function groupCandidatesFilm(string $original, array $attributes = []): Movie
{
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => null,
        ...$attributes,
    ]);

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/** Le rechargement partiel de la fiche qui ne rend que les candidats par proximité. */
function groupCandidatesReload(Movie $movie): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'group_candidates',
        ]);
}

test('un film à distance tolérée et à chiffres identiques est proposé', function (): void {
    $movie = groupCandidatesFilm('Nosferatu');
    $close = groupCandidatesFilm('Nosferatou');
    groupCandidatesFilm('Metropolis');

    $alien3 = groupCandidatesFilm('Alien 3');
    $alien4 = groupCandidatesFilm('Alien 4');
    groupCandidatesFilm('Alien');

    $proposed = collect(app(MovieGroupCandidates::class)->for($movie));

    expect($proposed->map(fn (array $row): int => $row['movie']->id)->all())->toBe([$close->id])
        ->and($proposed->first()['reasons'])->toBe([MovieGroupCandidates::REASON_TITLE]);

    // Chiffres différents : jamais proches, quelle que soit la distance.
    expect(app(MovieGroupCandidates::class)->for($alien3))->toBe([])
        ->and(app(MovieGroupCandidates::class)->for($alien4))->toBe([]);
});

test('une même collection est proposée sans jamais regrouper seule', function (): void {
    $collection = Collection::factory()->create();

    $movie = groupCandidatesFilm('The Fellowship', ['collection_id' => $collection->id]);
    $sibling = groupCandidatesFilm('The Return', ['collection_id' => $collection->id]);
    groupCandidatesFilm('Unrelated Story');

    $withdrawn = Movie::factory()->withdrawn()->create(['collection_id' => $collection->id]);

    $proposed = app(MovieGroupCandidates::class)->for($movie);

    expect(array_map(fn (array $row): int => $row['movie']->id, $proposed))->toBe([$sibling->id])
        ->and($proposed[0]['reasons'])->toBe([MovieGroupCandidates::REASON_COLLECTION]);

    groupCandidatesReload($movie)
        ->assertOk()
        ->assertJsonPath('props.group_candidates.0.id', $sibling->id)
        ->assertJsonPath('props.group_candidates.0.reasons', ['same_collection'])
        ->assertJsonPath('props.group_candidates.0.same_group', false);

    // Une suggestion, jamais un regroupement.
    expect(MovieGroup::query()->count())->toBe(0)
        ->and($movie->refresh()->group_id)->toBeNull()
        ->and($sibling->refresh()->group_id)->toBeNull()
        ->and($withdrawn->refresh()->group_id)->toBeNull();
});

test('les candidats ne sont calculés qu\'à la demande', function (): void {
    $movie = groupCandidatesFilm('Nosferatu');
    groupCandidatesFilm('Nosferatou');

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/show', false)
            ->missing('group_candidates'));

    groupCandidatesReload($movie)
        ->assertOk()
        ->assertJsonCount(1, 'props.group_candidates')
        ->assertJsonPath('props.group_candidates.0.reasons', ['title_distance']);
});

test('un candidat exact n\'est pas reproposé par proximité', function (): void {
    $movie = groupCandidatesFilm('Old Boy', ['release_year' => 2003]);
    groupCandidatesFilm('Old Boy', ['release_year' => 2013]);

    expect(app(MovieGroupCandidates::class)->for($movie))->toBe([]);
});
