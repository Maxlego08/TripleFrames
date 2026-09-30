<?php

use App\Actions\Curation\ChangeFrameLevel;
use App\Actions\Curation\ReviewFrame;
use App\Actions\Curation\UnpublishFrame;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Enums\Locale;
use App\Enums\ReviewDecision;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\User;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| Passer une revue d'image — spec 20 § 7.5 et § 7.8, contrat C14-bis
|--------------------------------------------------------------------------
|
| Une revue passante PUBLIE l'image dans la transaction qui écrit sa preuve ;
| une revue rejetée laisse l'état inchangé. La décision est dérivée par le
| serveur, jamais reçue. Chaque refus — octets, grille ou niveau changés,
| image déjà jugée — est relu sous le verrou et n'écrit AUCUNE preuve. Une
| preuve écrite ne se modifie ni ne se supprime.
|
*/

beforeEach(function (): void {
    // Les images de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);

    // Revoir ne distribue aucun traitement.
    Queue::fake();
});

/**
 * Une image prête, jamais revue : un brouillon « à revoir ».
 *
 * @param  array<string, mixed>  $attributes
 */
function frameReviewAwaiting(?Movie $movie = null, FrameLevel $level = FrameLevel::Level3, array $attributes = []): Frame
{
    return Frame::factory()
        ->for($movie ?? Movie::factory()->create())
        ->level($level)
        ->withFiles()
        ->create($attributes);
}

/**
 * La charge utile qu'enverrait l'écran de revue pour cette image, telle
 * qu'elle est en base à cet instant : tout conforme, sauf les points nommés.
 *
 * @param  list<string>  $failed
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frameReviewPayload(Frame $frame, array $failed = [], array $overrides = []): array
{
    $frame->refresh();

    $answers = [];

    foreach (ExclusionGrid::slugsFor($frame->frame_level) as $slug) {
        $answers[$slug] = ! in_array($slug, $failed, true);
    }

    return [
        'grid_version' => ExclusionGrid::CURRENT_VERSION,
        'reviewed_hash' => $frame->published_hash,
        'answers' => $answers,
        'declared_source_reference' => ReviewQueue::declaredSource($frame)['reference'],
        ...$overrides,
    ];
}

/**
 * L'envoi de la revue, posté depuis la file de revue.
 *
 * @param  array<string, mixed>  $payload
 */
function frameReviewPost(Frame $frame, array $payload, ?User $reviewer = null): TestResponse
{
    return test()
        ->actingAs($reviewer ?? User::factory()->curator()->create())
        ->from(route('admin.review.index'))
        ->post(route('admin.catalog.frames.review.store', ['movie' => $frame->movie_id, 'frame' => $frame->id]), $payload);
}

/**
 * Un texte du back-office, résolu en français — sa clé vérifiée d'abord :
 * une clé absente se rendrait telle quelle des deux côtés d'une comparaison.
 */
function frameReviewText(string $key): string
{
    expect(Lang::hasForLocale($key, Locale::French->value))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, [], Locale::French->value);
}

