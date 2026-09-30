<?php

use App\Actions\Curation\ChangeFrameLevel;
use App\Actions\Curation\UnpublishFrame;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\ReviewDecision;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\ExclusionGrid;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| La file de revue — spec 20 § 7.3, § 7.6 et § 7.7
|--------------------------------------------------------------------------
|
| Trois listes dérivées, sans aucune colonne : « À revoir » (prêtes, hors du
| jeu, sans revue qui les juge depuis leur dernier changement d'état),
| « À re-revoir » (en jeu sous une ancienne grille), « Rejetées » (dont la
| revue qui les juge est rejetée). Une image écartée, suspendue, retirée ou
| d'un film suspendu ou retiré n'y paraît jamais. `ReviewQueue` est leur
| seul porteur, relu sous verrou par la revue elle-même.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    // Les images de fixture écrivent leurs octets : sur un disque faux.
    Storage::fake(FrameStoragePrefix::DISK);

    // Aucun geste d'ici ne distribue de traitement.
    Queue::fake();

    $this->curator = User::factory()->curator()->create();
});

/**
 * Les identifiants des images d'une liste, dans l'ordre de la file, lus par
 * une file neuve : l'état de la base à cet instant.
 *
 * @return list<int>
 */
function reviewQueueIds(ReviewList $list): array
{
    return array_map(static fn (Frame $frame): int => $frame->id, (new ReviewQueue)->frames($list));
}

/**
 * Les listes où figure une image.
 *
 * @return list<ReviewList>
 */
function reviewQueueListsOf(Frame $frame): array
{
    return array_values(array_filter(
        ReviewList::cases(),
        static fn (ReviewList $list): bool => in_array($frame->id, reviewQueueIds($list), true),
    ));
}

/**
 * Une revue passée depuis l'écran, tout conforme sauf les points nommés.
 *
 * @param  list<string>  $failed
 */
function reviewQueuePost(Frame $frame, User $reviewer, array $failed = []): TestResponse
{
    $frame->refresh();

    $answers = [];

    foreach (ExclusionGrid::slugsFor($frame->frame_level) as $slug) {
        $answers[$slug] = ! in_array($slug, $failed, true);
    }

    return test()
        ->actingAs($reviewer)
        ->from(route('admin.review.index'))
        ->post(route('admin.catalog.frames.review.store', ['movie' => $frame->movie_id, 'frame' => $frame->id]), [
            'grid_version' => ExclusionGrid::CURRENT_VERSION,
            'reviewed_hash' => $frame->published_hash,
            'answers' => $answers,
            'declared_source_reference' => ReviewQueue::declaredSource($frame)['reference'],
        ]);
}

test('la file à revoir ne montre que les images prêtes sans revue depuis leur dernier changement d\'état', function (): void {
    $movie = Movie::factory()->create();
    $factory = Frame::factory()->for($movie);

    // Dans la file.
    $fresh = $factory->withFiles()->create();
    $reviewedOtherBytes = $factory->withFiles()->create();
    FrameReview::factory()->forFrame($reviewedOtherBytes)->rejected()->create(['reviewed_hash' => hash('sha256', 'ancien rendu')]);
    $reviewedOtherGrid = $factory->withFiles()->create();
    FrameReview::factory()->forFrame($reviewedOtherGrid)->rejected()->gridVersion(ExclusionGrid::CURRENT_VERSION - 1)->create();

    // Hors de la file.
    $pending = $factory->create();
    $failed = $factory->processingFailed(FrameProcessingFailure::CropInvalid)->create();
    $published = $factory->published()->create();
    $rejected = $factory->withFiles()->create();
    FrameReview::factory()->forFrame($rejected)->rejected()->create();

    // Dépubliée APRÈS sa revue passante : sa sortie du jeu la renvoie en
    // revue, octets et grille inchangés.
    $unpublished = $factory->published()->create();
    $this->travel(5)->minutes();
    app(UnpublishFrame::class)->handle($unpublished, $this->curator, null);

    expect(reviewQueueIds(ReviewList::ToReview))
        ->toEqualCanonicalizing([$fresh->id, $reviewedOtherBytes->id, $reviewedOtherGrid->id, $unpublished->id]);

    foreach ([$pending, $failed, $published, $rejected] as $absent) {
        expect(reviewQueueIds(ReviewList::ToReview))->not->toContain($absent->id);
    }

    // Une revue au même instant que le changement d'état le juge encore
    // (« postérieure OU ÉGALE ») : la revue passante écrit les deux dates au
    // même instant serveur.
    $sameSecond = $factory->withFiles()->create(['availability_changed_at' => now()]);
    FrameReview::factory()->forFrame($sameSecond)->rejected()->create(['reviewed_at' => $sameSecond->availability_changed_at]);

    expect(reviewQueueListsOf($sameSecond))->toBe([ReviewList::Rejected]);
});

