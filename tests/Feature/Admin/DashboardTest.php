<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Draw\PoolReporter;
use App\Support\Frames\FrameStoragePrefix;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Tableau de bord — les compteurs viennent de la PROJECTION
|--------------------------------------------------------------------------
|
| Aucun agrégat sur `frame` : la projection existe précisément pour ça (§ 3.2).
| Les tests ci-dessous créent de vraies frames et laissent le projecteur
| synchrone recalculer, parce qu'un compteur écrit à la main dans
| `movie_projection` prouverait un comportement sur un état que le code ne
| produit jamais.
|
*/

beforeEach(function (): void {
    // Les pages React de ce lot sont écrites par un autre agent : sans ceci,
    // ces tests mesureraient la présence d'un fichier dans le manifeste Vite au
    // lieu de mesurer le contrôleur. Le rendu de la page, lui, est couvert par
    // `tsc` et par le build.
    $this->withoutVite();

    // Obligatoire avant toute fixture de `frame` : sans lui, les octets
    // partent dans la racine réelle du disque `frames` et y restent après le
    // `RefreshDatabase`, qui n'annule que la ligne.
    Storage::fake(FrameStoragePrefix::DISK);

    $this->curator = User::factory()->curator()->create();
});

test('le tableau de bord rend son composant et la forme complète de ses props', function (): void {
    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard', false)
            ->has('stats.movies_total')
            ->has('stats.availability')
            ->has('stats.content_flag')
            ->has('stats.exceptions', fn (Assert $exceptions) => $exceptions
                ->hasAll(['total', 'language', 'vote_count', 'release_year']))
            ->has('stats.pool', RoomSettingsBounds::MAX_FRAMES_PER_ROUND - RoomSettingsBounds::MIN_FRAMES_PER_ROUND + 1)
            ->has('stats.coverage.levels', 5)
            ->hasAll([
                'stats.coverage.variants_total',
                'stats.coverage.covers_publishable',
                'stats.coverage.without_frames',
                'stats.coverage.single_variant_levels',
            ])
            ->has('stats.curation', fn (Assert $curation) => $curation
                ->hasAll(['ready_to_publish', 'incomplete', 'set_aside']))
            ->has('stats.frames', fn (Assert $frames) => $frames
                ->hasAll(['to_review', 'to_rereview', 'rejected', 'failed']))
            ->has('queue')
            ->has('set_aside')
            ->has('runs')
            ->where('tmdb_configured', false));
});

test('les cinq tuiles de disponibilité et les trois de contenu comptent chaque valeur, zéro compris', function (): void {
    Movie::factory()->count(2)->create();
    Movie::factory()->published()->contentFlag(ContentFlag::Clear)->create();
    Movie::factory()->withdrawn()->contentFlag(ContentFlag::Blocked)->create();

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard', false)
            ->where('stats.movies_total', 4)
            ->where('stats.availability.'.ContentAvailability::Draft->value, 2)
            ->where('stats.availability.'.ContentAvailability::Published->value, 1)
            ->where('stats.availability.'.ContentAvailability::Withdrawn->value, 1)
            ->where('stats.availability.'.ContentAvailability::Suspended->value, 0)
            ->where('stats.availability.'.ContentAvailability::Unpublished->value, 0)
            ->where('stats.content_flag.'.ContentFlag::UnratedPending->value, 2)
            ->where('stats.content_flag.'.ContentFlag::Clear->value, 1)
            ->where('stats.content_flag.'.ContentFlag::Blocked->value, 1));
});

test('le vivier par N est cumulatif et ne compte que les œuvres publiées et clear', function (): void {
    // Trois niveaux couverts (1, 3, 5) : jouable à 2 et à 3, pas à 4 ni 5.
    $playable = Movie::factory()
        ->playable(3)
        ->has(
            Frame::factory()
                ->count(3)
                ->published()
                ->sequence(
                    ['frame_level' => FrameLevel::Level1],
                    ['frame_level' => FrameLevel::Level3],
                    ['frame_level' => FrameLevel::Level5],
                ),
            'frames',
        )
        ->create();

    MovieFactory::recomputeProjection($playable);

    // Même couverture, mais en brouillon : hors vivier.
    $draft = Movie::factory()
        ->has(
            Frame::factory()
                ->count(3)
                ->published()
                ->sequence(
                    ['frame_level' => FrameLevel::Level1],
                    ['frame_level' => FrameLevel::Level3],
                    ['frame_level' => FrameLevel::Level5],
                ),
            'frames',
        )
        ->create();

    MovieFactory::recomputeProjection($draft);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.pool.0', ['frames_per_round' => 2, 'works' => 1])
            ->where('stats.pool.1', ['frames_per_round' => 3, 'works' => 1])
            ->where('stats.pool.2', ['frames_per_round' => 4, 'works' => 0])
            ->where('stats.pool.3', ['frames_per_round' => 5, 'works' => 0]));
});