test('une revue passante publie dans la même transaction et pointe published_review_id', function (): void {
    $reviewer = User::factory()->curator()->create();
    $movie = Movie::factory()->create();
    $frame = frameReviewAwaiting($movie);
    $payload = frameReviewPayload($frame);

    // La revue suit l'ajout, comme dans la vraie vie.
    $this->travel(5)->minutes();

    frameReviewPost($frame, $payload, $reviewer)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.review.index'));

    $review = FrameReview::query()->sole();
    $frame->refresh();

    // La preuve : instantanés du relecteur, de la grille, des octets et de
    // la source ; réponses telles quelles ; décision dérivée.
    expect($review->frame_id)->toBe($frame->id)
        ->and($review->decision)->toBe(ReviewDecision::Passed)
        ->and($review->reviewer_id)->toBe($reviewer->id)
        ->and($review->reviewer_role)->toBe(UserRole::Curator)
        ->and($review->grid_version)->toBe(ExclusionGrid::CURRENT_VERSION)
        ->and($review->reviewed_hash)->toBe($frame->published_hash)
        ->and($review->declared_source_kind)->toBe(FrameSourceKind::Tmdb)
        ->and($review->declared_source_reference)->toBe($frame->tmdb_file_path)
        ->and($review->answers)->toBe($payload['answers']);

    // La publication : même instant serveur que la preuve, dont le prédicat
    // de la file dépend.
    expect($frame->availability)->toBe(ContentAvailability::Published)
        ->and($frame->published_review_id)->toBe($review->id)
        ->and($frame->first_published_at?->equalTo($review->reviewed_at))->toBeTrue()
        ->and($frame->availability_changed_at?->equalTo($review->reviewed_at))->toBeTrue()
        ->and($frame->reviewed_at?->equalTo($review->reviewed_at))->toBeTrue()
        ->and($frame->review_grid_version)->toBe(ExclusionGrid::CURRENT_VERSION)
        ->and($frame->isServable())->toBeTrue();

    // La projection du film, recalculée dans la transaction. La revue
    // passante EST la preuve de la publication ; le journal n'en porte que
    // l'index `frame.reviewed` (D41 du 30/09), qui la pointe.
    expect(FrameBank::projection($movie)->level_3_variants)->toBe(1);

    $line = AdminAction::query()->sole();

    expect($line->action)->toBe(AdminActionType::FrameReviewed)
        ->and($line->subject_id)->toBe($frame->id)
        ->and($line->details?->values)->toBe([
            'review_id' => $review->id,
            'decision' => 'passed',
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
        ]);

    // Une frame d'un film encore en brouillon se publie : c'est la passe 1.
    expect($movie->refresh()->availability)->toBe(ContentAvailability::Draft);

    // Même transaction : une panne du recalcul de la projection annule la
    // preuve ET la publication.
    $other = frameReviewAwaiting($movie, FrameLevel::Level5);
    $otherPayload = frameReviewPayload($other);

    Event::listen('eloquent.saving: '.MovieProjection::class, function (): never {
        throw new RuntimeException('Panne simulée du recalcul de projection.');
    });

    expect(fn () => app(ReviewFrame::class)->handle(
        $other,
        $reviewer,
        ExclusionGrid::CURRENT_VERSION,
        $otherPayload['reviewed_hash'],
        $otherPayload['answers'],
        $otherPayload['declared_source_reference'],
    ))->toThrow(RuntimeException::class, 'Panne simulée');

    expect($other->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($other->published_review_id)->toBeNull()
        ->and($other->reviews()->count())->toBe(0);
});

test('une empreinte périmée est refusée', function (): void {
    $frame = frameReviewAwaiting();
    $payload = frameReviewPayload($frame);

    // Un nouveau rendu a remplacé les octets affichés (re-recadrage traité
    // entre l'affichage et l'envoi).
    $frame->forceFill(['published_hash' => hash('sha256', 'nouveau rendu')])->save();

    frameReviewPost($frame, $payload)
        ->assertSessionHasErrors(['reviewed_hash' => frameReviewText('admin.review.stale')]);

    // Une empreinte qui n'a jamais été la sienne, pareil.
    frameReviewPost($frame, frameReviewPayload($frame, overrides: ['reviewed_hash' => str_repeat('0', 64)]))
        ->assertSessionHasErrors(['reviewed_hash' => frameReviewText('admin.review.stale')]);

    expect(FrameReview::query()->count())->toBe(0)
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Draft);
});

test('une version de grille périmée est refusée', function (): void {
    $frame = frameReviewAwaiting();

    foreach ([ExclusionGrid::CURRENT_VERSION - 1, ExclusionGrid::CURRENT_VERSION + 1] as $version) {
        frameReviewPost($frame, frameReviewPayload($frame, overrides: ['grid_version' => $version]))
            ->assertSessionHasErrors(['grid_version' => frameReviewText('admin.review.grid_version_outdated')]);
    }

    // L'action la relit aussi : elle ne publie jamais sous une grille que
    // l'écran n'a pas montrée.
    $payload = frameReviewPayload($frame);

    expect(fn () => app(ReviewFrame::class)->handle(
        $frame,
        User::factory()->curator()->create(),
        ExclusionGrid::CURRENT_VERSION + 1,
        $payload['reviewed_hash'],
        $payload['answers'],
        $payload['declared_source_reference'],
    ))->toThrow(ValidationException::class);

    expect(FrameReview::query()->count())->toBe(0)
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Draft);
});