test('une image publiée changée de niveau réapparaît à revoir', function (): void {
    [, [$level1, $level3, $level5]] = FrameBank::publishedMovie([1, 3, 5]);

    foreach ([$level1, $level3, $level5] as $frame) {
        expect(reviewQueueListsOf($frame))->toBe([]);
    }

    $this->travel(5)->minutes();
    app(ChangeFrameLevel::class)->handle($level3, $this->curator, FrameLevel::Level4);

    // Ni ses octets ni la version de la grille n'ont changé : seule sa sortie
    // du jeu, datée, la renvoie en revue.
    expect(reviewQueueListsOf($level3->refresh()))->toBe([ReviewList::ToReview])
        ->and($level3->reviews()->where('decision', ReviewDecision::Passed->value)->count())->toBe(1);

    $this->travel(1)->minutes();
    reviewQueuePost($level3, $this->curator)->assertSessionHasNoErrors();

    expect($level3->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and($level3->frame_level)->toBe(FrameLevel::Level4)
        ->and(reviewQueueListsOf($level3))->toBe([]);
});

test('une image dépubliée se republie par la file', function (): void {
    [$movie, [$frame]] = FrameBank::publishedMovie([3]);
    $firstPublishedAt = $frame->first_published_at?->toIso8601String();
    $firstReview = $frame->published_review_id;

    $this->travel(5)->minutes();
    app(UnpublishFrame::class)->handle($frame, $this->curator, 'Image à revoir.');

    expect(reviewQueueListsOf($frame->refresh()))->toBe([ReviewList::ToReview])
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(0);

    // Rejetée d'abord : elle passe dans « Rejetées », dépubliée — ni écartée,
    // ni hors de toute liste —, et reste hors du jeu.
    $this->travel(1)->minutes();
    reviewQueuePost($frame, $this->curator, ['no_watermark_or_copyright'])->assertSessionHasNoErrors();

    expect(reviewQueueListsOf($frame->refresh()))->toBe([ReviewList::Rejected])
        ->and($frame->availability)->toBe(ContentAvailability::Unpublished);

    // « Revoir » : une revue conforme la remet en jeu.
    $this->travel(1)->minutes();
    reviewQueuePost($frame, $this->curator)->assertSessionHasNoErrors();

    $frame->refresh();

    // De retour en jeu par une NOUVELLE preuve ; sa première publication
    // n'est jamais réécrite.
    expect($frame->availability)->toBe(ContentAvailability::Published)
        ->and($frame->published_review_id)->not->toBe($firstReview)
        ->and($frame->first_published_at?->toIso8601String())->toBe($firstPublishedAt)
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(1)
        ->and(reviewQueueListsOf($frame))->toBe([]);
});

test('une image écartée n\'apparaît jamais à revoir', function (): void {
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create();

    expect(reviewQueueListsOf($frame))->toBe([ReviewList::ToReview]);

    // Écartée : `unpublished` sans avoir jamais été publiée.
    $this->travel(1)->minutes();
    app(UnpublishFrame::class)->handle($frame, $this->curator, null);

    expect($frame->refresh()->availability)->toBe(ContentAvailability::Unpublished)
        ->and($frame->first_published_at)->toBeNull()
        ->and(reviewQueueListsOf($frame))->toBe([]);

    // Ni plus tard, ni avec une revue rejetée : elle a été jugée.
    $this->travel(1)->days();
    FrameReview::factory()->forFrame($frame)->rejected()->create();

    expect(reviewQueueListsOf($frame))->toBe([]);

    // Et la revue elle-même la refuse, sans preuve.
    $count = $frame->reviews()->count();

    reviewQueuePost($frame, $this->curator)->assertSessionHasErrors('frame');

    expect($frame->reviews()->count())->toBe($count)
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Unpublished);
});

