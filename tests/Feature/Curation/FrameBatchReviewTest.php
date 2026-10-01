<?php

use App\Actions\Curation\PublishMovie;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\FrameSourceKind;
use App\Enums\Locale;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Valider en lot les images d'un film — D42 du 30/09, spec 20 § 7.9
|--------------------------------------------------------------------------
|
| « Tout valider » écrit UNE revue passante par image en attente — grille
| courante, « rien à signaler » à son niveau —, publie chaque image comme la
| revue unitaire, et consigne le lot par UNE ligne `movie.frames_reviewed`,
| sans aucune `frame.reviewed`. Tout ou rien, relu sous le verrou : une
| liste périmée ou une empreinte changée refuse le lot sans rien écrire. La
| publication du film reste un geste distinct.
|
*/

beforeEach(function (): void {
    // Les images de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);

    // Revoir ne distribue aucun traitement.
    Queue::fake();
});

/**
 * Un film au contenu vérifié par certification et aux clés de réponse
 * projetées : seules ses images manquent à sa publication.
 */
function batchReviewMovie(): Movie
{
    $movie = Movie::factory()->withCertification()->contentFlag(ContentFlag::Clear)->create();

    (new AnswerKeyProjector)->project($movie);

    return $movie->refresh();
}

/**
 * Une image prête, jamais revue : un brouillon « à revoir ».
 *
 * @param  array<string, mixed>  $attributes
 */
function batchReviewAwaiting(Movie $movie, FrameLevel $level, array $attributes = []): Frame
{
    return Frame::factory()->for($movie)->level($level)->withFiles()->create($attributes);
}

/**
 * La charge qu'enverrait la confirmation : le lot tel qu'il est en base.
 *
 * @return array{frames: list<array{id: int, hash: string}>}
 */
function batchReviewPayload(Movie $movie): array
{
    return [
        'frames' => array_map(static fn (Frame $frame): array => [
            'id' => $frame->id,
            'hash' => (string) $frame->published_hash,
        ], ReviewQueue::batchOf($movie)),
    ];
}

/**
 * L'envoi du lot, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function batchReviewPost(Movie $movie, array $payload, ?User $reviewer = null): TestResponse
{
    return test()
        ->actingAs($reviewer ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.review_all', ['movie' => $movie->id]), $payload);
}

/**
 * Un texte du back-office, résolu en français — sa clé vérifiée d'abord.
 *
 * @param  array<string, int|string>  $replace
 */
function batchReviewText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, Locale::French->value);
}

/**
 * Rien n'a été écrit : aucune preuve, aucune ligne de journal, images en
 * l'état.
 *
 * @param  list<Frame>  $frames
 */
function batchReviewNothingWritten(array $frames): void
{
    expect(FrameReview::query()->count())->toBe(0)
        ->and(AdminAction::query()->count())->toBe(0);

    foreach ($frames as $frame) {
        expect($frame->refresh()->availability)->toBe(ContentAvailability::Draft)
            ->and($frame->published_review_id)->toBeNull();
    }
}

