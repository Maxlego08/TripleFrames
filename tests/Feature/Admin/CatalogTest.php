<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ContentOrigin;
use App\Enums\FrameLevel;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Models\Alias;
use App\Models\Collection;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\TmdbCompany;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Le catalogue — liste filtrable et fiche, en LECTURE SEULE
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    $this->withoutVite();

    // Obligatoire avant toute fixture de `frame` : sans lui, les octets
    // partent dans la racine réelle du disque `frames` et y restent après le
    // `RefreshDatabase`, qui n'annule que la ligne.
    Storage::fake(FrameStoragePrefix::DISK);

    $this->curator = User::factory()->curator()->create();
});

test('la liste rend son composant, sa pagination explicite et ses listes blanches', function (): void {
    Movie::factory()->count(30)->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/index', false)
            ->has('movies.data', 25)
            ->where('movies.meta.current_page', 1)
            ->where('movies.meta.last_page', 2)
            ->where('movies.meta.per_page', 25)
            ->where('movies.meta.total', 30)
            ->where('movies.meta.from', 1)
            ->where('movies.meta.to', 25)
            // Le tableau `links` d'un paginateur embarque « Previous » et
            // « Next » en anglais : il ne doit JAMAIS partir vers l'écran.
            ->missing('movies.links')
            ->where('filters.sort', 'created_at')
            ->where('filters.direction', 'desc')
            ->has('options.availability', 5)
            ->has('options.content_flag', 3)
            ->has('options.import_source', 3)
            ->has('options.playable_at', 4)
            ->has('options.exception', 4)
            ->has('options.sort', 6)
            ->has('options.direction', 2));
});

test('la deuxième page est bien la deuxième page', function (): void {
    Movie::factory()->count(30)->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 5)
            ->where('movies.meta.current_page', 2)
            ->where('movies.meta.from', 26)
            ->where('movies.meta.to', 30));
});

test('chaque filtre d’état filtre réellement', function (): void {
    Movie::factory()->count(3)->create();
    Movie::factory()->published()->contentFlag(ContentFlag::Clear)->create(['title_original' => 'Publié']);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['availability' => ContentAvailability::Published->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 1)
            ->where('movies.data.0.title_original', 'Publié')
            ->where('filters.availability', ContentAvailability::Published->value));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['content_flag' => ContentFlag::Clear->value]))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 1));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['import_source' => ImportSource::Demo->value]))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 0));
});

test('la recherche libre porte sur les deux titres, et sur le tmdb_id quand la saisie est un nombre', function (): void {
    Movie::factory()->create(['title_original' => 'Le Voyage de Chihiro', 'tmdb_id' => 129]);
    Movie::factory()->create([
        'title_original' => '千と千尋の神隠し',
        'title_original_latin' => 'Sen to Chihiro no kamikakushi',
        'tmdb_id' => 4_242,
    ]);
    Movie::factory()->create(['title_original' => 'Parasite', 'tmdb_id' => 496_243]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['q' => 'Chihiro']))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 2));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['q' => '496243']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 1)
            ->where('movies.data.0.title_original', 'Parasite'));
});

test('le filtre « jouable à N » s’appuie sur la projection', function (): void {
    $movie = Movie::factory()
        ->has(
            Frame::factory()
                ->count(2)
                ->published()
                ->sequence(
                    ['frame_level' => FrameLevel::Level1],
                    ['frame_level' => FrameLevel::Level5],
                ),
            'frames',
        )
        ->create();

    MovieFactory::recomputeProjection($movie);

    Movie::factory()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['playable_at' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 1)
            ->where('movies.data.0.levels_count', 2)
            ->where('filters.playable_at', 2));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['playable_at' => 3]))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 0));
});