test('la décision est dérivée côté serveur', function (): void {
    $movie = Movie::factory()->create();
    $conforming = frameReviewAwaiting($movie, FrameLevel::Level1);
    $failing = frameReviewAwaiting($movie, FrameLevel::Level3);

    // Un `decision` posté n'est jamais lu : tout conforme passe…
    frameReviewPost($conforming, frameReviewPayload($conforming, overrides: ['decision' => ReviewDecision::Rejected->value]))
        ->assertSessionHasNoErrors();

    // …et un point en défaut rejette, quoi que le client prétende.
    frameReviewPost($failing, frameReviewPayload($failing, ['no_identifying_text'], ['decision' => ReviewDecision::Passed->value]))
        ->assertSessionHasNoErrors();

    $passed = $conforming->reviews()->sole();
    $rejected = $failing->reviews()->sole();

    expect($passed->decision)->toBe(ReviewDecision::Passed)
        ->and($conforming->refresh()->availability)->toBe(ContentAvailability::Published);

    expect($rejected->decision)->toBe(ReviewDecision::Rejected)
        ->and($rejected->answers['no_identifying_text'])->toBeFalse()
        ->and($rejected->answers['no_title_card'])->toBeTrue()
        ->and($failing->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($failing->published_review_id)->toBeNull();

    // Des réponses « 1 » et « 0 » valent des booléens ; une réponse qui n'en
    // est pas un, ou un slug hors de la grille, est refusée sans preuve.
    $third = frameReviewAwaiting($movie, FrameLevel::Level5);

    frameReviewPost($third, frameReviewPayload($third, overrides: [
        'answers' => [...frameReviewPayload($third)['answers'], 'no_title_card' => 'peut-être'],
    ]))->assertSessionHasErrors('answers.no_title_card');

    frameReviewPost($third, frameReviewPayload($third, overrides: [
        'answers' => [...frameReviewPayload($third)['answers'], 'item_inconnu' => true],
    ]))->assertSessionHasErrors(['answers' => frameReviewText('admin.review.answers_invalid')]);

    expect($third->reviews()->count())->toBe(0);
});

test('frame_review n\'est ni modifiable ni supprimable', function (): void {
    frameReviewPost($frame = frameReviewAwaiting(), frameReviewPayload($frame))->assertSessionHasNoErrors();

    $review = FrameReview::query()->sole();
    $snapshot = $review->getAttributes();

    // La policy refuse la modification et la suppression, à l'administrateur
    // comme au curateur — aucun `Gate::before` ne la contourne.
    foreach ([User::factory()->curator()->create(), User::factory()->admin()->create()] as $user) {
        expect(Gate::forUser($user)->allows('update', $review))->toBeFalse()
            ->and(Gate::forUser($user)->allows('delete', $review))->toBeFalse();
    }

    // Le modèle refuse la réécriture d'une instance…
    $review->answers = ['no_title_card' => false];

    expect(fn () => $review->save())->toThrow(LogicException::class);

    // …et le constructeur de requêtes, la mise à jour de masse.
    expect(fn () => FrameReview::query()->whereKey($review->id)->update(['grid_version' => 99]))
        ->toThrow(LogicException::class);

    expect(FrameReview::query()->findOrFail($review->id)->getAttributes())->toBe($snapshot);

    // Aucune route ne modifie ni ne supprime une revue : la seule écriture
    // est l'ajout.
    $writes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (str_contains($route->uri(), 'review')) {
            $writes = [...$writes, ...array_diff($route->methods(), ['GET', 'HEAD'])];
        }
    }

    expect(array_values(array_unique($writes)))->toBe(['POST']);
});

