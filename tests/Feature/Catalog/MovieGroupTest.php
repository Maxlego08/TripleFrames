<?php

use App\Actions\Curation\SetMovieGroup;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Alias;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\MovieTitle;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Regroupement « même œuvre » — spec 20 § 9.4, spec 10 § 3.3
|--------------------------------------------------------------------------
|
| `movie_group` est MANUEL : jamais TMDB, jamais montré à un joueur, et sa
| seule conséquence est l'exclusion mutuelle dans un tirage. Au jalon 1, un
| curateur regroupe deux films — le groupe naît avec un libellé pré-rempli
| tronqué à 120 caractères —, rattache un film à un groupe, ou l'en retire,
| en toutes lettres : un groupe réduit à moins de deux films disparaît. La
| fiche propose les candidats EXACTS, films au titre normalisé identique, et
| cherche un film désigné par son identifiant avant la même confirmation ;
| le back-office suggère, il ne regroupe jamais seul. Aucune ligne
| `admin_action`.
|
*/

beforeEach(function (): void {
    $this->curator = User::factory()->curator()->create();
});

/**
 * Un film au titre original et à l'année donnés, ses clés projetées.
 *
 * @param  array<string, mixed>  $attributes
 */
function movieGroupFilm(string $original, ?int $year, array $attributes = []): Movie
{
    $movie = Movie::factory()->create([
        'title_original' => $original,
        'title_original_latin' => null,
        'release_year' => $year,
        ...$attributes,
    ]);

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * Le geste de regroupement, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function movieGroupPatch(Movie $movie, array $payload): TestResponse
{
    return test()
        ->actingAs(test()->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->patch(route('admin.catalog.group.update', ['movie' => $movie->id]), $payload);
}

/**
 * La recherche de la voie manuelle : le rechargement partiel qui ne rend que
 * `group_manual_candidate`, pour l'identifiant saisi.
 */
function movieGroupLookup(Movie $movie, string $requested): TestResponse
{
    $page = test()->actingAs(test()->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->viewData('page');

    return test()
        ->actingAs(test()->curator)
        ->get(route('admin.catalog.show', [
            'movie' => $movie->id,
            'group_with' => $requested,
        ]), [
            Header::INERTIA => 'true',
            Header::VERSION => (string) $page['version'],
            Header::PARTIAL_COMPONENT => 'admin/catalog/show',
            Header::PARTIAL_ONLY => 'group_manual_candidate',
        ]);
}

/**
 * Un texte du back-office, résolu en français, sa clé vérifiée d'abord.
 */
function movieGroupText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

test('regrouper deux films crée un groupe manuel et les y rattache', function (): void {
    $first = movieGroupFilm('Old Boy', 2013);
    $second = movieGroupFilm('Old Boy', 2003);
    $journal = AdminAction::query()->count();

    // La voie manuelle cherche d'abord le film désigné, en lecture seule :
    // présenté comme un candidat, avec le libellé que la confirmation
    // pré-remplit, modifiable (§ 9.4).
    movieGroupLookup($first, ' '.$second->id.' ')
        ->assertOk()
        ->assertJsonPath('props.group_manual_candidate.requested', (string) $second->id)
        ->assertJsonPath('props.group_manual_candidate.refusal', null)
        ->assertJsonPath('props.group_manual_candidate.candidate.id', $second->id)
        ->assertJsonPath('props.group_manual_candidate.candidate.group_label', null)
        ->assertJsonPath('props.group_manual_candidate.candidate.default_label', 'Old Boy (2003) / Old Boy (2013)');

    expect(MovieGroup::query()->exists())->toBeFalse();

    movieGroupPatch($first, ['with_movie_id' => $second->id, 'note' => 'Remake américain du film coréen.'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $first->id]))
        ->assertInertiaFlash('toast.message', movieGroupText('admin.movie.group.flash.created'));

    /** @var MovieGroup $group */
    $group = MovieGroup::query()->sole();

    // Un groupe manuel, signé, au libellé pré-rempli du plus ancien au plus
    // récent ; les deux films y sont rattachés.
    expect($group->label)->toBe('Old Boy (2003) / Old Boy (2013)')
        ->and($group->note)->toBe('Remake américain du film coréen.')
        ->and($group->created_by_id)->toBe($this->curator->id)
        ->and($first->refresh()->group_id)->toBe($group->id)
        ->and($second->refresh()->group_id)->toBe($group->id);

    // Un troisième film rejoint le groupe existant, sans groupe nouveau ; le
    // libellé saisi n'y change rien.
    $third = movieGroupFilm('Oldeuboi', 2005);

    movieGroupPatch($third, ['with_movie_id' => $first->id, 'label' => 'Ignoré'])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', movieGroupText('admin.movie.group.flash.joined'));

    expect(MovieGroup::query()->count())->toBe(1)
        ->and($third->refresh()->group_id)->toBe($group->id)
        ->and($group->refresh()->label)->toBe('Old Boy (2003) / Old Boy (2013)');

    // Un libellé saisi remplace le libellé pré-rempli d'un groupe NOUVEAU,
    // et `group_id` rattache à un groupe désigné.
    $lion = movieGroupFilm('Le Roi Lion', 1994);
    $remake = movieGroupFilm('Le Roi Lion', 2019);

    movieGroupPatch($lion, ['with_movie_id' => $remake->id, 'label' => 'Le Roi lion 1994 / 2019'])->assertSessionHasNoErrors();

    $lions = MovieGroup::query()->where('label', 'Le Roi lion 1994 / 2019')->sole();
    $stage = movieGroupFilm('Le Roi Lion', 1995);

    movieGroupPatch($stage, ['group_id' => $lions->id])->assertSessionHasNoErrors();

    expect($stage->refresh()->group_id)->toBe($lions->id);

    // Jamais de fusion implicite : un film groupé ailleurs ne change pas de
    // groupe en silence. Refus traduits, rien n'est écrit.
    movieGroupPatch($first, ['with_movie_id' => $lion->id])
        ->assertSessionHasErrors(['with_movie_id' => movieGroupText('admin.movie.group.both_grouped')]);

    movieGroupPatch($first, ['group_id' => $lions->id])
        ->assertSessionHasErrors(['group_id' => movieGroupText('admin.movie.group.already_grouped')]);

    movieGroupPatch($first, ['with_movie_id' => $first->id])
        ->assertSessionHasErrors(['with_movie_id' => movieGroupText('admin.movie.group.self')]);

    movieGroupPatch($first, ['with_movie_id' => $first->id + 1_000])
        ->assertSessionHasErrors(['with_movie_id' => movieGroupText('admin.movie.group.other_missing')]);

    $withdrawn = movieGroupFilm('Old Boy', 2008, ['availability' => ContentAvailability::Withdrawn]);

    movieGroupPatch($withdrawn, ['with_movie_id' => $first->id])->assertForbidden();
    movieGroupPatch($lion, ['with_movie_id' => $withdrawn->id])
        ->assertSessionHasErrors(['with_movie_id' => movieGroupText('admin.movie.group.other_withdrawn')]);

    expect($first->refresh()->group_id)->toBe($group->id)
        ->and($withdrawn->refresh()->group_id)->toBeNull()
        ->and(MovieGroup::query()->count())->toBe(2);

    // La recherche de la voie manuelle dit ces refus AVANT toute
    // confirmation, plus le geste vain de deux films déjà ensemble.
    $lookups = [
        [$first, (string) $second->id, 'same_group'],
        [$first, (string) $lion->id, SetMovieGroup::REFUSAL_BOTH_GROUPED],
        [$first, (string) $first->id, SetMovieGroup::REFUSAL_SELF],
        [$first, (string) ($first->id + 1_000), SetMovieGroup::REFUSAL_MISSING],
        [$first, 'douze', SetMovieGroup::REFUSAL_MISSING],
        [$lion, (string) $withdrawn->id, SetMovieGroup::REFUSAL_WITHDRAWN],
    ];

    foreach ($lookups as [$movie, $requested, $refusal]) {
        movieGroupLookup($movie, $requested)
            ->assertJsonPath('props.group_manual_candidate.refusal', $refusal)
            ->assertJsonPath('props.group_manual_candidate.candidate', null);
    }

    // Une ligne `movie.grouped` par film dont le groupe a changé (D41 du
    // 30/09) — deux par groupe né, une par film rattaché —, aucune pour un
    // refus : 2 + 1 + 2 + 1.
    expect(AdminAction::query()->count())->toBe($journal + 6)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieGrouped->value)->count())->toBe(6);
});

