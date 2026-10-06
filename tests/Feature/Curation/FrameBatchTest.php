<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Enums\FrameSourceKind;
use App\Http\Requests\Admin\FrameBatchStoreRequest;
use App\Jobs\Curation\ImportFrameBatch;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\FrameBatch;
use App\Support\Curation\FrameBatchException;
use App\Support\Curation\FrameBatchImport;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\TmdbFixture;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Lots d'images — spec 20 § 5.10, D57 du 05/10
|--------------------------------------------------------------------------
|
| Un lot ne porte que des références TMDB, des niveaux et des cadres. Il se
| dépose sur le poste par `curation:frame-batch <fichier>` (proposition de
| l'IA, validée par le porteur dans l'éditeur local) et en production par
| l'écran « Lots d'images » ; dans les deux cas, chaque image passe par les
| gardes de l'ajout unitaire et entre `draft`, sans revue ni publication.
|
| TMDB est simulé par la fixture `movie-987654-images` et des octets
| synthétiques ; la file est fausse, sauf là où le job est joué à la main.
|
*/

/** Identifiant TMDB de la fixture `movie-987654-images`. */
const BATCH_TMDB_ID = 987654;

/** Un backdrop 16:9 de 1920 × 1080 de la fixture, sans langue. */
const BATCH_BACKDROP = '/6a7b8c9d0e1f2a3b4c5d6e7f80912a3b.jpg';

/** Un backdrop de la fixture auquel TMDB attache l'anglais. */
const BATCH_BACKDROP_WITH_TEXT = '/8c9d0e1f2a3b4c5d6e7f80912a3b4c5d.jpg';

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    Config::set('services.tmdb.read_access_token', 'fixture-token');
    Config::set('services.tmdb.api_key', null);
    Sleep::fake();
    Queue::fake();
});

afterEach(function (): void {
    Sleep::fake(false);
});

function frameBatchTmdbFake(): void
{
    Http::fake([
        '*themoviedb.org/3/movie/'.BATCH_TMDB_ID.'/images*' => Http::response(TmdbFixture::json('movie-987654-images')),
        '*image.tmdb.org/*' => Http::response(SourceImages::jpeg(1920, 1080)),
    ]);
}

/**
 * Un lot d'un film, en données.
 *
 * @param  list<array<string, mixed>>  $frames
 * @return array<string, mixed>
 */
function frameBatchData(array $frames, int $tmdbId = BATCH_TMDB_ID): array
{
    return [
        'format' => FrameBatch::FORMAT,
        'version' => FrameBatch::VERSION,
        'movies' => [['tmdb_id' => $tmdbId, 'title' => 'Film du lot', 'frames' => $frames]],
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function frameBatchFile(array $data): string
{
    $path = tempnam(sys_get_temp_dir(), 'frame-batch-');
    file_put_contents($path, (string) json_encode($data));

    return $path;
}

test('un lot exporté se relit à l\'identique, sans capture ni image écartée', function (): void {
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $kept = Frame::factory()->for($movie)->level(FrameLevel::Level1)->create(['processing_state' => FrameProcessingState::Ready]);
    Frame::factory()->for($movie)->capture()->create(['processing_state' => FrameProcessingState::Ready]);
    Frame::factory()->for($movie)->create(['processing_state' => FrameProcessingState::Ready, 'availability' => ContentAvailability::Unpublished]);
    Frame::factory()->for($movie)->create(['processing_state' => FrameProcessingState::Failed]);

    $batch = FrameBatch::fromMovies(Movie::query()->with('frames')->get());
    $reread = FrameBatch::fromJson((string) json_encode($batch->toArray()));

    expect($reread->framesCount())->toBe(1)
        ->and($reread->movies[0]['tmdb_id'])->toBe(BATCH_TMDB_ID)
        ->and($reread->movies[0]['frames'][0]['tmdb_file_path'])->toBe($kept->tmdb_file_path)
        ->and($reread->movies[0]['frames'][0]['level'])->toBe(FrameLevel::Level1)
        ->and($reread->movies[0]['frames'][0]['crop']?->toArray())->toBe([
            'x' => $kept->crop_x,
            'y' => $kept->crop_y,
            'width' => $kept->crop_width,
            'height' => $kept->crop_height,
        ]);
});

test('un lot hors format est refusé avec la clé de son motif', function (string $json, string $key): void {
    expect(fn () => FrameBatch::fromJson($json))
        ->toThrow(fn (FrameBatchException $exception) => expect($exception->key)->toBe($key));
})->with([
    'texte illisible' => ['{pas du json', 'admin.frame_batch.invalid.json'],
    'autre format' => [(string) json_encode(['format' => 'autre', 'version' => 1, 'movies' => []]), 'admin.frame_batch.invalid.format'],
    'aucun film' => [(string) json_encode(['format' => FrameBatch::FORMAT, 'version' => 1, 'movies' => []]), 'admin.frame_batch.invalid.empty'],
    'film sans image' => [(string) json_encode(frameBatchData([])), 'admin.frame_batch.invalid.no_frames'],
    'niveau hors échelle' => [(string) json_encode(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 6]])), 'admin.frame_batch.invalid.frame'],
    'chemin hors forme' => [(string) json_encode(frameBatchData([['tmdb_file_path' => '../etc/passwd', 'level' => 1]])), 'admin.frame_batch.invalid.frame'],
]);