test('une revue rejetée range l\'image dans les rejetées, d\'où elle peut être revue', function (): void {
    $movie = Movie::factory()->create(['title_original' => 'Un film rejeté']);
    $frame = Frame::factory()->for($movie)->level(FrameLevel::Level2)->withFiles()->create();

    $this->travel(1)->minutes();
    reviewQueuePost($frame, $this->curator, ['no_title_card', 'no_lead_face'])->assertSessionHasNoErrors();

    expect(reviewQueueListsOf($frame))->toBe([ReviewList::Rejected])
        ->and($frame->refresh()->availability)->toBe(ContentAvailability::Draft);

    // L'écran nomme ses points en défaut, dans l'ordre de la grille.
    $this->actingAs($this->curator)
        ->get(route('admin.review.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/review/index')
            ->has('queue.to_review', 0)
            ->has('queue.rejected', 1)
            ->where('queue.rejected.0.movie.title_original', 'Un film rejeté')
            ->where('queue.rejected.0.frames.0.id', $frame->id)
            ->where('queue.rejected.0.frames.0.failed_items', ['no_title_card', 'no_lead_face']));

    // Re-recadrée : un nouveau rendu la renvoie « À revoir ».
    $rendered = $frame->published_hash;
    $frame->forceFill(['published_hash' => hash('sha256', 'nouveau cadre')])->save();

    expect(reviewQueueListsOf($frame))->toBe([ReviewList::ToReview]);

    $frame->forceFill(['published_hash' => $rendered])->save();

    // « Revoir » : une revue passante la publie.
    $this->travel(1)->minutes();
    reviewQueuePost($frame, $this->curator)->assertSessionHasNoErrors();

    expect($frame->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(reviewQueueListsOf($frame))->toBe([]);
});

test('la file à re-revoir ne montre que les images publiées sous une version antérieure', function (): void {
    [, [$outdated, $current]] = FrameBank::publishedMovie([3, 3]);
    $outdated->forceFill(['review_grid_version' => ExclusionGrid::CURRENT_VERSION - 1])->save();

    // Un brouillon n'y entre jamais, même revu sous une ancienne grille.
    $draft = Frame::factory()->for(Movie::factory()->create())->withFiles()->create([
        'review_grid_version' => ExclusionGrid::CURRENT_VERSION - 1,
    ]);

    expect(reviewQueueIds(ReviewList::ToReReview))->toBe([$outdated->id])
        ->and(reviewQueueListsOf($outdated))->toBe([ReviewList::ToReReview])
        ->and(reviewQueueListsOf($current))->toBe([])
        ->and(reviewQueueListsOf($draft))->toBe([ReviewList::ToReview]);

    $changedAt = $outdated->refresh()->availability_changed_at?->toIso8601String();
    $previous = $outdated->published_review_id;

    // Une re-revue passante : une ligne neuve, repointée ; l'image ne quitte
    // jamais le jeu.
    $this->travel(1)->minutes();
    reviewQueuePost($outdated, $this->curator)->assertSessionHasNoErrors();

    $outdated->refresh();

    expect($outdated->availability)->toBe(ContentAvailability::Published)
        ->and($outdated->published_review_id)->not->toBe($previous)
        ->and($outdated->review_grid_version)->toBe(ExclusionGrid::CURRENT_VERSION)
        ->and($outdated->availability_changed_at?->toIso8601String())->toBe($changedAt)
        ->and(reviewQueueIds(ReviewList::ToReReview))->toBe([]);
});

test('une image suspendue ou d\'un film retiré n\'est jamais proposée', function (): void {
    $draftMovie = Movie::factory()->create();

    $suspended = Frame::factory()->for($draftMovie)->withFiles()->create(['availability' => ContentAvailability::Suspended]);
    $withdrawn = Frame::factory()->for($draftMovie)->withFiles()->withdrawn()->create();
    $ofWithdrawnMovie = Frame::factory()->for(Movie::factory()->withdrawn()->create())->withFiles()->create();
    $ofSuspendedMovie = Frame::factory()->for(Movie::factory()->suspended()->create())->withFiles()->create();

    // Rejetée, puis son film suspendu.
    $rejectedMovie = Movie::factory()->create();
    $rejected = Frame::factory()->for($rejectedMovie)->withFiles()->create();
    FrameReview::factory()->forFrame($rejected)->rejected()->create();

    // En jeu sous une ancienne grille, puis suspendue.
    [, [$outdated]] = FrameBank::publishedMovie([5]);
    $outdated->forceFill([
        'review_grid_version' => ExclusionGrid::CURRENT_VERSION - 1,
        'availability' => ContentAvailability::Suspended,
    ])->save();

    expect(reviewQueueListsOf($rejected))->toBe([ReviewList::Rejected]);

    $rejectedMovie->forceFill(['availability' => ContentAvailability::Suspended])->save();

    foreach ([$suspended, $withdrawn, $ofWithdrawnMovie, $ofSuspendedMovie, $rejected, $outdated] as $frame) {
        expect(reviewQueueListsOf($frame))->toBe([], "frame #{$frame->id}");

        // Et la revue la refuse, sans preuve.
        $count = $frame->reviews()->count();

        reviewQueuePost($frame, $this->curator)->assertSessionHasErrors('frame');

        expect($frame->reviews()->count())->toBe($count);
    }
});

test('la source déclarée affichée est tmdb_file_path ou le timecode', function (): void {
    $movie = Movie::factory()->create(['title_original' => 'Deux sources']);
    $tmdb = Frame::factory()->for($movie)->level(FrameLevel::Level1)->withFiles()->create();
    $capture = Frame::factory()->for($movie)->level(FrameLevel::Level5)->capture(3_723_456)->withFiles()->create();

    // Un instant DANS L'ŒUVRE, jamais un support ni un outil (A7).
    expect(ReviewQueue::declaredSource($tmdb))->toBe(['kind' => 'tmdb', 'reference' => $tmdb->tmdb_file_path])
        ->and(ReviewQueue::declaredSource($capture))->toBe(['kind' => 'capture', 'reference' => '1:02:03'])
        ->and(ReviewQueue::timecode(0))->toBe('0:00:00')
        ->and(ReviewQueue::timecode(59_999))->toBe('0:00:59')
        ->and(ReviewQueue::timecode(36_000_000))->toBe('10:00:00');

    // Les props de revue de l'écran (C14-bis § 3), en lecture seule.
    $this->actingAs($this->curator)
        ->get(route('admin.review.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/review/index')
            ->has('queue.to_review', 1)
            ->where('queue.to_review.0.movie.title_original', 'Deux sources')
            ->has('queue.to_review.0.frames', 2)
            ->where('queue.to_review.0.frames.0.id', $tmdb->id)
            ->where('queue.to_review.0.frames.0.declared_source', ['kind' => 'tmdb', 'reference' => $tmdb->tmdb_file_path])
            ->where('queue.to_review.0.frames.0.published_hash', $tmdb->published_hash)
            ->where('queue.to_review.0.frames.0.grid_version', ExclusionGrid::CURRENT_VERSION)
            ->where('queue.to_review.0.frames.0.game_url', route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $tmdb->id, 'v' => $tmdb->published_hash]))
            ->has('queue.to_review.0.frames.0.items', count(ExclusionGrid::slugsFor(FrameLevel::Level1)))
            ->where('queue.to_review.0.frames.0.items.0', [
                'slug' => 'no_poster_or_cover',
                'label_key' => 'admin.exclusion_grid.v1.no_poster_or_cover.label',
                'help_key' => 'admin.exclusion_grid.v1.no_poster_or_cover.help',
            ])
            ->where('queue.to_review.0.frames.1.id', $capture->id)
            ->where('queue.to_review.0.frames.1.declared_source', ['kind' => 'capture', 'reference' => '1:02:03'])
            ->has('queue.to_review.0.frames.1.items', count(ExclusionGrid::slugsFor(FrameLevel::Level5))));

    // L'envoi confirme la source affichée : c'est elle que la preuve fige.
    reviewQueuePost($capture, $this->curator)->assertSessionHasNoErrors();

    $review = $capture->reviews()->sole();

    expect($review->declared_source_kind->value)->toBe('capture')
        ->and($review->declared_source_reference)->toBe('1:02:03');
});

