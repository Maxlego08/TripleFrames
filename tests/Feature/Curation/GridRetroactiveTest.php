<?php

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\RetroactiveGrid;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| Geste rétroactif de grille — spec 20 § 7.7, D13 du 23/09, L20-25
|--------------------------------------------------------------------------
|
| La table des versions publiées de la grille est figée et n'admet aucune
| version de test (§ 7.2 point 1) : la preuve lie au conteneur un
| `RetroactiveGrid` construit sur une version 2 fictive, rétroactive, qui
| ajoute un item aux niveaux 1 et 2. Sans cette liaison, c'est la grille
| réelle (v1, non rétroactive) qui répond — « indisponible ».
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Storage::fake(FrameStoragePrefix::DISK);

    $this->admin = User::factory()->admin()->create();
});

/** Une grille v2 fictive, rétroactive aux niveaux 1 et 2. */
function gridRetroactiveBind(): RetroactiveGrid
{
    $grid = new RetroactiveGrid(
        version: ExclusionGrid::CURRENT_VERSION + 1,
        retroactive: true,
        levels: [FrameLevel::Level1, FrameLevel::Level2],
    );

    app()->instance(RetroactiveGrid::class, $grid);

    return $grid;
}

/**
 * Un film publié couvrant 1, 3 et 5, une variante revue sous la v1 chacun ;
 * `$extraLevelOne` ajoute une seconde variante de niveau 1 revue sous la v1.
 *
 * @return array{movie: Movie, frames: array<int, Frame>}
 */
function gridRetroactiveMovie(bool $extraLevelOne = false): array
{
    $movie = Movie::factory()->published()->create();

    $frames = [];

    foreach ([FrameLevel::Level1, FrameLevel::Level3, FrameLevel::Level5] as $level) {
        $frames[$level->value] = Frame::factory()->for($movie)->level($level)->published()->create();
    }

    if ($extraLevelOne) {
        Frame::factory()->for($movie)->level(FrameLevel::Level1)->published()->create();
    }

    app(MovieProjector::class)->recompute($movie);

    return ['movie' => $movie, 'frames' => $frames];
}

/** @return array<string, mixed> */
function gridRetroactivePayload(RetroactiveGrid $grid, array $overrides = []): array
{
    return [
        'version' => $grid->version,
        'reason' => 'Nouveau critère juridique : mise en demeure reçue.',
        ...$overrides,
    ];
}

test('le geste est réservé à l\'admin', function (): void {
    $grid = gridRetroactiveBind();
    ['frames' => $frames] = gridRetroactiveMovie();

    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid))
        ->assertForbidden();

    expect($frames[1]->refresh()->availability)->toBe(ContentAvailability::Published);

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid))
        ->assertRedirect(route('admin.exclusion_grid.retroactive.show'));

    expect($frames[1]->refresh()->availability)->toBe(ContentAvailability::Unpublished);
});

test('il n\'est offert que si la version courante est rétroactive', function (): void {
    ['frames' => $frames] = gridRetroactiveMovie();

    // La grille réelle : la v1 n'est pas rétroactive.
    expect(ExclusionGrid::isRetroactive(ExclusionGrid::CURRENT_VERSION))->toBeFalse();

    $this->actingAs($this->admin)
        ->get(route('admin.exclusion_grid.retroactive.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/exclusion-grid/retroactive', false)
            ->where('available', false)
            ->where('movies', []));

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), [
            'version' => ExclusionGrid::CURRENT_VERSION,
            'reason' => 'Essai sous une grille non rétroactive.',
        ])
        ->assertRedirect(route('admin.exclusion_grid.retroactive.show'))
        ->assertSessionHasNoErrors();

    expect($frames[1]->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->where('action', AdminActionType::FrameGridUnpublished->value)->count())->toBe(0);
});

test('il dépublie seulement les frames publiées non re-revues aux niveaux concernés et écrit une ligne frame.grid_unpublished par frame', function (): void {
    $grid = gridRetroactiveBind();
    ['movie' => $movie, 'frames' => $frames] = gridRetroactiveMovie();

    // Re-revue à la version courante : jamais visée.
    $rereviewed = Frame::factory()->for($movie)->level(FrameLevel::Level2)->published()->create();
    $rereviewed->forceFill(['review_grid_version' => $grid->version])->save();

    // Brouillon de niveau 1 : pas publié, jamais visé.
    $draft = Frame::factory()->for($movie)->level(FrameLevel::Level1)->create();

    $takedown = TakedownRequest::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid, [
            'takedown_reference' => $takedown->reference,
        ]))
        ->assertRedirect(route('admin.exclusion_grid.retroactive.show'))
        ->assertSessionHasNoErrors();

    expect($frames[1]->refresh()->availability)->toBe(ContentAvailability::Unpublished)
        ->and($frames[1]->availability_changed_at)->not->toBeNull()
        ->and($frames[3]->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($frames[5]->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($rereviewed->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($draft->refresh()->availability)->toBe($draft->availability);

    /** @var AdminAction $line */
    $line = AdminAction::query()->where('action', AdminActionType::FrameGridUnpublished->value)->sole();

    expect($line->subject_id)->toBe($frames[1]->id)
        ->and($line->actor_id)->toBe($this->admin->id)
        ->and($line->reason)->toBe('Nouveau critère juridique : mise en demeure reçue.')
        ->and($line->takedown_request_id)->toBe($takedown->id);
});

test('il est idempotent', function (): void {
    $grid = gridRetroactiveBind();
    gridRetroactiveMovie(extraLevelOne: true);

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid));

    $lines = AdminAction::query()->where('action', AdminActionType::FrameGridUnpublished->value)->count();

    expect($lines)->toBe(2);

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid))
        ->assertRedirect(route('admin.exclusion_grid.retroactive.show'));

    expect(AdminAction::query()->where('action', AdminActionType::FrameGridUnpublished->value)->count())->toBe($lines);
});

test('un film qui perd sa couverture reste publié', function (): void {
    $grid = gridRetroactiveBind();
    ['movie' => $movie] = gridRetroactiveMovie();

    $this->actingAs($this->admin)
        ->post(route('admin.exclusion_grid.retroactive.store'), gridRetroactivePayload($grid));

    $movie->refresh();

    /** @var MovieProjection $projection */
    $projection = MovieProjection::query()->findOrFail($movie->id);

    expect($movie->availability)->toBe(ContentAvailability::Published)
        ->and($projection->coversPublishableLevels())->toBeFalse()
        ->and($projection->variantsForLevel(FrameLevel::Level1))->toBe(0)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieUnpublished->value)->exists())->toBeFalse();
});

test('la confirmation liste les films que le geste rendra incomplets', function (): void {
    gridRetroactiveBind();
    ['movie' => $losing] = gridRetroactiveMovie();
    ['movie' => $twoVariants] = gridRetroactiveMovie(extraLevelOne: true);

    $this->actingAs($this->admin)
        ->get(route('admin.exclusion_grid.retroactive.show'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('available', true)
            ->where('levels', [1, 2])
            ->where('frames_total', 3)
            ->has('movies', 2)
            ->where('movies', fn ($movies): bool => collect($movies)->firstWhere('id', $losing->id)['becomes_incomplete'] === true
                && collect($movies)->firstWhere('id', $losing->id)['playable_up_to'] === 2
                && collect($movies)->firstWhere('id', $twoVariants->id)['becomes_incomplete'] === true
                && collect($movies)->firstWhere('id', $twoVariants->id)['frames'] === 2));

    // Lecture seule : rien n'a bougé.
    expect(Frame::query()->where('availability', ContentAvailability::Unpublished->value)->count())->toBe(0);
});
