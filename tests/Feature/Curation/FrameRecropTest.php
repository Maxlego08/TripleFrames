<?php

use App\Actions\Curation\RecropFrame;
use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Enums\ReviewDecision;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\AdminAction;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Models\User;
use App\Settings\PlatformLimits;
use App\Support\Curation\CoverageLossPreview;
use App\Support\Frames\CropRect;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Database\Factories\MovieFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\Frames\FrameBank;
use Tests\Support\Frames\ImagickLimits;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Re-recadrer une image, en place — contrat C9, spec 20 § 5.7, n° 16
|--------------------------------------------------------------------------
|
| Une image publiée sort du jeu AVANT la réécriture de son rectangle, dans
| la même transaction que la ligne `frame.unpublished` et le recalcul de la
| projection ; le job redérive le rendu après le commit, et l'image ne
| revient en jeu que par une revue sur ses NOUVEAUX octets.
|
| Le disque `frames` est faux ; la file aussi (`Queue::fake()`), sauf là où
| le test joue le job réel sur des octets synthétiques (`SourceImages`).
|
*/

beforeEach(function (): void {
    // Un fichier ne participe à aucune transaction : sans ce `fake`, les
    // octets de fixture partiraient dans la racine réelle du disque.
    Storage::fake(FrameStoragePrefix::DISK);

    // Le test qui joue le job pose les limites d'Imagick pour tout le
    // processus : elles sont relevées avant, et reposées après.
    ImagickLimits::capture();
});

afterEach(function (): void {
    ImagickLimits::restore();
});

/**
 * Le cadre par défaut d'un master 16:9 de 1920 × 1080 : celui des fixtures.
 */
function frameRecropDefaultCrop(): CropRect
{
    return FrameGeometry::defaultCrop(FrameGeometry::masterHeightFor(1920, 1080), PlatformLimits::current());
}

/**
 * Un nouveau cadre conforme sur ce master : huit pas plus serré que le cadre
 * par défaut, calé dans le coin haut gauche — une autre région du visuel.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function frameRecropPayload(array $overrides = []): array
{
    $width = frameRecropDefaultCrop()->width - 8 * FrameGeometry::ASPECT_WIDTH;

    return [
        'crop_x' => 0,
        'crop_y' => 0,
        'crop_width' => $width,
        'crop_height' => intdiv($width * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH),
        ...$overrides,
    ];
}

/**
 * L'envoi du nouveau cadre, posté depuis la fiche du film.
 *
 * @param  array<string, mixed>  $payload
 */
function frameRecropPatch(Frame $frame, array $payload, ?User $curator = null): TestResponse
{
    return test()
        ->actingAs($curator ?? User::factory()->curator()->create())
        ->from(route('admin.catalog.show', ['movie' => $frame->movie_id]))
        ->patch(route('admin.catalog.frames.crop.update', ['movie' => $frame->movie_id, 'frame' => $frame->id]), $payload);
}

/**
 * Un texte du back-office, résolu en français : le domaine `admin` n'existe
 * qu'en français, et la locale ambiante des tests est l'anglais. Une clé
 * absente se rendrait telle quelle des deux côtés d'une comparaison : son
 * existence est donc vérifiée d'abord.
 *
 * @param  array<string, int|string>  $replace
 */
function frameRecropText(string $key, array $replace = []): string
{
    expect(Lang::hasForLocale($key, 'fr'))->toBeTrue("Clé absente du dictionnaire admin : {$key}");

    return (string) __($key, $replace, 'fr');
}

/**
 * Le rectangle d'une frame, pour comparer avant et après un refus.
 *
 * @return array{x: int, y: int, width: int, height: int}
 */
function frameRecropRect(Frame $frame): array
{
    return CropRect::fromFrame($frame->fresh() ?? $frame)->toArray();
}

/**
 * Une image publiée au terme de la chaîne RÉELLE : un original synthétique
 * en dégradé déposé sous `master_path`, le job joué par la file `sync` des
 * tests, puis une revue passante sur les octets produits.
 */