test('la commande dépose les images d\'un lot en brouillon, au niveau et au cadre du lot', function (): void {
    frameBatchTmdbFake();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $curator = User::factory()->curator()->create();
    $limits = PlatformLimits::current();
    $masterHeight = FrameGeometry::masterHeightFor(1920, 1080);
    $width = FrameGeometry::maxCropWidth($masterHeight, $limits);
    $crop = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => intdiv($width * 9, 16)];

    $file = frameBatchFile(frameBatchData([
        ['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1, 'crop' => $crop],
        ['tmdb_file_path' => BATCH_BACKDROP, 'level' => 5],
    ]));

    expect(Artisan::call('curation:frame-batch', ['file' => $file, '--actor' => (string) $curator->id]))->toBe(0);

    $frames = $movie->frames()->orderBy('id')->get();

    expect($frames)->toHaveCount(2)
        ->and($frames[0]->frame_level)->toBe(FrameLevel::Level1)
        ->and([$frames[0]->crop_x, $frames[0]->crop_y, $frames[0]->crop_width])->toBe([0, 0, $width])
        ->and($frames[1]->frame_level)->toBe(FrameLevel::Level5)
        ->and($frames[1]->crop_width)->toBe(FrameGeometry::defaultCrop($masterHeight, $limits)->width)
        ->and($frames->every(fn (Frame $frame): bool => $frame->availability === ContentAvailability::Draft
            && $frame->source_kind === FrameSourceKind::Tmdb
            && $frame->uploaded_by_id === $curator->id))->toBeTrue();

    // Redéposé, le lot ne crée aucun doublon.
    Artisan::call('curation:frame-batch', ['file' => $file, '--actor' => (string) $curator->id]);

    expect($movie->frames()->count())->toBe(2);
});

test('un backdrop qui porte une langue est refusé sans être téléchargé', function (): void {
    frameBatchTmdbFake();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $curator = User::factory()->curator()->create();

    Artisan::call('curation:frame-batch', [
        'file' => frameBatchFile(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP_WITH_TEXT, 'level' => 3]])),
        '--actor' => (string) $curator->id,
    ]);

    expect($movie->frames()->count())->toBe(0)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'image.tmdb.org')))->toBeEmpty()
        ->and(Artisan::output())->toContain(__('admin.frame.tmdb.with_text', [], 'fr'));
});

test('la commande refuse un auteur sans rôle de curateur et n\'écrit rien', function (): void {
    frameBatchTmdbFake();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $player = User::factory()->player()->create();

    $status = Artisan::call('curation:frame-batch', [
        'file' => frameBatchFile(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 3]])),
        '--actor' => (string) $player->id,
    ]);

    expect($status)->toBe(1)
        ->and($movie->frames()->count())->toBe(0);
});

