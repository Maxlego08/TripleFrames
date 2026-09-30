<?php

use App\Enums\AdminActionRetention;
use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\ImportRunKind;
use App\Http\Controllers\Admin\ImportController;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\AdminAction;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Journal des lancements d'import — D41 du 30/09
|--------------------------------------------------------------------------
|
| Balayage, collage, lot de la liste d'amorçage et reprise écrivent leur
| ligne `admin_action`, sujet `import_run`, dans la transaction qui ouvre ou
| relit le balayage ; un lancement refusé n'en écrit aucune. L'aperçu à
| blanc, lui, n'écrit rien : il ne change aucun état.
|
*/

beforeEach(function (): void {
    $this->withoutVite();

    Bus::fake();
    Config::set('services.tmdb.api_key', 'clef-de-test');

    $this->curator = User::factory()->curator()->create();
});

/**
 * La seule ligne du journal pour cette action.
 */
function importJournalLine(AdminActionType $action): AdminAction
{
    return AdminAction::query()->where('action', $action->value)->sole();
}

test('lancer un balayage écrit import.discover_started, sujet le balayage ouvert', function (): void {
    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_vote_count' => config('catalog.import_filter.min_vote_count'),
            'languages' => config('catalog.import_filter.languages'),
            'min_release_year' => config('catalog.import_filter.min_release_year'),
            'pages' => 2,
        ])
        ->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();
    $line = importJournalLine(AdminActionType::ImportDiscoverStarted);

    expect($line->actor_id)->toBe($this->curator->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->subject_type)->toBe(AdminActionSubject::ImportRun)
        ->and($line->subject_id)->toBe($run->id)
        ->and($line->retention_class)->toBe(AdminActionRetention::Permanent)
        ->and($line->details?->values)->toBe(['pages' => 2]);

    // Un second balayage pendant que le premier est en file : refusé, aucune
    // ligne de plus.
    $this->actingAs($this->curator)
        ->post(route('admin.import.discover'), [
            'min_vote_count' => config('catalog.import_filter.min_vote_count'),
            'languages' => config('catalog.import_filter.languages'),
            'min_release_year' => config('catalog.import_filter.min_release_year'),
            'pages' => 1,
        ]);

    expect(ImportRun::query()->count())->toBe(1)
        ->and(AdminAction::query()->count())->toBe(1);
});

test('coller des identifiants écrit import.paste_started, les identifiants gardés', function (): void {
    $this->actingAs($this->curator)
        ->post(route('admin.import.ids'), ['ids' => "550\n27205 550"])
        ->assertSessionHasNoErrors();

    $run = ImportRun::query()->sole();
    $line = importJournalLine(AdminActionType::ImportPasteStarted);

    expect($run->run_kind)->toBe(ImportRunKind::Paste)
        ->and($line->subject_id)->toBe($run->id)
        ->and($line->details?->values)->toBe(['tmdb_ids' => [550, 27205]]);

    Bus::assertDispatched(RunCatalogImport::class, fn (RunCatalogImport $job): bool => $job->runId === $run->id);
});

test('importer la liste d\'amorçage écrit import.seed_list_started, le lot gardé', function (): void {
    Config::set('catalog.import.paste_max_ids', 2);

    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tripleframes-seed-'.bin2hex(random_bytes(6)).'.txt';
    File::put($path, "905\n12\n330\n");
    Config::set('catalog.import.seed_list_path', $path);

    try {
        $this->actingAs($this->curator)
            ->post(route('admin.import.seed_list'))
            ->assertSessionHasNoErrors();
    } finally {
        File::delete($path);
    }

    $line = importJournalLine(AdminActionType::ImportSeedListStarted);

    expect($line->subject_id)->toBe(ImportRun::query()->sole()->id)
        ->and($line->details?->values)->toBe(['tmdb_ids' => [905, 12]]);
});

test('reprendre un balayage écrit import.resumed, jamais pour une reprise refusée', function (): void {
    $paste = ImportRun::factory()->paste()->running()->create();
    $queued = ImportRun::factory()->discover()->running()->create(['started_at' => null]);

    foreach ([$paste, $queued] as $refused) {
        $this->actingAs($this->curator)->post(route('admin.import.resume', $refused))->assertRedirect();
    }

    expect(AdminAction::query()->count())->toBe(0);
    Bus::assertNothingDispatched();

    $run = ImportRun::factory()->discover()->running()->create();

    $this->actingAs($this->curator)
        ->post(route('admin.import.resume', $run))
        ->assertRedirect(route('admin.import.show', ['importRun' => $run->id]));

    $line = importJournalLine(AdminActionType::ImportResumed);

    expect($line->subject_id)->toBe($run->id)
        ->and($line->actor_name)->toBe($this->curator->real_name)
        ->and($line->details?->values)->toBe(['pages' => ImportController::defaults()['pages_max']]);

    Bus::assertDispatched(RunCatalogImport::class, fn (RunCatalogImport $job): bool => $job->runId === $run->id);
});

test('l\'aperçu à blanc d\'un collage n\'écrit rien au journal', function (): void {
    $this->actingAs($this->curator)
        ->post(route('admin.import.preview'), ['ids' => '550']);

    expect(AdminAction::query()->count())->toBe(0);
});