test('le filtre « entrés par exception » et ses trois motifs', function (): void {
    Movie::factory()->create();
    Movie::factory()->exceptionForLanguage()->create();
    Movie::factory()->exceptionForVoteCount()->create();
    Movie::factory()->exceptionForReleaseYear()->create();
    // Un film collé qui satisfait tout le filtre reste marqué entré par
    // exception : `is_import_exception` n'est jamais dérivable des motifs.
    Movie::factory()->importException()->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['exception' => 'any']))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 4));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['exception' => 'language']))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 1));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['exception' => 'release_year']))
        ->assertInertia(fn (Assert $page) => $page->has('movies.data', 1));
});

test('les facettes sont mesurées sur le jeu filtré courant PRIVÉ de la seule facette exception', function (): void {
    Movie::factory()->exceptionForLanguage()->create();
    Movie::factory()->exceptionForVoteCount()->create();
    Movie::factory()->exceptionForReleaseYear()->published()->create();
    Movie::factory()->create();

    // Sans autre filtre : les quatre compteurs portent sur tout le catalogue.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('facets.exception_total', 3)
            ->where('facets.exception_language', 1)
            ->where('facets.exception_vote_count', 1)
            ->where('facets.exception_release_year', 1));

    // La facette `exception` ne s'applique PAS à elle-même : filtrer sur
    // « motif langue » laisse les quatre compteurs inchangés.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['exception' => 'language']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 1)
            ->where('facets.exception_total', 3)
            ->where('facets.exception_release_year', 1));

    // Les AUTRES filtres, eux, s'appliquent bien à la facette.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', [
            'availability' => ContentAvailability::Published->value,
            'exception' => 'any',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('facets.exception_total', 1)
            ->where('facets.exception_language', 0)
            ->where('facets.exception_release_year', 1));
});

test('le tri est appliqué, et toujours départagé', function (): void {
    Movie::factory()->create(['title_original' => 'Bravo', 'vote_count' => 10]);
    Movie::factory()->create(['title_original' => 'Alpha', 'vote_count' => 900]);
    Movie::factory()->create(['title_original' => 'Charlie', 'vote_count' => 500]);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['sort' => 'title_original', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movies.data.0.title_original', 'Alpha')
            ->where('movies.data.2.title_original', 'Charlie')
            ->where('filters.sort', 'title_original')
            ->where('filters.direction', 'asc'));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['sort' => 'vote_count', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movies.data.0.title_original', 'Alpha')
            ->where('movies.data.2.title_original', 'Bravo'));
});

test('une valeur de query string hors liste blanche est refusée, jamais silencieusement ignorée', function (): void {
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['availability' => 'inventée']))
        ->assertSessionHasErrors('availability');

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['sort' => 'title_original; drop table movie']))
        ->assertSessionHasErrors('sort');

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['playable_at' => 9]))
        ->assertSessionHasErrors('playable_at');
});

test('la liste ne fait aucun N+1 : son nombre de requêtes ne dépend pas du volume', function (): void {
    Movie::factory()->count(3)->create();

    $this->actingAs($this->curator);

    DB::enableQueryLog();
    $this->get(route('admin.catalog.index'))->assertOk();
    $small = count(DB::getQueryLog());

    Movie::factory()->count(20)->create();

    DB::flushQueryLog();
    $this->get(route('admin.catalog.index'))->assertOk();
    $large = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($large)->toBe($small);
});