test('un groupe réduit à un film disparaît', function (): void {
    $first = movieGroupFilm('Old Boy', 2003);
    $second = movieGroupFilm('Old Boy', 2013);
    $third = movieGroupFilm('Oldeuboi', 2005);

    movieGroupPatch($first, ['with_movie_id' => $second->id, 'note' => 'Remake américain.'])->assertSessionHasNoErrors();
    movieGroupPatch($third, ['with_movie_id' => $first->id])->assertSessionHasNoErrors();

    /** @var MovieGroup $group */
    $group = MovieGroup::query()->sole();

    // Trois films : en retirer un laisse le groupe à deux, intact.
    movieGroupPatch($third, ['leave' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', movieGroupText('admin.movie.group.flash.left'));

    expect($third->refresh()->group_id)->toBeNull()
        ->and(MovieGroup::query()->whereKey($group->id)->exists())->toBeTrue()
        ->and(Movie::query()->where('group_id', $group->id)->pluck('id')->sort()->values()->all())
        ->toBe([$first->id, $second->id]);

    // Un envoi qui ne nomme rien — un identifiant laissé vide — n'est jamais
    // lu comme un retrait : refusé sous le champ, rien n'est écrit, et le
    // groupe garde ses deux films et sa note. Retirer se demande seul.
    foreach ([[], ['with_movie_id' => '', 'group_id' => '', 'label' => '', 'note' => '']] as $payload) {
        movieGroupPatch($first, $payload)
            ->assertSessionHasErrors(['with_movie_id' => movieGroupText('admin.movie.group.movie_required')]);
    }

    movieGroupPatch($first, ['leave' => true, 'with_movie_id' => $third->id])->assertSessionHasErrors('leave');

    expect($first->refresh()->group_id)->toBe($group->id)
        ->and($group->refresh()->note)->toBe('Remake américain.');

    // Deux films : en retirer un réduit le groupe à un seul — il disparaît,
    // dans la même transaction, son dernier film détaché avec lui.
    movieGroupPatch($first, ['leave' => '1'])->assertSessionHasNoErrors();

    expect(MovieGroup::query()->whereKey($group->id)->exists())->toBeFalse()
        ->and($first->refresh()->group_id)->toBeNull()
        ->and($second->refresh()->group_id)->toBeNull();

    // Retirer un film sans groupe ne change rien.
    movieGroupPatch($first, ['leave' => true])
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', movieGroupText('admin.movie.group.flash.unchanged'));
});

test('les candidats exacts sont les films au titre normalisé identique', function (): void {
    $movie = movieGroupFilm('Old Boy', 2003);

    // Même titre original, à la casse et à la ponctuation près.
    $remake = movieGroupFilm('OLD BOY!', 2013);

    // Un titre LOCALISÉ identique, sur un film au titre original différent.
    $korean = movieGroupFilm('올드보이', 2008);
    MovieTitle::factory()->forLocale(Locale::French)->titled('Old Boy')->create(['movie_id' => $korean->id]);
    (new AnswerKeyProjector)->project($korean);

    // Ne sont PAS candidats : un alias seul (il valide une réponse, il ne
    // désigne pas l'œuvre), un préfixe dérivé, un titre voisin, un film
    // retiré.
    $aliased = movieGroupFilm('Vengeance', 2010);
    Alias::factory()->forLocale(Locale::French)->accepting('Old Boy')->create([
        'movie_id' => $aliased->id,
        'origin' => ContentOrigin::Curator,
    ]);
    (new AnswerKeyProjector)->project($aliased);

    movieGroupFilm('Old Boy : La Vengeance', 2015);
    movieGroupFilm('Oldboy', 2016);
    movieGroupFilm('Old Boy', 2018, ['availability' => ContentAvailability::Withdrawn]);

    // Un candidat déjà groupé ailleurs le dit, avec son groupe.
    $other = movieGroupFilm('Autre film', 1999);
    movieGroupPatch($remake, ['with_movie_id' => $other->id, 'label' => 'Groupe du remake'])->assertSessionHasNoErrors();

    expect(SetMovieGroup::exactCandidates($movie)->modelKeys())->toBe([$korean->id, $remake->id]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('group', null)
            ->has('group_exact_candidates', 2)
            ->where('group_exact_candidates.0.id', $korean->id)
            ->where('group_exact_candidates.0.group_label', null)
            ->where('group_exact_candidates.0.same_group', false)
            ->where('group_exact_candidates.0.default_label', 'Old Boy (2003) / 올드보이 (2008)')
            ->where('group_exact_candidates.1.id', $remake->id)
            ->where('group_exact_candidates.1.group_label', 'Groupe du remake'));

    // Regroupés, le candidat reste listé, marqué « déjà dans ce groupe », et
    // la fiche montre le groupe et ses films.
    movieGroupPatch($movie, ['with_movie_id' => $korean->id])->assertSessionHasNoErrors();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('group.label', 'Old Boy (2003) / 올드보이 (2008)')
            ->where('group.created_by', $this->curator->name)
            ->has('group.movies', 2)
            ->where('group.movies.0.id', $movie->id)
            ->where('group_exact_candidates.0.same_group', true));
});

test('le libellé pré-rempli de deux titres longs est tronqué à cent vingt caractères', function (): void {
    // Deux titres de 140 caractères sans espace : la troncature tombe au
    // milieu d'un mot, jamais sur une espace que `Str::limit` rognerait.
    $first = movieGroupFilm(str_repeat('Lumière', 20), 1990);
    $second = movieGroupFilm(str_repeat('Crépuscule', 14), 2010);

    $expected = SetMovieGroup::defaultLabel($first, $second);

    expect(mb_strlen($expected))->toBe(SetMovieGroup::LABEL_MAX_LENGTH)
        ->and($expected)->toEndWith('…')
        ->and($expected)->toStartWith(mb_substr($first->title_original, 0, 119));

    // Le même libellé, servi à la fiche du candidat et écrit par le geste :
    // la colonne `string(120)` le reçoit tel quel.
    movieGroupPatch($first, ['with_movie_id' => $second->id])->assertSessionHasNoErrors();

    /** @var MovieGroup $group */
    $group = MovieGroup::query()->sole();

    expect($group->label)->toBe($expected)
        ->and(mb_strlen($group->label))->toBe(120);

    // Un libellé saisi au-delà de 120 caractères est refusé sous le champ.
    $third = movieGroupFilm('Tiers', 2001);
    $fourth = movieGroupFilm('Quart', 2002);

    movieGroupPatch($third, ['with_movie_id' => $fourth->id, 'label' => str_repeat('x', 121)])
        ->assertSessionHasErrors('label');

    expect($third->refresh()->group_id)->toBeNull();
});