test('tout valider écrit une revue passante par image, les publie et consigne une seule ligne movie.frames_reviewed', function (): void {
    $reviewer = User::factory()->curator()->create();
    $movie = batchReviewMovie();

    $level1 = batchReviewAwaiting($movie, FrameLevel::Level1);
    $level3 = batchReviewAwaiting($movie, FrameLevel::Level3);
    $capture = Frame::factory()->for($movie)->level(FrameLevel::Level5)->capture(3_723_000)->withFiles()->create();

    // Une image en jeu revue sous une grille antérieure : « à re-revoir »,
    // elle entre aussi dans le lot, et reste en jeu.
    $outdated = Frame::factory()->for($movie)->level(FrameLevel::Level2)->published()->create();
    $outdated->forceFill(['review_grid_version' => ExclusionGrid::CURRENT_VERSION - 1])->save();

    $payload = batchReviewPayload($movie);

    expect(array_column($payload['frames'], 'id'))
        ->toBe([$level1->id, $level3->id, $capture->id, $outdated->id]);

    $this->travel(5)->minutes();

    batchReviewPost($movie, $payload, $reviewer)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertiaFlash('toast.message', trans_choice('admin.review.batch.flash', 4, ['count' => 4], Locale::French->value));

    // Une preuve par image, exactement celle de la revue unitaire : nom réel,
    // rôle, grille courante, empreinte, source déclarée, « rien à signaler »
    // sur chaque item du niveau.
    $reviews = FrameReview::query()->where('reviewer_id', $reviewer->id)->orderBy('frame_id')->get();

    expect($reviews)->toHaveCount(4);

    foreach ([$level1, $level3, $capture, $outdated] as $frame) {
        $frame->refresh();
        $review = $reviews->firstWhere('frame_id', $frame->id);

        expect($review)->toBeInstanceOf(FrameReview::class)
            ->and($review->decision)->toBe(ReviewDecision::Passed)
            ->and($review->reviewer_name)->toBe(trim((string) $reviewer->real_name))
            ->and($review->reviewer_role)->toBe(UserRole::Curator)
            ->and($review->grid_version)->toBe(ExclusionGrid::CURRENT_VERSION)
            ->and($review->reviewed_hash)->toBe($frame->published_hash)
            ->and($review->declared_source_kind)->toBe($frame->source_kind)
            ->and($review->declared_source_reference)->toBe(ReviewQueue::declaredSource($frame)['reference'])
            ->and($review->answers)->toBe(array_fill_keys(ExclusionGrid::slugsFor($frame->frame_level), true));

        // La publication de l'image, au même instant serveur que la preuve.
        expect($frame->availability)->toBe(ContentAvailability::Published)
            ->and($frame->published_review_id)->toBe($review->id)
            ->and($frame->reviewed_at?->equalTo($review->reviewed_at))->toBeTrue()
            ->and($frame->review_grid_version)->toBe(ExclusionGrid::CURRENT_VERSION);
    }

    // Le minutage de la capture est la source déclarée.
    expect($reviews->firstWhere('frame_id', $capture->id)?->declared_source_kind)->toBe(FrameSourceKind::Capture)
        ->and($reviews->firstWhere('frame_id', $capture->id)?->declared_source_reference)->toBe('1:02:03');

    // La projection du film est recalculée dans la transaction.
    expect(FrameBank::projection($movie)->coversPublishableLevels())->toBeTrue();

    // UNE ligne pour le lot, sujet le film ; aucune `frame.reviewed`.
    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::MovieFramesReviewed)
        ->and($line->subject_type)->toBe(AdminActionSubject::Movie)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->actor_name)->toBe(trim((string) $reviewer->real_name))
        ->and($line->reason)->toBeNull()
        ->and($line->details?->values)->toBe([
            'frame_ids' => [$level1->id, $level3->id, $capture->id, $outdated->id],
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
        ])
        ->and(AdminAction::query()->where('action', AdminActionType::FrameReviewed->value)->count())->toBe(0);

    // Plus rien à valider : le lot est vide.
    expect(ReviewQueue::batchOf($movie))->toBe([]);
});