test('déposer un lot montre son aperçu sans appeler TMDB', function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $curator = User::factory()->curator()->create();
    $ready = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    Frame::factory()->for($ready)->create(['tmdb_file_path' => BATCH_BACKDROP]);
    Movie::factory()->suspended()->create(['tmdb_id' => 111]);

    $data = frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1]]);
    $data['movies'][] = ['tmdb_id' => 111, 'title' => null, 'frames' => [['tmdb_file_path' => BATCH_BACKDROP, 'level' => 2]]];
    $data['movies'][] = ['tmdb_id' => 222, 'title' => 'Absent', 'frames' => [['tmdb_file_path' => BATCH_BACKDROP, 'level' => 3]]];

    $this->actingAs($curator)
        ->post(route('admin.frame_batch.store'), [
            'batch' => UploadedFile::fake()->createWithContent('lot.json', (string) json_encode($data)),
        ])
        ->assertRedirect(route('admin.frame_batch.index'));

    $this->actingAs($curator)
        ->get(route('admin.frame_batch.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/frame-batch/index')
            ->where('frame_batch.status', FrameBatchImport::PREVIEWED)
            ->where('frame_batch.rows.0.status', FrameBatchImport::STATUS_READY)
            ->where('frame_batch.rows.0.known', 1)
            ->where('frame_batch.rows.1.status', FrameBatchImport::STATUS_LOCKED)
            ->where('frame_batch.rows.2.status', FrameBatchImport::STATUS_MISSING)
            ->where('frame_batch.rows.2.title', 'Absent'));

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('un fichier qui n\'est pas un lot est refusé sous le champ du lot', function (): void {
    $this->actingAs(User::factory()->curator()->create())
        ->from(route('admin.frame_batch.index'))
        ->post(route('admin.frame_batch.store'), [
            'batch' => UploadedFile::fake()->createWithContent('lot.json', '{"format":"autre"}'),
        ])
        ->assertRedirect(route('admin.frame_batch.index'))
        ->assertSessionHasErrors(['batch' => __('admin.frame_batch.invalid.format', [], 'fr')]);
});

test('importer un lot confie un seul job à la file, et un lot ne s\'importe qu\'une fois', function (): void {
    $curator = User::factory()->curator()->create();
    Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $token = FrameBatchImport::open($curator, FrameBatch::fromArray(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1]])));

    $this->actingAs($curator)
        ->post(route('admin.frame_batch.import'), ['token' => $token])
        ->assertRedirect(route('admin.frame_batch.index'));

    $this->actingAs($curator)
        ->post(route('admin.frame_batch.import'), ['token' => $token])
        ->assertRedirect(route('admin.frame_batch.index'));

    Queue::assertPushed(ImportFrameBatch::class, 1);
    expect(FrameBatchImport::find($curator->id, $token)['status'] ?? null)->toBe(FrameBatchImport::PENDING);
});

test('un lot est privé à son auteur', function (): void {
    $author = User::factory()->curator()->create();
    $other = User::factory()->curator()->create();
    $token = FrameBatchImport::open($author, FrameBatch::fromArray(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1]])));

    $this->actingAs($other)
        ->get(route('admin.frame_batch.index'))
        ->assertInertia(fn (Assert $page) => $page->where('frame_batch', null));

    $this->actingAs($other)
        ->post(route('admin.frame_batch.import'), ['token' => $token])
        ->assertRedirect(route('admin.frame_batch.index'));

    Queue::assertNothingPushed();
});