test('la fiche film rend les huit blocs du contrat', function (): void {
    $run = ImportRun::factory()->discover()->completed()->create();
    $collection = Collection::factory()->create(['name' => 'Saga de test']);
    $group = MovieGroup::factory()->create(['label' => 'Old Boy 2003 / 2013']);

    $movie = Movie::factory()
        ->inCollection($collection)
        ->inGroup($group)
        ->curatedBy($this->curator, 420)
        ->contentVerifiedBy($this->curator)
        ->has(
            Frame::factory()
                ->count(2)
                ->published()
                ->sequence(
                    ['frame_level' => FrameLevel::Level1],
                    ['frame_level' => FrameLevel::Level5],
                ),
            'frames',
        )
        ->create([
            'title_original' => 'Sujet de fiche',
            'title_original_latin' => 'Sujet de fiche',
            'import_run_id' => $run->id,
        ]);

    MovieTitle::factory()->create([
        'movie_id' => $movie->id,
        'locale' => 'fr',
        'title' => 'Sujet de fiche',
        'origin' => ContentOrigin::Curator,
        'edited_by_id' => $this->curator->id,
    ]);

    Alias::factory()->create([
        'movie_id' => $movie->id,
        'locale' => 'fr',
        'alias' => 'Sujet',
        'origin' => ContentOrigin::Curator,
    ]);

    MovieCertification::factory()->create(['movie_id' => $movie->id]);
    MovieTmdbTag::factory()->genre(16)->create(['movie_id' => $movie->id]);

    MovieFactory::recomputeProjection($movie);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/show', false)
            ->where('movie.id', $movie->id)
            ->where('movie.title_original', 'Sujet de fiche')
            ->where('movie.collection_name', 'Saga de test')
            ->where('movie.group_label', 'Old Boy 2003 / 2013')
            ->where('movie.curated_by', $this->curator->name)
            ->where('movie.content_verified_by', $this->curator->name)
            ->where('movie.curation_active_seconds', 420)
            ->where('movie.import_run_id', $run->id)
            ->where('projection.levels_count', 2)
            ->where('projection.covers_publishable', false)
            ->where('projection.playable_at', [2])
            ->where('projection.title_mask_current', true)
            ->has('titles', 1)
            ->where('titles.0.edited_by', $this->curator->name)
            ->has('aliases', 1)
            ->where('aliases.0.alias', 'Sujet')
            ->has('certifications', 1)
            ->has('tags', 1)
            ->where('tags.0.tmdb_tag_id', 16)
            ->has('themes')
            ->has('frames', 2)
            ->where('import_run.id', $run->id));
});

test('la fiche ne laisse jamais sortir un chemin d’image, une empreinte ni un identifiant de frame', function (): void {
    $movie = Movie::factory()
        ->has(Frame::factory()->published()->count(1), 'frames')
        ->create();

    $response = $this->actingAs($this->curator)->get(route('admin.catalog.show', $movie));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('frames', 1, fn (Assert $frame) => $frame->hasAll([
            'frame_level',
            'availability',
            'processing_state',
            'processing_error',
            'is_retryable',
        ])));

    $frame = $movie->frames()->firstOrFail();

    // Le § 10 est formel : un chemin ne quitte jamais le serveur, même en
    // administration. Ni le chemin, ni l'empreinte, ni l'identifiant.
    $response->assertDontSee((string) $frame->game_path, false)
        ->assertDontSee((string) $frame->master_path, false)
        ->assertDontSee($frame->source_hash, false)
        ->assertDontSee('game_path', false)
        ->assertDontSee('master_path', false);
});

test('un film sans ligne de projection reste lisible et vaut zéro partout', function (): void {
    $movie = Movie::factory()->create();
    $movie->projection()->delete();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projection', null)
            ->where('movie.levels_count', 0)
            ->where('movie.variants_total', 0));
});

test('la fiche ne fait aucun N+1 sur les titres, alias et thèmes', function (): void {
    $movie = Movie::factory()->create();

    foreach (['fr', 'en'] as $locale) {
        MovieTitle::factory()->create([
            'movie_id' => $movie->id,
            'locale' => $locale,
            'edited_by_id' => User::factory()->curator()->create()->id,
        ]);
    }

    $this->actingAs($this->curator);

    DB::enableQueryLog();
    $this->get(route('admin.catalog.show', $movie))->assertOk();
    $small = count(DB::getQueryLog());

    foreach (['ja', 'ko', 'it', 'es'] as $locale) {
        MovieTitle::factory()->create([
            'movie_id' => $movie->id,
            'locale' => $locale,
            'edited_by_id' => User::factory()->curator()->create()->id,
        ]);
    }

    DB::flushQueryLog();
    $this->get(route('admin.catalog.show', $movie))->assertOk();
    $large = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($large)->toBe($small);
});