test('l\'aperçu de revue change d\'adresse quand ses octets changent', function (): void {
    $movie = Movie::factory()->create();
    $frame = Frame::factory()->for($movie)->level(FrameLevel::Level3)->withFiles()->create();

    $before = AdminCatalogPresenter::reviewFrame($frame, null)['game_url'];

    // Le paramètre de version n'est lu par personne : l'aperçu sert les
    // mêmes octets, sous la même garde.
    $this->actingAs($this->curator)->get($before)->assertOk();

    // Un re-recadrage terminé dans un autre onglet : nouvelle empreinte,
    // donc nouvelle adresse. L'écran recharge l'image au lieu de garder
    // l'ancien rendu sous la nouvelle empreinte, que l'envoi certifierait.
    $rendered = hash('sha256', 'nouveau rendu');
    $frame->forceFill(['published_hash' => $rendered])->save();

    $after = AdminCatalogPresenter::reviewFrame($frame->refresh(), null)['game_url'];

    expect($after)->not->toBe($before)
        ->and($after)->toBe(route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $frame->id, 'v' => $rendered]));

    $this->actingAs($this->curator)
        ->get(route('admin.review.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queue.to_review.0.frames.0.published_hash', $rendered)
            ->where('queue.to_review.0.frames.0.game_url', $after));
});

test('la file regroupe les images par film, dans l\'ordre de leur plus ancienne image, puis par niveau', function (): void {
    $older = Movie::factory()->create();
    $newer = Movie::factory()->create();

    $newerFirst = Frame::factory()->for($newer)->level(FrameLevel::Level3)->withFiles()->create();
    $this->travel(1)->minutes();
    $olderLevel5 = Frame::factory()->for($older)->level(FrameLevel::Level5)->withFiles()->create(['created_at' => now()->subHour()]);
    $olderLevel1 = Frame::factory()->for($older)->level(FrameLevel::Level1)->withFiles()->create();
    $newerLevel1 = Frame::factory()->for($newer)->level(FrameLevel::Level1)->withFiles()->create();

    expect(reviewQueueIds(ReviewList::ToReview))->toBe([
        $olderLevel1->id,
        $olderLevel5->id,
        $newerLevel1->id,
        $newerFirst->id,
    ]);

    expect((new ReviewQueue)->counts())->toBe([
        ReviewList::ToReview->value => 4,
        ReviewList::ToReReview->value => 0,
        ReviewList::Rejected->value => 0,
    ]);
});