test('le job importe le lot et rend le sort de chaque film', function (): void {
    frameBatchTmdbFake();
    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $token = FrameBatchImport::open($curator, FrameBatch::fromArray(frameBatchData([
        ['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1],
        ['tmdb_file_path' => BATCH_BACKDROP_WITH_TEXT, 'level' => 5],
    ])));
    FrameBatchImport::queue($curator->id, $token);

    (new ImportFrameBatch($curator->id, $token))->handle();

    $state = FrameBatchImport::find($curator->id, $token);

    expect($state['status'] ?? null)->toBe(FrameBatchImport::COMPLETED)
        ->and($state['rows'][0]['done'] ?? null)->toBeTrue()
        ->and($state['rows'][0]['added'] ?? null)->toBe(1)
        ->and($state['rows'][0]['refused'] ?? [])->toHaveCount(1)
        ->and($movie->frames()->count())->toBe(1);
});

test('l\'export écrit les images exportables des films demandés', function (): void {
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    Frame::factory()->for($movie)->level(FrameLevel::Level5)->create(['processing_state' => FrameProcessingState::Ready]);
    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'frame-batch-export-'.bin2hex(random_bytes(4)).'.json';

    expect(Artisan::call('curation:export-batch', ['ids' => [(string) BATCH_TMDB_ID], '--out' => $out]))->toBe(0);

    $batch = FrameBatch::fromJson((string) file_get_contents($out));

    expect($batch->framesCount())->toBe(1)
        ->and($batch->movies[0]['frames'][0]['level'])->toBe(FrameLevel::Level5);

    unlink($out);
});

test("un export au-delà des plafonds de l'import se découpe en lots importables", function (): void {
    foreach (range(1, FrameBatch::MAX_MOVIES + 1) as $offset) {
        $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID + $offset]);
        Frame::factory()->for($movie)->level(FrameLevel::Level1)->create(['processing_state' => FrameProcessingState::Ready]);
    }

    $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'frame-batch-split-'.bin2hex(random_bytes(4)).'.json';
    $first = str_replace('.json', '-1.json', $out);
    $second = str_replace('.json', '-2.json', $out);

    expect(Artisan::call('curation:export-batch', ['--all' => true, '--out' => $out]))->toBe(0)
        ->and(file_exists($out))->toBeFalse();

    $batches = [FrameBatch::fromJson((string) file_get_contents($first)), FrameBatch::fromJson((string) file_get_contents($second))];

    expect(count($batches[0]->movies))->toBe(FrameBatch::MAX_MOVIES)
        ->and(count($batches[1]->movies))->toBe(1)
        ->and(filesize($first))->toBeLessThanOrEqual(FrameBatchStoreRequest::MAX_KILOBYTES * 1024);

    unlink($first);
    unlink($second);
});

test('la collecte des candidats est réservée au poste local', function (): void {
    expect(Artisan::call('curation:candidates', ['ids' => [(string) BATCH_TMDB_ID]]))->toBe(1);
});

test('un lot redéposé ne ressuscite jamais une image écartée', function (): void {
    frameBatchTmdbFake();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $curator = User::factory()->curator()->create();
    $crop = FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());
    Frame::factory()->for($movie)->create([
        'tmdb_file_path' => BATCH_BACKDROP,
        'availability' => ContentAvailability::Unpublished,
        'first_published_at' => null,
        'crop_x' => $crop->x,
        'crop_y' => $crop->y,
        'crop_width' => $crop->width,
        'crop_height' => $crop->height,
    ]);

    Artisan::call('curation:frame-batch', [
        'file' => frameBatchFile(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 3, 'crop' => $crop->toArray()]])),
        '--actor' => (string) $curator->id,
    ]);

    expect($movie->frames()->count())->toBe(1)
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'image.tmdb.org')))->toBeEmpty();
});

test('un visuel retiré n\'est jamais réajouté par un lot, même sous un autre cadre', function (): void {
    frameBatchTmdbFake();
    $movie = Movie::factory()->create(['tmdb_id' => BATCH_TMDB_ID]);
    $curator = User::factory()->curator()->create();
    Frame::factory()->for($movie)->withdrawn()->create(['tmdb_file_path' => BATCH_BACKDROP]);

    Artisan::call('curation:frame-batch', [
        'file' => frameBatchFile(frameBatchData([['tmdb_file_path' => BATCH_BACKDROP, 'level' => 1, 'crop' => ['x' => 0, 'y' => 0, 'width' => 640, 'height' => 360]]])),
        '--actor' => (string) $curator->id,
    ]);

    expect($movie->frames()->count())->toBe(1)
        ->and(Artisan::output())->toContain(__('admin.frame_batch.blocked', [], 'fr'))
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'image.tmdb.org')))->toBeEmpty();
});