test('les images en traitement, en échec, rejetées, hors jeu ou sans source ne font pas partie du lot', function (): void {
    $movie = batchReviewMovie();

    $awaiting = batchReviewAwaiting($movie, FrameLevel::Level1);

    // En traitement : aucun dérivé encore.
    Frame::factory()->for($movie)->level(FrameLevel::Level2)->create();

    // En échec.
    Frame::factory()->for($movie)->level(FrameLevel::Level2)->processingFailed()->create();

    // Retirée par un administrateur.
    batchReviewAwaiting($movie, FrameLevel::Level4)->forceFill([
        'availability' => ContentAvailability::Withdrawn,
        'availability_changed_at' => now(),
    ])->save();

    // Suspendue par un administrateur.
    batchReviewAwaiting($movie, FrameLevel::Level4)->forceFill([
        'availability' => ContentAvailability::Suspended,
        'availability_changed_at' => now(),
    ])->save();

    // Écartée : jugée, et qui n'y revient jamais.
    batchReviewAwaiting($movie, FrameLevel::Level3)->forceFill([
        'availability' => ContentAvailability::Unpublished,
        'availability_changed_at' => now(),
    ])->save();

    // Déjà en jeu, revue sous la grille courante.
    Frame::factory()->for($movie)->level(FrameLevel::Level5)->published()->create();

    // Capture sans minutage : aucune source exploitable.
    Frame::factory()->for($movie)->level(FrameLevel::Level3)->capture()->withFiles()->create([
        'source_timecode_ms' => null,
    ]);

    // Rejetée à sa dernière revue : un rejet se lève image par image.
    $rejected = batchReviewAwaiting($movie, FrameLevel::Level5);
    $this->travel(1)->minutes();
    FrameReview::factory()->forFrame($rejected)->rejected()->create(['reviewed_at' => now()]);

    expect(ReviewQueue::listsOf($rejected->refresh(), $movie, ReviewQueue::judgingReviewFor($rejected)?->decision))
        ->toContain(ReviewList::Rejected);

    $payload = batchReviewPayload($movie);

    expect(array_column($payload['frames'], 'id'))->toBe([$awaiting->id]);

    $before = FrameReview::query()->count();

    batchReviewPost($movie, $payload)->assertSessionHasNoErrors();

    expect(FrameReview::query()->count())->toBe($before + 1)
        ->and(FrameReview::query()->latest('id')->first()?->frame_id)->toBe($awaiting->id)
        ->and($rejected->refresh()->availability)->toBe(ContentAvailability::Draft);

    // Un film suspendu n'a aucun lot : toutes ses images sont verrouillées.
    $suspended = batchReviewMovie();
    batchReviewAwaiting($suspended, FrameLevel::Level1);
    $suspended->forceFill([
        'availability' => ContentAvailability::Suspended,
        'availability_changed_at' => now(),
        'availability_reason' => 'Suspendu le temps d’instruire un signalement.',
    ])->save();

    expect(ReviewQueue::batchOf($suspended))->toBe([]);
});

test('une empreinte changée depuis l’affichage refuse tout le lot sans rien écrire', function (): void {
    $movie = batchReviewMovie();
    $first = batchReviewAwaiting($movie, FrameLevel::Level1);
    $second = batchReviewAwaiting($movie, FrameLevel::Level3);

    $payload = batchReviewPayload($movie);
    $payload['frames'][1]['hash'] = hash('sha256', 'un autre rendu');

    batchReviewPost($movie, $payload)
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertSessionHasErrors(['frames' => batchReviewText('admin.review.batch.stale')]);

    batchReviewNothingWritten([$first, $second]);
});

test('une liste qui ne correspond plus aux images en attente refuse tout le lot sans rien écrire', function (): void {
    $movie = batchReviewMovie();
    $first = batchReviewAwaiting($movie, FrameLevel::Level1);
    $second = batchReviewAwaiting($movie, FrameLevel::Level3);

    // Une image est arrivée depuis l'affichage : la liste montrée est courte.
    $payload = batchReviewPayload($movie);
    $late = batchReviewAwaiting($movie, FrameLevel::Level5);

    batchReviewPost($movie, $payload)
        ->assertSessionHasErrors(['frames' => batchReviewText('admin.review.batch.stale_list')]);

    batchReviewNothingWritten([$first, $second, $late]);

    // Une image qui n'est pas du lot — d'un autre film — est refusée de même.
    $other = batchReviewAwaiting(batchReviewMovie(), FrameLevel::Level1);
    $payload = batchReviewPayload($movie);
    $payload['frames'][] = ['id' => $other->id, 'hash' => (string) $other->published_hash];

    batchReviewPost($movie, $payload)
        ->assertSessionHasErrors(['frames' => batchReviewText('admin.review.batch.stale_list')]);

    batchReviewNothingWritten([$first, $second, $late, $other]);
});