test('la supervision par N est celle du constructeur unique et lit ses bornes dans RoomSettingsBounds', function (): void {
    // Deux films regroupés comme une même œuvre, couvrant 1, 3 et 5 : UNE
    // œuvre à N ≤ 3, là où l'ancien comptage lisait deux films.
    $group = MovieGroup::factory()->create();

    foreach ([1, 2] as $ignored) {
        FrameBank::movieWith(
            Movie::factory()->playable(3)->inGroup($group)->create(),
            [1, 3, 5],
        );
    }

    // Une œuvre seule, couvrant les cinq niveaux : jouable à tout N.
    FrameBank::movieWith(Movie::factory()->playable(3)->create(), [1, 2, 3, 4, 5]);

    $bounds = range(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, RoomSettingsBounds::MAX_FRAMES_PER_ROUND);
    $reported = app(PoolReporter::class)->catalogueWorksByFramesPerRound();

    // La source unique, en œuvres, un N par borne.
    expect(array_keys($reported))->toBe($bounds)
        ->and($reported[2])->toBe(2)
        ->and($reported[3])->toBe(2)
        ->and($reported[4])->toBe(1)
        ->and($reported[5])->toBe(1);

    $expected = [];

    foreach ($reported as $framesPerRound => $works) {
        $expected[] = ['frames_per_round' => $framesPerRound, 'works' => $works];
    }

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('stats.pool', count($bounds))
            ->where('stats.pool', $expected)
            ->where('stats.pool.0.frames_per_round', RoomSettingsBounds::MIN_FRAMES_PER_ROUND)
            ->where('stats.pool.'.(count($bounds) - 1).'.frames_per_round', RoomSettingsBounds::MAX_FRAMES_PER_ROUND));

    // Le filtre « jouable à N » du catalogue lit les mêmes bornes.
    $this->actingAs($this->curator)
        ->get(route('admin.catalog.index'))
        ->assertInertia(fn (Assert $page) => $page->where('options.playable_at', $bounds));

    // Plus aucun second comptage : ni la méthode en `GROUP BY`, ni les bornes
    // recopiées dans le FormRequest, ni un `range(2, 5)` écrit en dur.
    expect(method_exists(DashboardController::class, 'poolByFramesPerRound'))->toBeFalse()
        ->and(defined(CatalogIndexRequest::class.'::PLAYABLE_AT_MIN'))->toBeFalse()
        ->and(defined(CatalogIndexRequest::class.'::PLAYABLE_AT_MAX'))->toBeFalse();

    foreach ([
        app_path('Http/Controllers/Admin/DashboardController.php'),
        app_path('Http/Controllers/Admin/CatalogController.php'),
        app_path('Http/Requests/Admin/CatalogIndexRequest.php'),
        app_path('Support/Admin/AdminCatalogPresenter.php'),
    ] as $path) {
        $code = (string) file_get_contents($path);

        expect(preg_match('/range\(\s*\d+\s*,\s*\d+\s*\)/', $code))->toBe(0, $path)
            ->and(str_contains($code, 'groupBy(\'movie_projection.levels_count\')'))->toBeFalse($path);
    }
});

test('le tableau de bord compte les films prêts à publier, incomplets et écartés', function (): void {
    // Prêt à publier : brouillon, contenu vérifié, niveaux 1, 3 et 5 en jeu.
    $ready = FrameBank::movieWith(
        Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)->create(),
        [1, 3, 5],
    )[0];

    // Pas prêts : couverture incomplète, ou contenu non vérifié.
    FrameBank::movieWith(Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)->create(), [1, 3]);
    FrameBank::movieWith(Movie::factory()->create(), [1, 3, 5]);

    // Incomplet : publié, le niveau 5 manque ; un publié complet ne l'est pas.
    $incomplete = FrameBank::publishedMovie([1, 3])[0];
    FrameBank::publishedMovie([1, 3, 5]);

    // Écarté : dépublié sans avoir jamais été publié ; un film dépublié
    // APRÈS publication ne l'est pas.
    $setAside = Movie::factory()->create([
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now(),
        'availability_reason' => 'Aucun visuel TMDB exploitable',
        'first_published_at' => null,
    ]);
    Movie::factory()->unpublished()->create();

    // Une image en échec compte ; une image écartée en échec, non.
    Frame::factory()->for(Movie::factory()->create())->processingFailed()->create();
    Frame::factory()->for(Movie::factory()->create())->processingFailed()->create([
        'availability' => ContentAvailability::Unpublished,
        'first_published_at' => null,
    ]);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.curation', [
                'ready_to_publish' => 1,
                'incomplete' => 1,
                'set_aside' => 1,
            ])
            ->where('stats.frames.failed', 1)
            // La liste « Films écartés », motif compris.
            ->has('set_aside', 1)
            ->where('set_aside.0.id', $setAside->id)
            ->where('set_aside.0.availability_reason', 'Aucun visuel TMDB exploitable'));

    // Chaque compteur mène à la liste qu'il annonce : le filtre du catalogue.
    foreach ([
        'ready_to_publish' => $ready,
        'incomplete' => $incomplete,
        'set_aside' => $setAside,
    ] as $status => $movie) {
        $this->actingAs($this->curator)
            ->get(route('admin.catalog.index', ['curation_status' => $status]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.curation_status', $status)
                ->has('movies.data', 1)
                ->where('movies.data.0.id', $movie->id));
    }
});