function frameRecropProcessedAndPublished(Movie $movie, FrameLevel $level): Frame
{
    $source = SourceImages::jpeg(1920, 1080);
    $path = FrameStoragePrefix::Master->newPath();
    Storage::disk(FrameStoragePrefix::DISK)->put($path, $source);

    $frame = Frame::factory()->for($movie)->level($level)->create([
        'master_path' => $path,
        'source_hash' => hash('sha256', $source),
    ]);

    ProcessFrameImage::dispatch($frame->id);
    $frame->refresh();

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready);

    $review = FrameReview::factory()->passed()->forFrame($frame)->create();

    $frame->forceFill([
        'availability' => ContentAvailability::Published,
        'availability_changed_at' => now(),
        'first_published_at' => now(),
        'published_review_id' => $review->id,
        'reviewed_at' => $review->reviewed_at,
        'review_grid_version' => $review->grid_version,
    ])->save();

    MovieFactory::recomputeProjection($movie);

    return $frame->refresh();
}

test('recadrer une frame publiée la sort de published et écrit frame.unpublished dans la même transaction', function (): void {
    Queue::fake();

    $curator = User::factory()->curator()->create();
    [$movie, [$level1, $level3a, $level3b, $level5]] = FrameBank::publishedMovie([1, 3, 3, 5]);

    expect(FrameBank::projection($movie)->level_3_variants)->toBe(2);

    frameRecropPatch($level3a, frameRecropPayload(['crop_seconds' => 12, 'reason' => 'Cadre trop large.']), $curator)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    $level3a->refresh();
    $expected = frameRecropPayload();

    // Sortie du jeu, rectangle réécrit, rendu en attente.
    expect($level3a->availability)->toBe(ContentAvailability::Unpublished)
        ->and($level3a->availability_changed_at)->not->toBeNull()
        ->and($level3a->first_published_at)->not->toBeNull()
        ->and($level3a->processing_state)->toBe(FrameProcessingState::Pending)
        ->and(frameRecropRect($level3a))->toBe([
            'x' => $expected['crop_x'],
            'y' => $expected['crop_y'],
            'width' => $expected['crop_width'],
            'height' => $expected['crop_height'],
        ]);

    // La ligne du journal, signée du nom réel, avec le motif saisi.
    $line = AdminAction::query()->where('action', AdminActionType::FrameUnpublished->value)->sole();

    expect($line->subject_id)->toBe($level3a->id)
        ->and($line->actor_id)->toBe($curator->id)
        ->and($line->actor_name)->toBe($curator->real_name)
        ->and($line->reason)->toBe('Cadre trop large.');

    // La projection ne compte plus que la seconde variante du niveau 3.
    expect(FrameBank::projection($movie)->level_3_variants)->toBe(1);

    Queue::assertPushed(ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $level3a->id);

    // Un motif laissé vide : le serveur écrit le texte pré-rempli, jamais sa clé.
    frameRecropPatch($level3b, frameRecropPayload(['reason' => '']), $curator)->assertSessionHasNoErrors();

    expect(AdminAction::query()->where('subject_id', $level3b->id)->sole()->reason)
        ->toBe(frameRecropText('admin.frame.recrop.default_reason'));

    // Même transaction : une panne de la dernière écriture du geste — le
    // recalcul de la projection — annule la sortie du jeu, le rectangle et la
    // ligne du journal, et aucun traitement ne part.
    Event::listen('eloquent.saving: '.MovieProjection::class, function (): never {
        throw new RuntimeException('Panne simulée du recalcul de projection.');
    });

    $before = frameRecropRect($level5);

    expect(fn () => app(RecropFrame::class)->handle($level5, $curator, CropRect::fromArray([
        'x' => 0,
        'y' => 0,
        'width' => $expected['crop_width'],
        'height' => $expected['crop_height'],
    ]), 'Motif.', null))->toThrow(RuntimeException::class, 'Panne simulée');

    $level5->refresh();

    expect($level5->availability)->toBe(ContentAvailability::Published)
        ->and($level5->processing_state)->toBe(FrameProcessingState::Ready)
        ->and(frameRecropRect($level5))->toBe($before)
        ->and(AdminAction::query()->where('subject_id', $level5->id)->exists())->toBeFalse()
        ->and($level1->refresh()->availability)->toBe(ContentAvailability::Published);

    Queue::assertNotPushed(ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $level5->id);
});