test('toute publication de frame insère sa propre ligne de revue', function (): void {
    $curator = User::factory()->curator()->create();
    $frame = frameReviewAwaiting();

    // Première publication.
    $this->travel(1)->minutes();
    frameReviewPost($frame, frameReviewPayload($frame), $curator)->assertSessionHasNoErrors();

    $first = $frame->refresh()->published_review_id;
    $firstPublishedAt = $frame->first_published_at?->toIso8601String();

    // Dépubliée, elle ne revient en jeu que par une NOUVELLE revue.
    $this->travel(1)->minutes();
    app(UnpublishFrame::class)->handle($frame, $curator, null);

    $this->travel(1)->minutes();
    frameReviewPost($frame, frameReviewPayload($frame), $curator)->assertSessionHasNoErrors();

    $second = $frame->refresh()->published_review_id;

    expect($frame->availability)->toBe(ContentAvailability::Published)
        ->and($second)->not->toBe($first)
        ->and($frame->first_published_at?->toIso8601String())->toBe($firstPublishedAt);

    // Une re-revue d'une image en jeu insère une ligne et la repointe, sans
    // jamais la sortir du jeu.
    $frame->forceFill(['review_grid_version' => ExclusionGrid::CURRENT_VERSION - 1])->save();
    $changedAt = $frame->availability_changed_at?->toIso8601String();

    $this->travel(1)->minutes();
    frameReviewPost($frame, frameReviewPayload($frame), $curator)->assertSessionHasNoErrors();

    $third = $frame->refresh()->published_review_id;

    expect($third)->not->toBe($second)
        ->and($frame->availability)->toBe(ContentAvailability::Published)
        ->and($frame->availability_changed_at?->toIso8601String())->toBe($changedAt)
        ->and($frame->review_grid_version)->toBe(ExclusionGrid::CURRENT_VERSION);

    // Trois publications, trois preuves passantes distinctes, chacune sur
    // les octets en jeu ; aucune ligne n'a été réécrite.
    $reviews = $frame->reviews()->orderBy('id')->get();

    expect($reviews->pluck('id')->all())->toBe([$first, $second, $third])
        ->and($reviews->every(fn (FrameReview $review): bool => $review->decision === ReviewDecision::Passed
            && $review->reviewed_hash === $frame->published_hash))->toBeTrue();
});

test('reviewer_name est le nom réel', function (): void {
    $curator = User::factory()->curator()->create([
        'name' => 'pseudo-du-compte',
        'real_name' => 'Camille Martin',
    ]);
    $admin = User::factory()->admin()->create(['real_name' => 'Dominique Leroy']);

    $movie = Movie::factory()->create();
    $byCurator = frameReviewAwaiting($movie, FrameLevel::Level1);
    $byAdmin = frameReviewAwaiting($movie, FrameLevel::Level5);

    frameReviewPost($byCurator, frameReviewPayload($byCurator), $curator)->assertSessionHasNoErrors();
    frameReviewPost($byAdmin, frameReviewPayload($byAdmin, ['no_credits']), $admin)->assertSessionHasNoErrors();

    $curatorReview = $byCurator->reviews()->sole();
    $adminReview = $byAdmin->reviews()->sole();

    // Le nom réel, jamais le pseudo de compte ; le rôle courant.
    expect($curatorReview->reviewer_name)->toBe('Camille Martin')
        ->and($curatorReview->reviewer_name)->not->toBe($curator->name)
        ->and($curatorReview->reviewer_role)->toBe(UserRole::Curator)
        ->and($adminReview->reviewer_name)->toBe('Dominique Leroy')
        ->and($adminReview->reviewer_role)->toBe(UserRole::Admin);

    // Un instantané : corriger le nom réel ne réécrit aucune preuve signée.
    $curator->forceFill(['real_name' => 'Camille Durand'])->save();

    expect($curatorReview->refresh()->reviewer_name)->toBe('Camille Martin');
});