test('l’onglet étiquettes affiche le nom d’une société connue', function (): void {
    $movie = Movie::factory()->withGenre(16)->withCompany(420)->withCompany(7711)->create();
    TmdbCompany::factory()->named(420, 'Marvel Studios')->create();
    // Un genre ne porte jamais de nom, même si une société partage son identifiant.
    TmdbCompany::factory()->named(16, 'Homonyme d’un genre')->create();

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('tags', 3)
            ->where('tags.0.tag_kind', 'company')
            ->where('tags.0.tmdb_tag_id', 420)
            ->where('tags.0.name', 'Marvel Studios')
            ->where('tags.1.tmdb_tag_id', 7711)
            ->where('tags.1.name', null)
            ->where('tags.2.tag_kind', 'genre')
            ->where('tags.2.name', null));
});

test('les noms des sociétés se lisent en une requête, quel que soit leur nombre', function (): void {
    $movie = Movie::factory()->withGenre(16)->create();

    $this->actingAs($this->curator);

    DB::enableQueryLog();
    $this->get(route('admin.catalog.show', $movie))->assertOk();
    $none = count(DB::getQueryLog());

    foreach ([420, 429, 128064, 184898] as $companyId) {
        MovieTmdbTag::factory()->company($companyId)->create(['movie_id' => $movie->id]);
        TmdbCompany::factory()->named($companyId, 'Société '.$companyId)->create();
    }

    DB::flushQueryLog();
    $this->get(route('admin.catalog.show', $movie))->assertOk();
    $many = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($many)->toBe($none);
});

test('le filtre des titres manquants lit title_locale_mask à la version courante', function (): void {
    // Aucun titre : le français manque, masque à la version courante.
    $draftMissing = Movie::factory()->create(['title_original' => 'Brouillon sans titre']);

    // Publié et sans titre français : il vient en tête (§ 9.3).
    $publishedMissing = Movie::factory()->published()->create(['title_original' => 'Publié sans titre']);

    // Titré en français : hors de la file « titres manquants » en français,
    // mais dedans en anglais.
    $titled = Movie::factory()->create(['title_original' => 'Titré en français']);
    MovieTitle::factory()->forLocale(Locale::French)->create(['movie_id' => $titled->id]);
    MovieFactory::recomputeProjection($titled);

    // Masque PÉRIMÉ : jamais lu comme valide, le film n'y entre pas, quel que
    // soit le bit.
    $stale = Movie::factory()->create(['title_original' => 'Masque périmé']);
    MovieProjection::query()->whereKey($stale->id)->update(['title_mask_version' => Locale::MASK_VERSION - 1]);

    // Sans ligne de projection : pas davantage.
    Movie::factory()->withoutProjection()->create(['title_original' => 'Sans projection']);

    expect(FrameBank::projection($titled)->title_locale_mask & Locale::French->maskBit())->not->toBe(0)
        ->and(FrameBank::projection($draftMissing)->title_locale_mask & Locale::French->maskBit())->toBe(0);

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['missing_title' => Locale::French->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.missing_title', Locale::French->value)
            ->where('options.missing_title', array_column(Locale::cases(), 'value'))
            ->has('movies.data', 2)
            ->where('movies.data.0.id', $publishedMissing->id)
            ->where('movies.data.1.id', $draftMissing->id));

    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['missing_title' => Locale::English->value]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movies.data', 3)
            ->where('movies.data.0.id', $publishedMissing->id));

    // Une locale non activée est refusée, jamais ignorée.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index', ['missing_title' => 'ja']))
        ->assertSessionHasErrors('missing_title');
});
