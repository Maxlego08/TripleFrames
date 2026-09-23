<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Models\Movie;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Preuve fonctionnelle — les trois films réellement entrés au catalogue
|--------------------------------------------------------------------------
|
| Les trois films que l'import TMDB a fait entrer en base de développement —
| Fight Club (550), Parasite (496243) et Le Labyrinthe de Pan (1417) — sont
| REJOUÉS ICI PAR FACTORY. Aucun appel réseau, aucune lecture de la base de
| développement : un test qui dépendrait de l'une ou de l'autre ne prouverait
| plus rien le jour où quelqu'un vide le catalogue local, et la règle 6
| interdit de toute façon un appel TMDB hors acte d'administration.
|
| Ce qu'il prouve bout à bout, du contrôleur jusqu'aux props de l'écran :
|
| 1. la liste les affiche, avec l'identité que l'import leur a donnée ;
| 2. le filtre « entrés par exception » de la décision 11 remonte les trois ;
| 3. le comptage PAR MOTIF en dénombre exactement DEUX au motif « langue ».
|
| Le troisième point est celui qui vaut d'être écrit. Le filtre de notoriété
| par défaut (`config('catalog.import_filter')`) retient les langues `fr`,
| `en` et `ja`, 500 votes et 1970 : Parasite (`ko`) et Le Labyrinthe de Pan
| (`es`) en sortent par la langue, tandis que Fight Club (`en`, 1999, très au
| delà de 500 votes) le satisfait ENTIÈREMENT — et reste pourtant marqué
| « entré par exception », parce qu'il est entré par collage d'identifiants.
| `is_import_exception` n'est jamais dérivable des trois motifs : c'est
| exactement ce que le couple « trois remontés, deux au motif langue » atteste.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->curator = User::factory()->curator()->create();

    // Fight Club — entré par collage alors qu'il satisfait tout le filtre.
    // Aucun motif coché, et pourtant une exception : le cas que la décision 11
    // interdit de rendre invisible.
    $this->fightClub = Movie::factory()->importException()->create([
        'tmdb_id' => 550,
        'title_original' => 'Fight Club',
        'title_original_latin' => null,
        'original_language' => 'en',
        'release_year' => 1999,
        'vote_count' => 30_000,
    ]);

    // Parasite — 기생충, coréen : hors des langues du filtre.
    $this->parasite = Movie::factory()->exceptionForLanguage('ko')->create([
        'tmdb_id' => 496_243,
        'title_original' => '기생충',
        'title_original_latin' => 'Gisaengchung',
        'release_year' => 2019,
        'vote_count' => 18_000,
    ]);

    // Le Labyrinthe de Pan — espagnol : hors des langues du filtre lui aussi.
    $this->panLabyrinth = Movie::factory()->exceptionForLanguage('es')->create([
        'tmdb_id' => 1_417,
        'title_original' => 'El laberinto del fauno',
        'title_original_latin' => null,
        'release_year' => 2006,
        'vote_count' => 9_000,
    ]);

    // Un témoin entré par balayage, sans exception : sans lui, « le filtre les
    // remonte » ne prouverait rien, puisqu'il ne retirerait jamais personne.
    $this->witness = Movie::factory()->create([
        'tmdb_id' => 603,
        'title_original' => 'The Matrix',
        'original_language' => 'en',
        'release_year' => 1999,
        'vote_count' => 25_000,
    ]);
});