test('une revue envoyée après un changement de niveau est refusée sans écrire de preuve', function (): void {
    $curator = User::factory()->curator()->create();

    // Un brouillon affiché au niveau 3, reclassé au niveau 1 dans un second
    // onglet : `no_lead_face` s'applique désormais, et la revue affichée n'y
    // répond pas.
    $draft = frameReviewAwaiting(level: FrameLevel::Level3);
    $shown = frameReviewPayload($draft);

    app(ChangeFrameLevel::class)->handle($draft, $curator, FrameLevel::Level1);

    frameReviewPost($draft, $shown, $curator)
        ->assertSessionHasErrors(['answers' => frameReviewText('admin.review.level_changed')]);

    // L'inverse aussi : une revue d'un niveau 2 envoyée sur un niveau 4.
    [, [$published]] = FrameBank::publishedMovie([2]);
    $this->travel(1)->minutes();
    app(ChangeFrameLevel::class)->handle($published, $curator, FrameLevel::Level4);

    $stale = frameReviewPayload($published);
    $stale['answers'] = array_fill_keys(ExclusionGrid::slugsFor(FrameLevel::Level2), true);

    frameReviewPost($published, $stale, $curator)
        ->assertSessionHasErrors(['answers' => frameReviewText('admin.review.level_changed')]);

    // Aucune preuve — ni passante ni rejetée —, et rien n'est remis en jeu.
    expect($draft->reviews()->count())->toBe(0)
        ->and($draft->refresh()->availability)->toBe(ContentAvailability::Draft)
        ->and($published->reviews()->count())->toBe(1)
        ->and($published->refresh()->availability)->toBe(ContentAvailability::Unpublished);

    // Revue au niveau courant : elle passe.
    frameReviewPost($draft, frameReviewPayload($draft), $curator)->assertSessionHasNoErrors();

    expect($draft->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($draft->reviews()->sole()->answers)->toHaveKey('no_lead_face');
});

test('une seconde revue passante sur les mêmes octets est refusée', function (): void {
    $frame = frameReviewAwaiting();
    $payload = frameReviewPayload($frame);

    frameReviewPost($frame, $payload)->assertSessionHasNoErrors();

    // Le double clic, ou un second onglet resté sur l'image.
    frameReviewPost($frame, $payload)
        ->assertSessionHasErrors(['frame' => frameReviewText('admin.review.already_reviewed')]);

    expect($frame->reviews()->count())->toBe(1)
        ->and($frame->refresh()->published_review_id)->toBe($frame->reviews()->sole()->id);
});

test('un double rejet n\'écrit qu\'une preuve', function (): void {
    $frame = frameReviewAwaiting();
    $rejection = frameReviewPayload($frame, ['no_studio_logo']);

    frameReviewPost($frame, $rejection)->assertSessionHasNoErrors();

    frameReviewPost($frame, $rejection)
        ->assertSessionHasErrors(['frame' => frameReviewText('admin.review.already_reviewed')]);

    // Même un rejet sur d'autres points : l'image est déjà dans « Rejetées ».
    frameReviewPost($frame, frameReviewPayload($frame, ['no_credits']))
        ->assertSessionHasErrors(['frame' => frameReviewText('admin.review.already_reviewed')]);

    expect($frame->reviews()->count())->toBe(1)
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Draft);

    // Seule une revue passante (« Revoir ») peut encore partir.
    frameReviewPost($frame, frameReviewPayload($frame))->assertSessionHasNoErrors();

    expect($frame->reviews()->count())->toBe(2)
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Published);
});

test('une image verrouillée, non prête ou à la source contestée est refusée sans preuve', function (ContentAvailability|FrameProcessingState|string $case): void {
    $movie = Movie::factory()->create();
    $frame = frameReviewAwaiting($movie);
    $payload = frameReviewPayload($frame);
    $expected = ['frame' => frameReviewText('admin.review.locked')];

    match (true) {
        $case instanceof ContentAvailability => $frame->forceFill(['availability' => $case])->save(),
        $case instanceof FrameProcessingState => $frame->forceFill(['processing_state' => $case])->save(),
        $case === 'movie_suspended' => $movie->forceFill(['availability' => ContentAvailability::Suspended])->save(),
        $case === 'movie_withdrawn' => $movie->forceFill(['availability' => ContentAvailability::Withdrawn])->save(),
        default => null,
    };

    if ($case === 'source') {
        $payload['declared_source_reference'] = '/un-autre-visuel.jpg';
        $expected = ['declared_source_reference' => frameReviewText('admin.review.source_mismatch')];
    }

    frameReviewPost($frame, $payload)->assertSessionHasErrors($expected);

    expect($frame->reviews()->count())->toBe(0);
})->with([
    'image suspendue' => ContentAvailability::Suspended,
    'image retirée' => ContentAvailability::Withdrawn,
    'image en traitement' => FrameProcessingState::Pending,
    'film suspendu' => 'movie_suspended',
    'film retiré' => 'movie_withdrawn',
    'source contestée' => 'source',
]);
