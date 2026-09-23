<?php

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use Carbon\CarbonImmutable;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->has('stats.pool', 4)
            ->has('stats.coverage.levels', 5)
            ->hasAll([
                'stats.coverage.variants_total',
                'stats.coverage.covers_publishable',
                'stats.coverage.without_frames',
                'stats.coverage.single_variant_levels',
            ])
            ->has('queue')
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

test('le vivier par N est cumulatif et ne compte que les films publiés et clear', function (): void {
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
            ->where('stats.pool.0', ['frames_per_round' => 2, 'movies' => 1])
            ->where('stats.pool.1', ['frames_per_round' => 3, 'movies' => 1])
            ->where('stats.pool.2', ['frames_per_round' => 4, 'movies' => 0])
            ->where('stats.pool.3', ['frames_per_round' => 5, 'movies' => 0]));
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

test('la file de curation montre les dix plus anciens brouillons, du plus ancien au plus récent', function (): void {
    foreach (range(1, 12) as $offset) {
        Movie::factory()->create([
            'title_original' => 'Brouillon '.$offset,
            'created_at' => CarbonImmutable::now()->subDays(20 - $offset),
        ]);
    }

    Movie::factory()->published()->create(['title_original' => 'Publié']);

    $this->actingAs($this->curator)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('queue', 10)
            ->where('queue.0.title_original', 'Brouillon 1')
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