test('la couverture d’images dit la vérité, y compris quand elle vaut zéro', function (): void {
    Movie::factory()->count(2)->create();

    $covered = Movie::factory()
        ->has(
            Frame::factory()
                ->count(3)
                ->published()
                ->sequence(
                    ['frame_level' => FrameLevel::Level1],
                    ['frame_level' => FrameLevel::Level3],
                    ['frame_level' => FrameLevel::Level5],
                ),
            'frames',
        )
        ->create();

    MovieFactory::recomputeProjection($covered);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.coverage.variants_total', 3)
            // `levels_mask & 21 = 21` : un seul film couvre 1, 3 et 5.
            ->where('stats.coverage.covers_publishable', 1)
            ->where('stats.coverage.without_frames', 2)
            // Trois couples (film, niveau) à variante unique.
            ->where('stats.coverage.single_variant_levels', 3)
            ->where('stats.coverage.levels.0', ['level' => 1, 'variants' => 1, 'movies' => 1])
            ->where('stats.coverage.levels.1', ['level' => 2, 'variants' => 0, 'movies' => 0]));
});

test('la tête de la file du tableau de bord suit l’ordre de la file de curation', function (): void {
    foreach (range(1, 12) as $rank) {
        Movie::factory()->create([
            'title_original' => 'Brouillon '.$rank,
            'vote_count' => 10_000 - $rank,
        ]);
    }

    Movie::factory()->published()->create(['title_original' => 'Publié', 'vote_count' => 90_000]);
    Movie::factory()->demo()->create(['title_original' => 'Démonstration', 'vote_count' => 90_000]);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('queue', DashboardController::LIST_SIZE)
            ->where('queue.0.title_original', 'Brouillon 1')
            ->where('queue.0.rank', 1)
            ->where('queue.0.is_started', false)
            ->where('queue.9.title_original', 'Brouillon 10')
            ->where('queue.0.levels_count', 0)
            ->where('queue.0.variants_total', 0));
});

test('les cinq derniers balayages sont rendus avec leur auteur et l’état « en file »', function (): void {
    ImportRun::factory()->count(6)->create();

    $queued = ImportRun::factory()->running()->create(['started_at' => null]);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('runs', 5)
            ->where('runs.0.id', $queued->id)
            ->where('runs.0.is_queued', true)
            ->where('runs.0.status', 'running')
            ->has('runs.0.actor_name')
            ->hasAll([
                'runs.0.total_seen',
                'runs.0.total_imported',
                'runs.0.total_skipped',
                'runs.0.total_refused_content',
            ]));
});

test('un balayage dont l’auteur a disparu reste lisible', function (): void {
    $actor = User::factory()->curator()->create();
    $run = ImportRun::factory()->actedBy($actor)->create();

    $actor->delete();

    expect($run->refresh()->actor_id)->toBeNull();

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('runs.0.actor_name', null));
});

test('le tableau de bord ne fait aucun N+1 : son nombre de requêtes ne dépend pas du volume', function (): void {
    Movie::factory()->count(3)->create();
    ImportRun::factory()->count(2)->create();

    $this->actingAs($this->curator);

    // Compter plutôt que plafonner : un plafond arbitraire se périme au premier
    // `with()` légitime, alors qu'un nombre de requêtes INDÉPENDANT du volume
    // est exactement la définition de l'absence de N+1.
    DB::enableQueryLog();

    $this->get(route('admin.dashboard'))->assertOk();
    $small = count(DB::getQueryLog());

    DB::flushQueryLog();

    Movie::factory()->count(20)->create();
    ImportRun::factory()->count(8)->create();

    DB::flushQueryLog();

    $this->get(route('admin.dashboard'))->assertOk();
    $large = count(DB::getQueryLog());

    DB::disableQueryLog();

    expect($large)->toBe($small);
});
