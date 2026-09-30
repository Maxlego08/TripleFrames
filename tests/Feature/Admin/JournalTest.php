<?php

use App\Enums\AdminActionType;
use App\Http\Requests\Admin\AdminJournalRequest;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\ValueObjects\Admin\AdminActionDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
|--------------------------------------------------------------------------
| L'écran « Journal » — spec 20 § 2.2, ligne 41 (D41 du 30/09)
|--------------------------------------------------------------------------
|
| Administrateur seul, en lecture seule : tout `admin_action`, du plus récent
| au plus ancien, filtrable par acteur, action, période et sujet, paginé côté
| serveur. Les liens « Historique » de la fiche d'un film et de la fiche d'un
| compte l'ouvrent filtré sur leur sujet.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->admin = User::factory()->admin()->create();
});

/**
 * Les identifiants d'une page du journal, dans son ordre.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function journalIds(array $query = []): array
{
    $ids = [];

    test()->actingAs(test()->admin)
        ->get(route('admin.journal.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids): void {
            $page->component('admin/journal/index');

            $ids = array_map(
                static fn (array $line): int => $line['id'],
                $page->toArray()['props']['lines']['data'],
            );
        });

    return $ids;
}

test('l\'administrateur lit le journal, du plus récent au plus ancien, auteur signé et sujet résolu', function (): void {
    $movie = Movie::factory()->create(['title_original' => 'Le Film du Journal']);
    $curator = User::factory()->curator()->create(['real_name' => 'Camille Curatrice']);

    $older = AdminAction::factory()->byActor($curator)->of(AdminActionType::MoviePublished, $movie->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-10 10:00:00')]);
    $newer = AdminAction::factory()->byActor($this->admin)->of(AdminActionType::MovieUnpublished, $movie->id)
        ->because('Titre ambigu')
        ->create(['created_at' => CarbonImmutable::parse('2026-09-20 10:00:00')]);

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/journal/index')
            ->where('lines.meta.total', 2)
            ->where('lines.data.0.id', $newer->id)
            ->where('lines.data.0.action', 'movie.unpublished')
            ->where('lines.data.0.actor_name', $this->admin->real_name)
            ->where('lines.data.0.actor_id', $this->admin->id)
            ->where('lines.data.0.reason', 'Titre ambigu')
            ->where('lines.data.0.retention_class', 'permanent')
            ->where('lines.data.0.subject', [
                'type' => 'movie',
                'id' => $movie->id,
                'label' => 'Le Film du Journal',
                'movie_id' => $movie->id,
                'exists' => true,
            ])
            ->where('lines.data.1.id', $older->id)
            ->where('lines.data.1.actor_name', 'Camille Curatrice')
            ->where('subject', null)
            ->where('options.actions', array_column(AdminActionType::cases(), 'value'))
            ->where('options.actors', fn ($actors): bool => collect($actors)->pluck('label')->sort()->values()->all()
                === collect([$this->admin->real_name, 'Camille Curatrice'])->sort()->values()->all()),
        );
});

test('le complément details est rendu tel quel et l\'image mène à la fiche de son film', function (): void {
    $movie = Movie::factory()->create(['title_original' => 'Film Aux Images']);
    $frame = Frame::factory()->for($movie)->create();

    DB::transaction(fn (): AdminAction => app(AdminJournal::class)->record(
        $this->admin,
        AdminActionType::MovieAliasAdded,
        $movie->id,
        details: AdminActionDetails::aliasAdded(7, 'fr', 'Le Film'),
    ));

    AdminAction::factory()->byActor($this->admin)->of(AdminActionType::FrameUnpublished, $frame->id)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', ['action' => 'movie.alias_added']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lines.meta.total', 1)
            ->where('lines.data.0.details', ['alias_id' => 7, 'locale' => 'fr', 'alias' => 'Le Film']),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', ['subject_type' => 'frame', 'subject_id' => $frame->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lines.meta.total', 1)
            ->where('lines.data.0.subject.movie_id', $movie->id)
            ->where('lines.data.0.subject.label', 'Film Aux Images')
            ->where('subject.type', 'frame')
            ->where('subject.exists', true),
        );
});

test('les filtres d\'acteur, d\'action, de période et de sujet s\'appliquent', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();
    $other = Movie::factory()->create();
    $target = User::factory()->player()->create();

    $byCurator = AdminAction::factory()->byActor($curator)->of(AdminActionType::MoviePublished, $movie->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-05 23:59:00')]);
    $onOther = AdminAction::factory()->byActor($this->admin)->of(AdminActionType::MoviePublished, $other->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-06 00:00:00')]);
    $onUser = AdminAction::factory()->byActor($this->admin)->of(AdminActionType::RoleChanged, $target->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-07 12:00:00')]);
    $fromConsole = AdminAction::factory()->of(AdminActionType::SiteClosed)
        ->state(['actor_id' => null, 'actor_name' => AdminAction::CONSOLE_ACTOR])
        ->because('Maintenance')
        ->create(['created_at' => CarbonImmutable::parse('2026-09-08 08:00:00')]);

    expect(journalIds(['actor' => (string) $curator->id]))->toBe([$byCurator->id])
        ->and(journalIds(['actor' => 'console']))->toBe([$fromConsole->id])
        ->and(journalIds(['action' => 'movie.published']))->toBe([$onOther->id, $byCurator->id])
        ->and(journalIds(['from' => '2026-09-06', 'to' => '2026-09-07']))->toBe([$onUser->id, $onOther->id])
        ->and(journalIds(['to' => '2026-09-05']))->toBe([$byCurator->id])
        ->and(journalIds(['subject_type' => 'movie']))->toBe([$onOther->id, $byCurator->id])
        ->and(journalIds(['subject_type' => 'movie', 'subject_id' => $movie->id]))->toBe([$byCurator->id])
        ->and(journalIds(['subject_type' => 'user', 'subject_id' => $target->id]))->toBe([$onUser->id])
        ->and(journalIds(['subject_type' => 'site']))->toBe([$fromConsole->id]);
});

test('le filtre film ramène aussi les lignes de ses images', function (): void {
    $movie = Movie::factory()->create(['title_original' => 'Film Historique']);
    $frame = Frame::factory()->for($movie)->create();
    $other = Movie::factory()->create();
    $otherFrame = Frame::factory()->for($other)->create();

    $published = AdminAction::factory()->byActor($this->admin)->of(AdminActionType::MoviePublished, $movie->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-10 10:00:00')]);
    $frameLine = AdminAction::factory()->byActor($this->admin)->of(AdminActionType::FrameUnpublished, $frame->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-11 10:00:00')]);
    AdminAction::factory()->byActor($this->admin)->of(AdminActionType::MoviePublished, $other->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-12 10:00:00')]);
    AdminAction::factory()->byActor($this->admin)->of(AdminActionType::FrameUnpublished, $otherFrame->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-13 10:00:00')]);
    // Un compte qui porte le même numéro que l'image n'entre jamais dans
    // l'historique du film.
    AdminAction::factory()->byActor($this->admin)->of(AdminActionType::RoleChanged, $frame->id)
        ->create(['created_at' => CarbonImmutable::parse('2026-09-14 10:00:00')]);

    expect(journalIds(['movie' => $movie->id]))->toBe([$frameLine->id, $published->id])
        ->and(journalIds(['movie' => $movie->id, 'action' => 'frame.unpublished']))->toBe([$frameLine->id]);

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', ['movie' => $movie->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.movie', $movie->id)
            ->where('filters.subject_type', null)
            ->where('subject.type', 'movie')
            ->where('subject.id', $movie->id)
            ->where('subject.label', 'Film Historique')
            ->where('subject.exists', true),
        );
});

test('un filtre invalide renvoie au journal nu avec son erreur, jamais à un journal faussement filtré', function (array $query, string $field): void {
    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', $query))
        ->assertRedirect(route('admin.journal.index'))
        ->assertSessionHasErrors($field);
})->with([
    'action hors liste' => [['action' => 'movie.deleted'], 'action'],
    'acteur illisible' => [['actor' => 'camille'], 'actor'],
    'date mal formée' => [['from' => '30/09/2026'], 'from'],
    'période inversée' => [['from' => '2026-09-10', 'to' => '2026-09-01'], 'to'],
    'sujet hors liste' => [['subject_type' => 'room'], 'subject_type'],
    'numéro sans type' => [['subject_id' => 12], 'subject_id'],
    'numéro sur le site' => [['subject_type' => 'site', 'subject_id' => 1], 'subject_id'],
    'numéro non entier' => [['subject_type' => 'movie', 'subject_id' => 'abc'], 'subject_id'],
    'film non entier' => [['movie' => 'abc'], 'movie'],
    'film et sujet ensemble' => [['movie' => 3, 'subject_type' => 'movie', 'subject_id' => 3], 'movie'],
    'film et type de sujet' => [['movie' => 3, 'subject_type' => 'frame'], 'movie'],
]);

test('le journal est paginé côté serveur et la pagination garde les filtres', function (): void {
    $movie = Movie::factory()->create();

    AdminAction::factory()
        ->count(AdminJournalRequest::PER_PAGE + 3)
        ->byActor($this->admin)
        ->of(AdminActionType::MoviePublished, $movie->id)
        ->create();

    AdminAction::factory()->byActor($this->admin)->of(AdminActionType::RoleChanged, $this->admin->id)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', ['action' => 'movie.published', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lines.meta.total', AdminJournalRequest::PER_PAGE + 3)
            ->where('lines.meta.current_page', 2)
            ->where('lines.meta.last_page', 2)
            ->has('lines.data', 3)
            ->where('filters.action', 'movie.published')
            ->missing('lines.links'),
        );
});

test('un sujet filtré qui n\'existe pas est dit, et la page reste vide', function (): void {
    $this->actingAs($this->admin)
        ->get(route('admin.journal.index', ['subject_type' => 'movie', 'subject_id' => 999_999]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lines.meta.total', 0)
            ->where('subject.exists', false)
            ->where('subject.label', null),
        );
});

test('le curateur est refusé, comme sur toute route de l\'administrateur, et ne voit pas le lien de la fiche film', function (): void {
    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();

    $this->actingAs($curator)
        ->get(route('admin.journal.index'))
        ->assertForbidden();

    $this->actingAs($curator)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities.viewJournal', false));

    $this->actingAs($this->admin)
        ->get(route('admin.catalog.show', $movie))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('abilities.viewJournal', true));
});

test('la visite du journal n\'écrit aucune ligne', function (): void {
    $this->actingAs($this->admin)->get(route('admin.journal.index'))->assertOk();

    expect(AdminAction::query()->count())->toBe(0);
});

test('l\'identifiant de l\'auteur ne quitte le serveur que pour ouvrir sa fiche, et jamais pour un acteur réservé', function (): void {
    AdminAction::factory()->system(AdminActionType::AvatarHidden)->create();

    $this->actingAs($this->admin)
        ->get(route('admin.journal.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lines.data.0.actor_id', null)
            ->where('lines.data.0.actor_name', AdminAction::SYSTEM_ACTOR),
        );
});
