<?php

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\Locale;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Curation\CoverageLossPreview;
use App\Support\Frames\FrameStoragePrefix;
use App\ValueObjects\Catalog\FrameLevelCoverage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Dépublier ou écarter une image — spec 20 § 8.4, E10-23, n° 4
|--------------------------------------------------------------------------
|
| Un seul geste : depuis `published`, l'image sort du jeu ; depuis `draft`,
| elle est écartée (`unpublished`, `first_published_at` NULL). Jamais une
| suppression. Un film publié qui perd sa couverture 1-3-5 reste publié,
| jouable jusqu'au plus grand `N` que sa banque couvre encore, et l'écran le
| dit avant confirmation (`CoverageLossPreview`).
|
*/

beforeEach(function (): void {
    // Les images de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);

    // Dépublier ne distribue aucun traitement.
    Queue::fake();
});

/**
 * L'envoi du geste, posté depuis la fiche du film.
 */
function frameUnpublishPost(Frame $frame, ?string $reason = null, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $frame->movie_id]))
        ->post(route('admin.catalog.frames.unpublish', ['movie' => $frame->movie_id, 'frame' => $frame->id]), [
            'reason' => $reason,
        ]);
}

test('dépublier une image écrit frame.unpublished et recalcule la projection', function (): void {
    $curator = User::factory()->curator()->create();
    [$movie, [, $level3a, $level3b]] = FrameBank::publishedMovie([1, 3, 3, 5]);

    expect(FrameBank::projection($movie)->level_3_variants)->toBe(2)
        ->and(FrameBank::projection($movie)->variants_total)->toBe(4);

    frameUnpublishPost($level3a, 'Texte incrusté repéré après publication.', $curator)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $level3a->refresh();

    expect($level3a->availability)->toBe(ContentAvailability::Unpublished)
        ->and($level3a->availability_changed_at)->not->toBeNull()
        ->and($level3a->first_published_at)->not->toBeNull()
        ->and($level3a->isServable())->toBeFalse();

    $line = AdminAction::query()->where('action', AdminActionType::FrameUnpublished->value)->sole();

    expect($line->subject_id)->toBe($level3a->id)
        ->and($line->actor_id)->toBe($curator->id)
        ->and($line->actor_name)->toBe($curator->real_name)
        ->and($line->reason)->toBe('Texte incrusté repéré après publication.');

    $projection = FrameBank::projection($movie);

    expect($projection->level_3_variants)->toBe(1)
        ->and($projection->variants_total)->toBe(3)
        ->and($level3b->refresh()->availability)->toBe(ContentAvailability::Published);

    // Le motif est facultatif (C14) : sans lui, la ligne s'écrit quand même.
    frameUnpublishPost($level3b, null, $curator)->assertSessionHasNoErrors();

    expect(AdminAction::query()->where('subject_id', $level3b->id)->sole()->reason)->toBeNull()
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(0);

    Queue::assertNothingPushed();
});

test('un film publié qui perd sa couverture 1-3-5 reste publié et se signale incomplet', function (): void {
    $curator = User::factory()->curator()->create();
    [$movie, [, , $level5]] = FrameBank::publishedMovie([1, 3, 5]);

    expect(FrameBank::projection($movie)->coversPublishableLevels())->toBeTrue();

    frameUnpublishPost($level5, null, $curator)->assertSessionHasNoErrors();

    // Aucune dépublication automatique : la couverture est une garde de
    // transition, jamais un invariant d'état (E10-23).
    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieUnpublished->value)->exists())->toBeFalse();

    // La fiche le signale « incomplet » : publié, sans la couverture 1-3-5,
    // encore jouable à N = 2 avec repli de niveau.
    $this->actingAs($curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('movie.availability', ContentAvailability::Published->value)
            ->where('projection.covers_publishable', false)
            ->where('projection.playable_at', [RoomSettingsBounds::MIN_FRAMES_PER_ROUND]));

    expect(FrameLevelCoverage::usesFallback(RoomSettingsBounds::MIN_FRAMES_PER_ROUND, FrameBank::projection($movie)->levels_mask))
        ->toBeTrue();
});

test('écarter une image jamais publiée la passe unpublished sans jamais la supprimer', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();
    $frame = Frame::factory()->for($movie)->withFiles()->create();
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    frameUnpublishPost($frame, 'Aucun cadre sans texte incrusté.', $curator)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $frame = Frame::query()->find($frame->id);

    // Écartée : `unpublished` sans `first_published_at`, la ligne et ses deux
    // fichiers intacts.
    expect($frame)->toBeInstanceOf(Frame::class)
        ->and($frame?->availability)->toBe(ContentAvailability::Unpublished)
        ->and($frame?->first_published_at)->toBeNull()
        ->and($disk->exists((string) $frame?->game_path))->toBeTrue()
        ->and($disk->exists((string) $frame?->master_path))->toBeTrue()
        ->and(AdminAction::query()->where('subject_id', $frame?->id)->sole()->reason)->toBe('Aucun cadre sans texte incrusté.');

    // Aucun chemin ne la supprime : ni policy, ni route.
    expect(Gate::forUser(User::factory()->admin()->create())->allows('delete', $frame))->toBeFalse();

    $deleting = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'admin.catalog.frames.')
            && in_array('DELETE', $route->methods(), true));

    expect($deleting)->toBeEmpty();

    // Une image écartée ne s'écarte pas deux fois : la garde la refuse.
    frameUnpublishPost($frame, null, $curator)->assertForbidden();

    expect(AdminAction::query()->where('subject_id', $frame?->id)->count())->toBe(1);
});