test('une frame recadrée ne revient en jeu qu\'après une revue sur ses nouveaux octets', function (): void {
    $movie = Movie::factory()->published()->create();
    $frame = frameRecropProcessedAndPublished($movie, FrameLevel::Level3);
    $oldHash = (string) $frame->published_hash;
    $oldGame = (string) $frame->game_path;

    expect($frame->isServable())->toBeTrue()
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(1);

    // File `sync` : le job part après le commit du geste, dans la requête.
    frameRecropPatch($frame, frameRecropPayload())->assertSessionHasNoErrors();

    $frame->refresh();

    // Le nouveau rendu existe, sous un nouveau nom et d'autres octets…
    expect($frame->processing_state)->toBe(FrameProcessingState::Ready)
        ->and($frame->game_path)->not->toBe($oldGame)
        ->and($frame->published_hash)->not->toBe($oldHash)
        ->and(Storage::disk(FrameStoragePrefix::DISK)->exists($oldGame))->toBeFalse();

    // …mais l'image reste hors du jeu : la seule revue passante cite
    // l'ancienne empreinte, et ne prouve rien des nouveaux octets.
    expect($frame->availability)->toBe(ContentAvailability::Unpublished)
        ->and($frame->isServable())->toBeFalse()
        ->and(FrameBank::projection($movie)->level_3_variants)->toBe(0)
        ->and($frame->publishedReview?->reviewed_hash)->toBe($oldHash)
        ->and($frame->reviews()
            ->where('decision', ReviewDecision::Passed->value)
            ->where('reviewed_hash', $frame->published_hash)
            ->exists())->toBeFalse();

    // Un nouveau traitement ne la remet jamais en jeu : seule une revue le fait.
    ProcessFrameImage::dispatch($frame->id);

    expect($frame->refresh()->availability)->toBe(ContentAvailability::Unpublished);
});

test('un recadrage d\'une frame en traitement est refusé comme occupé', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();

    $frames = [
        // Un re-recadrage déjà en file : le rendu précédent existe encore.
        'nouveau rendu en file' => Frame::factory()->for($movie)->withFiles()->create([
            'processing_state' => FrameProcessingState::Pending,
        ]),
        // Un premier traitement en file : aucun rendu encore — « en
        // traitement », et non « sans rendu ».
        'premier traitement en file' => Frame::factory()->for($movie)->create(),
    ];

    foreach ($frames as $label => $frame) {
        $before = frameRecropRect($frame);

        frameRecropPatch($frame, frameRecropPayload())
            ->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]))
            ->assertSessionHasErrors(['frame' => frameRecropText('admin.frame.recrop.busy')]);

        expect(frameRecropRect($frame))->toBe($before, $label)
            ->and($frame->refresh()->processing_state)->toBe(FrameProcessingState::Pending, $label);
    }

    Queue::assertNothingPushed();
    expect(AdminAction::query()->count())->toBe(0);
});

test('recadrer une frame suspendue ou retirée est refusé par une erreur traduite et jamais par un 403', function (ContentAvailability $state): void {
    Queue::fake();

    $curator = User::factory()->curator()->create();
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create(['availability' => $state]);
    $before = frameRecropRect($frame);

    // La garde de la route laisse passer : `FramePolicy::update` n'a aucune
    // condition d'état.
    expect(Gate::forUser($curator)->allows('update', $frame))->toBeTrue();

    $response = frameRecropPatch($frame, frameRecropPayload(), $curator);

    expect($response->getStatusCode())->toBe(302);

    $response->assertSessionHasErrors(['frame' => frameRecropText('admin.frame.recrop.locked')]);

    expect($frame->refresh()->availability)->toBe($state)
        ->and(frameRecropRect($frame))->toBe($before)
        ->and(AdminAction::query()->count())->toBe(0);

    // L'action rejoue le refus sous le verrou, même appelée sans la requête.
    expect(fn () => app(RecropFrame::class)->handle($frame, $curator, frameRecropDefaultCrop(), null, null))
        ->toThrow(ValidationException::class);

    Queue::assertNothingPushed();
})->with([
    'suspendue' => ContentAvailability::Suspended,
    'retirée' => ContentAvailability::Withdrawn,
]);