test('la liste du catalogue affiche les trois films et le témoin', function (): void {
    $page = $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', [
            'sort' => 'release_year',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/index', false)
            ->has('movies.data', 4)
            ->where('movies.meta.total', 4))
        ->inertiaPage();

    $rows = $page['props']['movies']['data'];

    $byTmdbId = [];

    foreach ($rows as $row) {
        $byTmdbId[$row['tmdb_id']] = $row;
    }

    expect(array_keys($byTmdbId))->toContain(550, 496_243, 1_417, 603);

    expect($byTmdbId[550])->toMatchArray([
        'title_original' => 'Fight Club',
        'original_language' => 'en',
        'release_year' => 1999,
        'import_source' => ImportSource::Paste->value,
        'is_import_exception' => true,
        'exception_for_language' => false,
        'exception_for_vote_count' => false,
        'exception_for_release_year' => false,
        // Aucun des trois n'est curé : ils sortent de l'import en brouillon et
        // sans coche de contenu, donc non publiables.
        'availability' => ContentAvailability::Draft->value,
        'content_flag' => ContentFlag::UnratedPending->value,
        // Aucune image n'est encore classée : zéro est la vérité à afficher,
        // jamais un chiffre inventé.
        'levels_count' => 0,
        'levels_mask' => 0,
        'variants_total' => 0,
    ]);

    // La translittération latine est portée par sa propre colonne : le titre
    // original reste invariant, en hangul.
    expect($byTmdbId[496_243])->toMatchArray([
        'title_original' => '기생충',
        'title_original_latin' => 'Gisaengchung',
        'original_language' => 'ko',
        'exception_for_language' => true,
    ]);

    expect($byTmdbId[1_417])->toMatchArray([
        'title_original' => 'El laberinto del fauno',
        'original_language' => 'es',
        'exception_for_language' => true,
    ]);

    // Le témoin est entré par balayage : aucune exception.
    expect($byTmdbId[603])->toMatchArray([
        'import_source' => ImportSource::Discover->value,
        'is_import_exception' => false,
    ]);
});

test('le filtre « entrés par exception » remonte les trois films et laisse le témoin dehors', function (): void {
    $page = $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', [
            'exception' => 'any',
            'sort' => 'release_year',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 3)
            ->where('movies.meta.total', 3)
            ->where('filters.exception', 'any')
            // Fight Club 1999, Le Labyrinthe de Pan 2006, Parasite 2019 :
            // l'ordre est celui de l'année, départagé par l'identifiant.
            ->where('movies.data.0.tmdb_id', 550)
            ->where('movies.data.1.tmdb_id', 1_417)
            ->where('movies.data.2.tmdb_id', 496_243))
        ->inertiaPage();

    foreach ($page['props']['movies']['data'] as $row) {
        expect($row['is_import_exception'])->toBeTrue();
    }
});

test('le comptage par motif dénombre exactement deux exceptions de langue', function (): void {
    // Sans autre filtre : les quatre compteurs portent sur tout le catalogue,
    // témoin compris — qui ne pèse sur aucun d'eux.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 4)
            ->where('facets.exception_total', 3)
            ->where('facets.exception_language', 2)
            ->where('facets.exception_vote_count', 0)
            ->where('facets.exception_release_year', 0));

    // Et le filtre par motif rend bien les deux seuls films concernés.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', [
            'exception' => 'language',
            'sort' => 'release_year',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 2)
            ->where('movies.data.0.tmdb_id', 1_417)
            ->where('movies.data.1.tmdb_id', 496_243)
            // La facette ne s'applique jamais à elle-même : les quatre
            // compteurs restent ceux du catalogue, sinon trois d'entre eux
            // vaudraient zéro par construction et la facette ne servirait plus.
            ->where('facets.exception_total', 3)
            ->where('facets.exception_language', 2));
});

test('un film entré par exception reste lisible sur sa fiche, avec son motif', function (): void {
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $this->parasite))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/show', false)
            ->where('movie.tmdb_id', 496_243)
            ->where('movie.title_original', '기생충')
            ->where('movie.title_original_latin', 'Gisaengchung')
            ->where('movie.is_import_exception', true)
            ->where('movie.exception_for_language', true)
            ->where('movie.exception_for_vote_count', false)
            ->where('movie.exception_for_release_year', false)
            // La banque d'images est vide, et l'écran le dit plutôt que de
            // laisser croire à un film jouable.
            ->where('projection.covers_publishable', false)
            ->has('frames', 0));
});