test('l\'avertissement de couverture nomme le plus grand N encore jouable', function (array $levels, int $target, int $expected): void {
    [$movie, $frames] = FrameBank::publishedMovie($levels);
    $frame = $frames[array_search($target, $levels, true)];

    $warning = app(CoverageLossPreview::class)->forFrame($frame);

    expect($warning)->toBe(['frame_id' => $frame->id, 'playable_up_to' => $expected]);

    $text = (string) __('admin.frame.unpublish.coverage_warning', ['max' => $expected], Locale::French->value);

    expect($text)->toContain('N = '.$expected)
        ->and($text)->not->toContain(':max');

    // Le geste confirme l'annonce : le film reste publié et joue exactement
    // jusqu'au N annoncé, pas un de plus.
    frameUnpublishPost($frame)->assertSessionHasNoErrors();

    $mask = FrameBank::projection($movie)->levels_mask;

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(CoverageLossPreview::playableUpTo($mask))->toBe($expected)
        ->and(FrameLevelCoverage::select($expected, $mask))->not->toBeNull();

    if ($expected < RoomSettingsBounds::MAX_FRAMES_PER_ROUND) {
        expect(FrameLevelCoverage::select($expected + 1, $mask))->toBeNull();
    }
})->with([
    'banque complète, niveau 3 retiré' => [[1, 2, 3, 4, 5], 3, 4],
    'banque complète, niveau 1 retiré' => [[1, 2, 3, 4, 5], 1, 4],
    'passe 1 seule, niveau 1 retiré' => [[1, 3, 5], 1, 2],
    'niveaux 1, 2, 3 et 5, niveau 5 retiré' => [[1, 2, 3, 5], 5, 3],
    'niveaux 1, 3, 4 et 5, niveau 1 retiré' => [[1, 3, 4, 5], 1, 3],
]);

test('aucun N jouable se dit par la variante sans nombre', function (): void {
    // Un masque d'un seul niveau ne joue à aucun N permis par RoomSettingsBounds.
    expect(CoverageLossPreview::playableUpTo(FrameLevel::Level1->bit()))->toBeNull()
        ->and(CoverageLossPreview::playableUpTo(0))->toBeNull();

    // Pour un geste sur une seule image, seul un film DÉJÀ incomplet y mène :
    // publié avec les niveaux 1 et 3 (jouable à N = 2), il perd sa seule
    // variante de niveau 1.
    [$movie, [$level1]] = FrameBank::publishedMovie([1, 3]);

    expect(FrameBank::projection($movie)->coversPublishableLevels())->toBeFalse()
        ->and(CoverageLossPreview::playableUpTo(FrameBank::projection($movie)->levels_mask))
        ->toBe(RoomSettingsBounds::MIN_FRAMES_PER_ROUND);

    expect(app(CoverageLossPreview::class)->forFrame($level1))
        ->toBe(['frame_id' => $level1->id, 'playable_up_to' => null]);

    $text = (string) __('admin.frame.unpublish.coverage_warning_unplayable', [], Locale::French->value);

    expect($text)->not->toBe('admin.frame.unpublish.coverage_warning_unplayable')
        ->and($text)->not->toContain(':max');

    // Le geste confirme l'annonce : le film reste publié, jouable à aucun N.
    frameUnpublishPost($level1)->assertSessionHasNoErrors();

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(CoverageLossPreview::playableUpTo(FrameBank::projection($movie)->levels_mask))->toBeNull();
});

test('un film déjà incomplet n\'est annoncé que s\'il cesse d\'être jouable', function (array $levels, int $target): void {
    [$movie, $frames] = FrameBank::publishedMovie($levels);
    $frame = $frames[array_search($target, $levels, true)];

    expect(FrameBank::projection($movie)->coversPublishableLevels())->toBeFalse()
        ->and(app(CoverageLossPreview::class)->forFrame($frame))->toBeNull();
})->with([
    'niveaux 1, 3 et 4, niveau 4 retiré : encore jouable à N = 2' => [[1, 3, 4], 4],
    'niveaux 1, 3 et 3, une variante de niveau 3 retirée' => [[1, 3, 3], 3],
    'niveau 3 seul : déjà injouable' => [[3], 3],
]);

test('dépublier une image suspendue, retirée ou déjà hors du jeu est refusé par la garde', function (ContentAvailability $state): void {
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create([
        'availability' => $state,
        'availability_changed_at' => now(),
    ]);

    frameUnpublishPost($frame)->assertForbidden();

    expect($frame->refresh()->availability)->toBe($state)
        ->and(AdminAction::query()->count())->toBe(0);
})->with([
    'suspendue' => ContentAvailability::Suspended,
    'retirée' => ContentAvailability::Withdrawn,
    'déjà dépubliée' => ContentAvailability::Unpublished,
]);
