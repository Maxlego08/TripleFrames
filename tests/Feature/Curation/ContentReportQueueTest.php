<?php

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentReportReason;
use App\Enums\ContentReportResolution;
use App\Enums\ContentReportStatus;
use App\Enums\FrameLevel;
use App\Models\AdminAction;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\Player;
use App\Models\User;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Frames\FrameBank;

/*
|--------------------------------------------------------------------------
| File des signalements de contenu — spec 20 § 11.6, D63 du 07/10
|--------------------------------------------------------------------------
|
| Curateur et au-delà, groupée par cible. Trois gestes, chacun clôt les
| signalements ouverts de sa cible dans la transaction du geste :
| dépublier le film (motif obligatoire, `movie.unpublished`), dépublier
| l'image (`frame.unpublished`), ignorer (`content_report.dismissed`).
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Queue::fake();
    $this->withoutVite();
});

it('montre au curateur la file groupée par cible, motifs comptés et derniers commentaires', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5]);
    $frame = $frames[1];

    ContentReport::factory()->forFrame($frame)->reason(ContentReportReason::TitleVisible)->create(['comment' => 'Titre lisible.']);
    ContentReport::factory()->forFrame($frame)->reason(ContentReportReason::TitleVisible)->create();
    ContentReport::factory()->forFrame($frame)->reason(ContentReportReason::PoorQuality)->create();
    ContentReport::factory()->forMovie($movie)->create();
    ContentReport::factory()->forMovie($movie)->dismissed()->create();

    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.content-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/content-reports/index')
            ->where('filter', 'open')
            ->where('counts.open_targets', 2)
            ->where('counts.open_reports', 4)
            ->has('groups.data', 2)
            ->where('groups.data.1.scope', 'frame')
            ->where('groups.data.1.reports_count', 3)
            ->where('groups.data.1.reasons', ['title_visible' => 2, 'poor_quality' => 1])
            ->where('groups.data.1.comments.0.comment', 'Titre lisible.')
            ->where('groups.data.1.frame.id', $frame->id)
            ->where('groups.data.1.movie.id', $movie->id)
            ->where('groups.data.1.abilities.unpublish_frame', true)
            ->where('groups.data.1.abilities.unpublish_movie', true)
            ->where('groups.data.1.abilities.dismiss', true)
            ->where('groups.data.0.scope', 'movie')
            ->where('groups.data.0.frame', null)
            ->where('groups.data.0.abilities.unpublish_frame', false));
});

it('refuse la file et ses gestes à un joueur', function (): void {
    $report = ContentReport::factory()->create();
    $player = User::factory()->player()->create();

    $this->actingAs($player)->get(route('admin.content-reports.index'))->assertForbidden();
    $this->actingAs($player)->post(route('admin.content-reports.dismiss', ['contentReport' => $report->id]))->assertForbidden();

    expect($report->fresh()?->status)->toBe(ContentReportStatus::Open);
});

it('dépublie le film, journalise movie.unpublished et clôt tous les signalements ouverts du film', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5]);
    $curator = User::factory()->curator()->create();
    $movieReport = ContentReport::factory()->forMovie($movie)->create();
    $frameReport = ContentReport::factory()->forFrame($frames[0])->create();
    $already = ContentReport::factory()->forMovie($movie)->dismissed()->create();
    $other = ContentReport::factory()->create();

    $this->actingAs($curator)
        ->post(route('admin.content-reports.unpublish-movie', ['contentReport' => $movieReport->id]), ['reason' => 'Fiche fausse.'])
        ->assertRedirect(route('admin.content-reports.index'));

    $line = AdminAction::query()->where('action', AdminActionType::MovieUnpublished->value)->sole();

    expect($movie->fresh()?->availability)->toBe(ContentAvailability::Unpublished)
        ->and($line->subject_id)->toBe($movie->id)
        ->and($line->reason)->toBe('Fiche fausse.')
        ->and($movieReport->fresh()?->status)->toBe(ContentReportStatus::Resolved)
        ->and($movieReport->fresh()?->resolution)->toBe(ContentReportResolution::MovieUnpublished)
        ->and($movieReport->fresh()?->resolved_by_id)->toBe($curator->id)
        ->and($movieReport->fresh()?->resolved_at)->not->toBeNull()
        ->and($frameReport->fresh()?->resolution)->toBe(ContentReportResolution::MovieUnpublished)
        ->and($already->fresh()?->resolution)->toBe(ContentReportResolution::Dismissed)
        ->and($other->fresh()?->status)->toBe(ContentReportStatus::Open);
});

it('exige un motif pour dépublier le film depuis la file', function (): void {
    $report = ContentReport::factory()->create();

    $this->actingAs(User::factory()->curator()->create())
        ->from(route('admin.content-reports.index'))
        ->post(route('admin.content-reports.unpublish-movie', ['contentReport' => $report->id]), ['reason' => '  '])
        ->assertSessionHasErrors('reason');

    expect($report->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and($report->movie->fresh()?->availability)->toBe(ContentAvailability::Published);
});

it('dépublie l’image, journalise frame.unpublished et ne clôt que les signalements de cette image', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 3, 5]);
    $curator = User::factory()->curator()->create();
    $target = ContentReport::factory()->forFrame($frames[1])->create();
    $twin = ContentReport::factory()->forFrame($frames[1])->create();
    $sibling = ContentReport::factory()->forFrame($frames[2])->create();
    $movieReport = ContentReport::factory()->forMovie($movie)->create();

    $this->actingAs($curator)
        ->post(route('admin.content-reports.unpublish-frame', ['contentReport' => $target->id]), ['reason' => ''])
        ->assertRedirect(route('admin.content-reports.index'));

    $line = AdminAction::query()->where('action', AdminActionType::FrameUnpublished->value)->sole();

    expect($frames[1]->fresh()?->availability)->toBe(ContentAvailability::Unpublished)
        ->and($line->subject_id)->toBe($frames[1]->id)
        ->and($target->fresh()?->resolution)->toBe(ContentReportResolution::FrameUnpublished)
        ->and($twin->fresh()?->resolution)->toBe(ContentReportResolution::FrameUnpublished)
        ->and($sibling->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and($movieReport->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and($movie->fresh()?->availability)->toBe(ContentAvailability::Published);
});

it('répond 404 à la dépublication d’image pour un signalement du film entier', function (): void {
    $report = ContentReport::factory()->create();

    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.content-reports.unpublish-frame', ['contentReport' => $report->id]))
        ->assertNotFound();

    expect($report->fresh()?->status)->toBe(ContentReportStatus::Open);
});

it('ignore les signalements ouverts d’une cible et journalise content_report.dismissed avec leur nombre', function (): void {
    [, $frames] = FrameBank::publishedMovie([1, 3, 5]);
    $curator = User::factory()->curator()->create();
    $first = ContentReport::factory()->forFrame($frames[0])->create();
    $second = ContentReport::factory()->forFrame($frames[0])->create();
    $other = ContentReport::factory()->forFrame($frames[1])->create();

    $this->actingAs($curator)
        ->post(route('admin.content-reports.dismiss', ['contentReport' => $second->id]), ['reason' => 'Image correcte.'])
        ->assertRedirect(route('admin.content-reports.index'));

    $line = AdminAction::query()->where('action', AdminActionType::ContentReportDismissed->value)->sole();

    expect($first->fresh()?->status)->toBe(ContentReportStatus::Dismissed)
        ->and($second->fresh()?->resolution)->toBe(ContentReportResolution::Dismissed)
        ->and($other->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($line->subject_id)->toBe($first->id)
        ->and($line->actor_id)->toBe($curator->id)
        ->and($line->reason)->toBe('Image correcte.')
        ->and($line->details?->values)->toBe([
            'movie_id' => $frames[0]->movie_id,
            'frame_id' => $frames[0]->id,
            'resolution' => 'dismissed',
            'closed' => 2,
        ]);

    // Idempotent : plus rien d'ouvert, aucune seconde ligne.
    $this->actingAs($curator)->post(route('admin.content-reports.dismiss', ['contentReport' => $second->id]))->assertRedirect();

    expect(AdminAction::query()->where('action', AdminActionType::ContentReportDismissed->value)->count())->toBe(1);
});

it('clôt en already_handled les signalements d’une cible déjà hors jeu', function (): void {
    $movie = Movie::factory()->unpublished()->create();
    $report = ContentReport::factory()->forMovie($movie)->create();

    $this->actingAs(User::factory()->curator()->create())
        ->post(route('admin.content-reports.dismiss', ['contentReport' => $report->id]))
        ->assertRedirect();

    expect($report->fresh()?->status)->toBe(ContentReportStatus::Resolved)
        ->and($report->fresh()?->resolution)->toBe(ContentReportResolution::AlreadyHandled);
});

it('clôt en already_handled les signalements d’un film retiré et ne propose que d’ignorer', function (): void {
    $movie = Movie::factory()->withdrawn()->create(['first_published_at' => now()]);
    $report = ContentReport::factory()->forMovie($movie)->create();
    $curator = User::factory()->curator()->create();

    $this->actingAs($curator)
        ->get(route('admin.content-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('groups.data', 1)
            ->where('groups.data.0.abilities', ['unpublish_movie' => false, 'unpublish_frame' => false, 'dismiss' => true, 'suspend_movie' => false, 'suspend_frame' => false]));

    $this->actingAs($curator)
        ->post(route('admin.content-reports.unpublish-movie', ['contentReport' => $report->id]), ['reason' => 'Retiré.'])
        ->assertForbidden();

    $this->actingAs($curator)
        ->post(route('admin.content-reports.dismiss', ['contentReport' => $report->id]))
        ->assertRedirect();

    expect($report->fresh()?->status)->toBe(ContentReportStatus::Resolved)
        ->and($report->fresh()?->resolution)->toBe(ContentReportResolution::AlreadyHandled)
        ->and($movie->fresh()?->availability)->toBe(ContentAvailability::Withdrawn);
});

it('ne propose plus aucun geste dans l’historique des cibles traitées', function (): void {
    ContentReport::factory()->dismissed()->create();

    $this->actingAs(User::factory()->curator()->create())
        ->get(route('admin.content-reports.index', ['filter' => 'closed']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/content-reports/index')
            ->where('filter', 'closed')
            ->has('groups.data', 1)
            ->where('groups.data.0.resolution', 'dismissed')
            ->where('groups.data.0.abilities', ['unpublish_movie' => false, 'unpublish_frame' => false, 'dismiss' => false, 'suspend_movie' => false, 'suspend_frame' => false]));
});

it('suspendre depuis la file clôt les signalements de la cible en movie_suspended dans la transaction du geste', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 3, 5]);
    $admin = User::factory()->admin()->create();
    $movieReport = ContentReport::factory()->forMovie($movie)->create();
    $frameReport = ContentReport::factory()->forFrame($frames[0])->create();
    $other = ContentReport::factory()->create();

    $this->actingAs($admin)
        ->get(route('admin.content-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.1.abilities.suspend_movie', true)
            ->where('groups.data.1.abilities.suspend_frame', true));

    // L'image seule d'abord : ses signalements, et eux seuls, sont clos.
    $this->actingAs($admin)
        ->post(route('admin.content-reports.suspend-frame', ['contentReport' => $frameReport->id]), ['reason' => 'Titre lisible.'])
        ->assertRedirect(route('admin.content-reports.index'));

    $frameLine = AdminAction::query()->where('action', AdminActionType::FrameSuspended->value)->sole();

    expect($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and($frameLine->subject_id)->toBe($frames[0]->id)
        ->and($frameLine->reason)->toBe('Titre lisible.')
        ->and($frameReport->fresh()?->resolution)->toBe(ContentReportResolution::FrameSuspended)
        ->and($frameReport->fresh()?->status)->toBe(ContentReportStatus::Resolved)
        ->and($movieReport->fresh()?->status)->toBe(ContentReportStatus::Open);

    // Puis le film : tous ses signalements ouverts, portées film et image.
    $second = ContentReport::factory()->forFrame($frames[1])->create();

    $this->actingAs($admin)
        ->post(route('admin.content-reports.suspend-movie', ['contentReport' => $movieReport->id]))
        ->assertRedirect(route('admin.content-reports.index'));

    expect($movie->fresh()?->availability)->toBe(ContentAvailability::Suspended)
        ->and(AdminAction::query()->where('action', AdminActionType::MovieSuspended->value)->sole()->subject_id)->toBe($movie->id)
        ->and($movieReport->fresh()?->resolution)->toBe(ContentReportResolution::MovieSuspended)
        ->and($movieReport->fresh()?->resolved_by_id)->toBe($admin->id)
        ->and($second->fresh()?->resolution)->toBe(ContentReportResolution::MovieSuspended)
        ->and($frameReport->fresh()?->resolution)->toBe(ContentReportResolution::FrameSuspended)
        ->and($other->fresh()?->status)->toBe(ContentReportStatus::Open);

    // Un geste refusé sous verrou — le film est déjà suspendu — ne clôt rien.
    $late = ContentReport::factory()->forMovie($movie)->create();

    $this->actingAs($admin)
        ->post(route('admin.content-reports.suspend-movie', ['contentReport' => $late->id]))
        ->assertForbidden();

    expect($late->fresh()?->status)->toBe(ContentReportStatus::Open);
});

it('un curateur ne voit ni n’emploie la suspension depuis la file', function (): void {
    [$movie, $frames] = FrameBank::publishedMovie([1, 3, 5]);
    $curator = User::factory()->curator()->create();
    $movieReport = ContentReport::factory()->forMovie($movie)->create();
    $frameReport = ContentReport::factory()->forFrame($frames[0])->create();

    $this->actingAs($curator)
        ->get(route('admin.content-reports.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.data.0.abilities.suspend_movie', false)
            ->where('groups.data.0.abilities.suspend_frame', false)
            ->where('groups.data.1.abilities.suspend_movie', false)
            ->where('groups.data.1.abilities.suspend_frame', false)
            ->where('groups.data.1.abilities.unpublish_movie', true));

    $this->actingAs($curator)
        ->post(route('admin.content-reports.suspend-movie', ['contentReport' => $movieReport->id]))
        ->assertForbidden();

    $this->actingAs($curator)
        ->post(route('admin.content-reports.suspend-frame', ['contentReport' => $frameReport->id]))
        ->assertForbidden();

    expect($movie->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($frames[0]->fresh()?->availability)->toBe(ContentAvailability::Published)
        ->and($movieReport->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and($frameReport->fresh()?->status)->toBe(ContentReportStatus::Open)
        ->and(AdminAction::query()->count())->toBe(0);
});

it('purge un signalement de plus de douze mois et garde un signalement récent', function (): void {
    $old = ContentReport::factory()->create(['created_at' => now()->subMonths(13)]);
    $recent = ContentReport::factory()->create();

    $this->artisan('purge:run', ['--sync' => true])->assertSuccessful();

    expect(ContentReport::query()->find($old->id))->toBeNull()
        ->and(ContentReport::query()->find($recent->id))->not->toBeNull()
        ->and($old->movie->fresh())->not->toBeNull();
});

it('frappe un public_id base32 unique à chaque image créée, jamais dérivé de l’id', function (): void {
    $frames = Frame::factory()->count(3)->for(Movie::factory())->level(FrameLevel::Level1)->create();

    foreach ($frames as $frame) {
        expect($frame->public_id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{12}$/')
            ->and($frame->toArray())->toHaveKey('public_id')
            ->and($frame->toArray())->not->toHaveKey('id');
    }

    expect($frames->pluck('public_id')->unique())->toHaveCount(3);

    $seat = Player::factory()->create();
    expect($seat->public_id)->toHaveLength(12);
});
