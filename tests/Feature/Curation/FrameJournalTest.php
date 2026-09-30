<?php

use App\Actions\Curation\AddFrame;
use App\Actions\Curation\ChangeFrameLevel;
use App\Actions\Curation\RecropFrame;
use App\Actions\Curation\ReviewFrame;
use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Frames\FrameBank;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Journal des gestes sur les images — D41 du 30/09
|--------------------------------------------------------------------------
|
| Ajout, recadrage, relance, niveau et revue d'une image écrivent désormais
| leur ligne `admin_action`, dans la transaction du geste, signée du nom
| réel ; un refus relu sous verrou n'en écrit aucune. `details` garde ce que
| le geste écrase en place.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Le cadre par défaut d'un master 16:9 de 1920 × 1080.
 */
function frameJournalCrop(): CropRect
{
    return FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());
}

/**
 * Un cadre conforme plus serré, calé en haut à gauche.
 */
function frameJournalTighterCrop(): CropRect
{
    $width = frameJournalCrop()->width - 8 * FrameGeometry::ASPECT_WIDTH;

    return new CropRect(0, 0, $width, intdiv($width * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH));
}

/**
 * La seule ligne du journal pour cette action.
 */
function frameJournalLine(AdminActionType $action): AdminAction
{
    return AdminAction::query()->where('action', $action->value)->sole();
}

test('ajouter une image écrit frame.added avec sa voie et son niveau, jamais pour un doublon', function (): void {
    $movie = Movie::factory()->create();
    $bytes = SourceImages::jpeg(1920, 1080);
    $path = '/'.bin2hex(random_bytes(16)).'.jpg';

    $frame = app(AddFrame::class)->fromTmdb($movie, $this->curator, FrameLevel::Level5, frameJournalCrop(), $path, $bytes, null);

    $line = frameJournalLine(AdminActionType::FrameAdded);

    expect($line->actor_id)->toBe($this->curator->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->subject_type)->toBe(AdminActionSubject::Frame)
        ->and($line->subject_id)->toBe($frame->id)
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($line->details?->values)->toBe(['source_kind' => 'tmdb', 'frame_level' => 5]);

    // Le même visuel sous le même cadre : refusé sous verrou, aucune ligne.
    expect(fn () => app(AddFrame::class)->fromTmdb($movie, $this->curator, FrameLevel::Level5, frameJournalCrop(), $path, $bytes, null))
        ->toThrow(ValidationException::class);

    expect(AdminAction::query()->count())->toBe(1);

    // La voie capture écrit le même cas, sa voie dans `details` — d'autres
    // octets (métadonnées comprises), pour ne pas tomber sur le doublon.
    $capture = UploadedFile::fake()->createWithContent('capture.jpg', SourceImages::jpeg(1920, 1080, true));
    $captured = app(AddFrame::class)->fromCapture($movie, $this->curator, FrameLevel::Level1, frameJournalCrop(), $capture, 3_723_000, null);

    $second = AdminAction::query()->where('subject_id', $captured->id)->where('subject_type', AdminActionSubject::Frame->value)->sole();

    expect($second->action)->toBe(AdminActionType::FrameAdded)
        ->and($second->details?->values)->toBe(['source_kind' => 'capture', 'frame_level' => 1]);
});

test('recadrer une image non publiée écrit frame.recropped seule, l\'ancien cadre gardé', function (): void {
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create();
    $before = CropRect::fromFrame($frame);

    app(RecropFrame::class)->handle($frame, $this->curator, frameJournalTighterCrop(), null, null);

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::FrameRecropped)
        ->and($line->subject_id)->toBe($frame->id)
        ->and($line->reason)->toBeNull()
        ->and($line->details?->values)->toBe([
            'before' => $before->toArray(),
            'after' => frameJournalTighterCrop()->toArray(),
        ]);
});

test('recadrer une image publiée écrit frame.recropped et frame.unpublished, le motif recopié', function (): void {
    [, [$frame]] = FrameBank::publishedMovie([3, 3]);

    app(RecropFrame::class)->handle($frame, $this->curator, frameJournalTighterCrop(), 'Cadre trop large.', null);

    $lines = AdminAction::query()
        ->where('subject_type', AdminActionSubject::Frame->value)
        ->where('subject_id', $frame->id)
        ->orderBy('id')
        ->get();

    expect($lines->pluck('action')->all())->toBe([AdminActionType::FrameRecropped, AdminActionType::FrameUnpublished])
        ->and($lines[0]->reason)->toBe('Cadre trop large.');
});

test('changer le niveau d\'une image publiée écrit frame.level_changed et frame.unpublished', function (): void {
    [, [$frame]] = FrameBank::publishedMovie([3, 3]);

    app(ChangeFrameLevel::class)->handle($frame, $this->curator, FrameLevel::Level4);

    expect(frameJournalLine(AdminActionType::FrameLevelChanged)->details?->values)->toBe(['from' => 3, 'to' => 4])
        ->and(frameJournalLine(AdminActionType::FrameUnpublished)->subject_id)->toBe($frame->id);

    // Le même niveau ne change rien : aucune ligne de plus.
    app(ChangeFrameLevel::class)->handle($frame->refresh(), $this->curator, FrameLevel::Level4);

    expect(AdminAction::query()->count())->toBe(2);
});

test('relancer un traitement écrit frame.processing_retried avec l\'échec effacé, jamais un refus', function (): void {
    $movie = Movie::factory()->create();
    $retryable = Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::ResourceLimit)->create();
    $definitive = Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::CropInvalid)->create();

    $retry = fn (Frame $frame) => $this->actingAs($this->curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.retry', ['movie' => $movie->id, 'frame' => $frame->id]));

    $retry($definitive)->assertSessionHasErrors('frame');

    expect(AdminAction::query()->count())->toBe(0);

    $retry($retryable)->assertSessionHasNoErrors();

    $line = frameJournalLine(AdminActionType::FrameProcessingRetried);

    expect($line->subject_id)->toBe($retryable->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->details?->values)->toBe(['failure' => FrameProcessingFailure::ResourceLimit->value]);
});

test('une revue rejetée écrit frame.reviewed qui pointe sa preuve, une revue refusée rien', function (): void {
    $frame = Frame::factory()->for(Movie::factory()->create())->level(FrameLevel::Level3)->withFiles()->create();
    $answers = array_fill_keys(ExclusionGrid::slugsFor($frame->frame_level), true);
    $answers[array_key_first($answers)] = false;
    $source = ReviewQueue::declaredSource($frame)['reference'];

    // Des octets qui ne sont plus ceux affichés : refus, aucune ligne.
    expect(fn () => app(ReviewFrame::class)->handle($frame, $this->curator, ExclusionGrid::CURRENT_VERSION, str_repeat('0', 64), $answers, $source))
        ->toThrow(ValidationException::class);

    expect(AdminAction::query()->count())->toBe(0);

    $review = app(ReviewFrame::class)->handle($frame, $this->curator, ExclusionGrid::CURRENT_VERSION, (string) $frame->published_hash, $answers, $source);

    $line = frameJournalLine(AdminActionType::FrameReviewed);

    expect($line->subject_id)->toBe($frame->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->details?->values)->toBe([
            'review_id' => $review->id,
            'decision' => 'rejected',
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
        ]);
});