test('un film sans image en attente n’a rien à valider : pas de bouton, et l’envoi est refusé', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = batchReviewMovie();
    $published = Frame::factory()->for($movie)->level(FrameLevel::Level1)->published()->create();

    $this->actingAs($curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/catalog/show')
            ->where('review_batch', null));

    $reviews = FrameReview::query()->count();

    batchReviewPost($movie, ['frames' => [['id' => $published->id, 'hash' => (string) $published->published_hash]]], $curator)
        ->assertSessionHasErrors(['frames' => batchReviewText('admin.review.batch.empty')]);

    expect(FrameReview::query()->count())->toBe($reviews)
        ->and(AdminAction::query()->count())->toBe(0);

    // Une liste vide ne part même pas.
    batchReviewPost($movie, ['frames' => []], $curator)->assertSessionHasErrors('frames');
});

test('curateur et administrateur valident en lot, un joueur est refusé', function (): void {
    $movie = batchReviewMovie();
    $frame = batchReviewAwaiting($movie, FrameLevel::Level1);
    $payload = batchReviewPayload($movie);

    batchReviewPost($movie, $payload, User::factory()->player()->create())->assertForbidden();

    batchReviewNothingWritten([$frame]);

    batchReviewPost($movie, $payload, User::factory()->admin()->create())->assertSessionHasNoErrors();

    expect(FrameReview::query()->sole()->reviewer_role)->toBe(UserRole::Admin);

    $other = batchReviewMovie();
    batchReviewAwaiting($other, FrameLevel::Level3);

    batchReviewPost($other, batchReviewPayload($other), User::factory()->curator()->create())
        ->assertSessionHasNoErrors();

    expect(FrameReview::query()->count())->toBe(2)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieFramesReviewed->value)->count())->toBe(2);
});

test('la fiche et la file proposent le lot, puis la fiche propose Publier sans jamais publier le film', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = batchReviewMovie();

    $frames = [
        batchReviewAwaiting($movie, FrameLevel::Level1),
        batchReviewAwaiting($movie, FrameLevel::Level3),
        batchReviewAwaiting($movie, FrameLevel::Level5),
    ];

    $expected = array_map(static fn (Frame $frame): array => [
        'id' => $frame->id,
        'hash' => (string) $frame->published_hash,
    ], $frames);

    // Avant : le lot est proposé, la publication bloquée par la couverture.
    $this->actingAs($curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review_batch.grid_version', ExclusionGrid::CURRENT_VERSION)
            ->where('review_batch.frames', $expected)
            ->where('publication.blockers', [PublishMovie::COVERAGE_MISSING]));

    $this->actingAs($curator)
        ->get(route('admin.review.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/review/index')
            ->has('review_batches', 1)
            ->where('review_batches.0.movie_id', $movie->id)
            ->where('review_batches.0.frames', $expected));

    batchReviewPost($movie, batchReviewPayload($movie), $curator)->assertSessionHasNoErrors();

    // Après : plus de lot, « Publier » proposé — et le film reste un
    // brouillon : sa publication est un geste distinct.
    $this->actingAs($curator)
        ->get(route('admin.catalog.show', ['movie' => $movie->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('review_batch', null)
            ->where('abilities.publish', true)
            ->where('publication.blockers', []));

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and(AdminAction::query()->where('action', AdminActionType::MoviePublished->value)->count())->toBe(0);
});

test('au-delà du plafond d’un lot, le bouton n’est pas proposé : l’envoi serait toujours refusé', function (): void {
    $frames = static fn (int $count): array => array_map(
        static fn (int $id): Frame => (new Frame)->forceFill(['id' => $id, 'published_hash' => str_repeat('a', 64)]),
        range(1, $count),
    );

    expect(AdminCatalogPresenter::reviewBatch($frames(ReviewQueue::BATCH_MAX_FRAMES)))
        ->not->toBeNull()
        ->and(AdminCatalogPresenter::reviewBatch($frames(ReviewQueue::BATCH_MAX_FRAMES + 1)))
        ->toBeNull();

    $movie = batchReviewMovie();
    $frame = batchReviewAwaiting($movie, FrameLevel::Level1);
    $payload = ['frames' => array_map(
        static fn (int $index): array => ['id' => $frame->id + $index, 'hash' => (string) $frame->published_hash],
        range(0, ReviewQueue::BATCH_MAX_FRAMES),
    )];

    batchReviewPost($movie, $payload)->assertSessionHasErrors('frames');

    batchReviewNothingWritten([$frame]);
});