test('recadrer une frame publiée qui porte seule un niveau 1, 3 ou 5 avertit avant confirmation', function (): void {
    Queue::fake();

    [$movie, [$level1, $level3a, $level3b, $level5]] = FrameBank::publishedMovie([1, 3, 3, 5]);
    $preview = app(CoverageLossPreview::class);
    $projectionBefore = FrameBank::projection($movie)->toArray();

    // Seule variante du niveau 1, ou du niveau 5 : le film deviendrait
    // incomplet, jouable jusqu'à N = 2 avec les niveaux restants.
    expect($preview->forFrame($level1))->toBe(['frame_id' => $level1->id, 'playable_up_to' => 2])
        ->and($preview->forFrame($level5))->toBe(['frame_id' => $level5->id, 'playable_up_to' => 2]);

    // Deux variantes au niveau 3 : la couverture tient, rien à annoncer.
    expect($preview->forFrame($level3a))->toBeNull()
        ->and($preview->forFrame($level3b))->toBeNull();

    // Le texte annoncé nomme ce N.
    expect(frameRecropText('admin.frame.unpublish.coverage_warning', ['max' => 2]))->toContain('N = 2');

    // L'aperçu n'écrit rien.
    expect(FrameBank::projection($movie)->toArray())->toBe($projectionBefore)
        ->and($level1->refresh()->availability)->toBe(ContentAvailability::Published);

    // Un film encore en brouillon n'a pas de couverture à perdre.
    [, [$draftOnly]] = FrameBank::movieWith(Movie::factory()->create(), [1, 3, 5]);

    expect($preview->forFrame($draftOnly))->toBeNull();

    // Le geste reste permis, et le film reste publié, désormais incomplet.
    frameRecropPatch($level1, frameRecropPayload())->assertSessionHasNoErrors();

    expect($movie->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(FrameBank::projection($movie)->coversPublishableLevels())->toBeFalse()
        ->and(CoverageLossPreview::playableUpTo(FrameBank::projection($movie)->levels_mask))->toBe(2);
});

test('un re-recadrage ne réécrit jamais un crop_seconds déjà posé', function (): void {
    Queue::fake();

    $curator = User::factory()->curator()->create();
    $firstPass = Movie::factory()->create();
    $recrop = fn (Frame $frame, int $seconds) => frameRecropPatch($frame, frameRecropPayload(['crop_seconds' => $seconds]), $curator)
        ->assertSessionHasNoErrors();

    // Déjà posé : le premier recadrage reste la mesure.
    $measured = Frame::factory()->for($firstPass)->withFiles()->create(['crop_seconds' => 30]);
    $recrop($measured, 99);

    expect($measured->refresh()->crop_seconds)->toBe(30);

    // Jamais posé, film en passe 1 : le re-recadrage l'écrit, plafonné.
    $unmeasured = Frame::factory()->for($firstPass)->withFiles()->create(['crop_seconds' => null]);
    $recrop($unmeasured, 99);

    expect($unmeasured->refresh()->crop_seconds)->toBe(99);

    $forgotten = Frame::factory()->for($firstPass)->withFiles()->create(['crop_seconds' => null]);
    $recrop($forgotten, 1_000_000);

    expect($forgotten->refresh()->crop_seconds)->toBe(Config::integer('catalog.curation.crop_seconds_max'));

    // Film terminé — publié, ou écarté sans jamais l'avoir été : la mesure
    // de la passe 1 ne bouge plus.
    $terminated = [
        'publié' => Movie::factory()->published()->create(),
        'écarté' => Movie::factory()->create(['availability' => ContentAvailability::Unpublished]),
    ];

    foreach ($terminated as $label => $movie) {
        $frame = Frame::factory()->for($movie)->withFiles()->create(['crop_seconds' => null]);
        $recrop($frame, 99);

        expect($frame->refresh()->crop_seconds)->toBeNull($label);
    }
});

test('recadrer une frame sans rendu, ou dont le master manque, est refusé avant toute écriture', function (): void {
    Queue::fake();

    $movie = Movie::factory()->create();

    // Premier traitement en échec définitif : aucun rendu à recadrer.
    $failed = Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::SourceTooSmall)->create();

    frameRecropPatch($failed, frameRecropPayload())
        ->assertSessionHasErrors(['frame' => frameRecropText('admin.frame.recrop.not_ready')]);

    // Un rendu, mais plus de master : le job échouerait après avoir sorti
    // l'image du jeu.
    $orphan = Frame::factory()->for($movie)->published()->create();
    Storage::disk(FrameStoragePrefix::DISK)->delete((string) $orphan->master_path);

    frameRecropPatch($orphan, frameRecropPayload())
        ->assertSessionHasErrors(['frame' => frameRecropText('admin.frame.processing_error.source_missing')]);

    expect($orphan->refresh()->availability)->toBe(ContentAvailability::Published)
        ->and(AdminAction::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

test('un cadre qui dépasse la borne de hauteur du master réel est refusé avant toute écriture', function (): void {
    Queue::fake();

    // Un master plus large que 16:9 (1920 × 800) : le cadre par défaut d'un
    // master 16:9, admis par la requête qui ne connaît que la largeur,
    // couvrirait plus que la fraction admise de sa hauteur.
    $frame = Frame::factory()->for(Movie::factory()->create())->withFiles()->create();
    Storage::disk(FrameStoragePrefix::DISK)->put((string) $frame->master_path, SourceImages::webp(FrameGeometry::MASTER_WIDTH, 800));
    $before = frameRecropRect($frame);
    $default = frameRecropDefaultCrop();

    frameRecropPatch($frame, [
        'crop_x' => 0,
        'crop_y' => 0,
        'crop_width' => $default->width,
        'crop_height' => $default->height,
    ])->assertSessionHasErrors(['crop' => frameRecropText('admin.validation.crop.too_wide')]);

    expect(frameRecropRect($frame))->toBe($before)
        ->and($frame->refresh()->processing_state)->toBe(FrameProcessingState::Ready);

    Queue::assertNothingPushed();
});

test('relancer n\'est accepté que pour un échec rejouable', function (): void {
    Queue::fake();

    $curator = User::factory()->curator()->create();
    $movie = Movie::factory()->create();
    $retry = fn (Frame $frame): TestResponse => test()
        ->actingAs($curator)
        ->from(route('admin.catalog.show', ['movie' => $movie->id]))
        ->post(route('admin.catalog.frames.retry', ['movie' => $movie->id, 'frame' => $frame->id]));

    // Rejouable : l'image repart en traitement sur les octets gardés.
    $retryable = Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::ResourceLimit)->create();

    $retry($retryable)->assertSessionHasNoErrors()->assertRedirect(route('admin.catalog.show', ['movie' => $movie->id]));

    expect($retryable->refresh()->processing_state)->toBe(FrameProcessingState::Pending)
        ->and($retryable->processing_error)->toBeNull();

    Queue::assertPushed(ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $retryable->id);

    // Définitif, déjà traité ou déjà en file : refus traduit, rien ne part.
    $refused = [
        'échec définitif' => [Frame::factory()->for($movie)->processingFailed(FrameProcessingFailure::CropInvalid)->create(), 'admin.frame.retry.not_retryable'],
        'déjà traité' => [Frame::factory()->for($movie)->withFiles()->create(), 'admin.frame.retry.not_retryable'],
        'déjà en file' => [Frame::factory()->for($movie)->create(), 'admin.frame.recrop.busy'],
        'suspendue' => [Frame::factory()->for($movie)->processingFailed()->create(['availability' => ContentAvailability::Suspended]), 'admin.frame.recrop.locked'],
    ];

    foreach ($refused as $label => [$frame, $key]) {
        $state = $frame->processing_state;

        $retry($frame)->assertSessionHasErrors(['frame' => frameRecropText($key)]);

        expect($frame->refresh()->processing_state)->toBe($state, $label);
        Queue::assertNotPushed(ProcessFrameImage::class, fn (ProcessFrameImage $job): bool => $job->frameId === $frame->id);
    }
});
