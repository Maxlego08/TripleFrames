<?php

use App\Enums\ContentAvailability;
use App\Enums\FrameProcessingFailure;
use App\Enums\FrameProcessingState;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Settings\PlatformLimits;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameStoragePrefix;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Frames\ImagickLimits;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Chaîne Imagick et job de traitement — contrat C9 (spec 20 § 5.5 et § 5.6)
|--------------------------------------------------------------------------
|
| Chaque test dépose des octets SYNTHÉTIQUES sous `master_path`, exactement
| comme la requête d'ajout dépose l'original téléchargé, puis joue le job par
| la file `sync` des tests. Aucune image réelle : les sources sont générées
| par Imagick au moment du test (`Tests\Support\Frames\SourceImages`).
|
*/

beforeEach(function (): void {
    // Un fichier ne participe à aucune transaction : sans ce `fake`, les
    // octets partiraient dans la racine réelle du disque et y resteraient.
    Storage::fake(FrameStoragePrefix::DISK);
    ImagickLimits::capture();
});

afterEach(function (): void {
    // Les limites d'Imagick valent pour tout le processus : celles que la
    // chaîne pose, ou qu'un test resserre, n'atteignent pas les suivants.
    ImagickLimits::restore();
});

/**
 * Une frame fraîchement ajoutée : `draft`, `pending`, l'original déposé sous
 * `master_path`, `source_hash` calculé sur lui, et le rectangle par défaut du
 * master que produira cette source.
 *
 * @param  array<string, mixed>  $attributes
 */
function processFrameAdded(string $source, array $attributes = []): Frame
{
    $path = FrameStoragePrefix::Master->newPath();
    Storage::disk(FrameStoragePrefix::DISK)->put($path, $source);

    $size = @getimagesizefromstring($source);
    $crop = [];

    if ($size !== false && $size[0] >= $size[1]) {
        $masterHeight = FrameGeometry::masterHeightFor($size[0], $size[1]);

        if (FrameGeometry::maxCropWidth($masterHeight, PlatformLimits::current()) >= PlatformLimits::current()->frameCropMinWidthPx) {
            $rect = FrameGeometry::defaultCrop($masterHeight, PlatformLimits::current());
            $crop = [
                'crop_x' => $rect->x,
                'crop_y' => $rect->y,
                'crop_width' => $rect->width,
                'crop_height' => $rect->height,
            ];
        }
    }

    return Frame::factory()->create([
        'master_path' => $path,
        'source_hash' => hash('sha256', $source),
        ...$crop,
        ...$attributes,
    ]);
}

/**
 * Joue le job par la file des tests (`sync`), et relit la frame.
 */
function processFrameRun(Frame $frame): Frame
{
    ProcessFrameImage::dispatch($frame->id);

    return $frame->fresh() ?? throw new RuntimeException('Frame disparue.');
}

/**
 * Les octets servis d'une frame traitée.
 */
function processFrameGameBytes(Frame $frame): string
{
    return (string) Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->game_path);
}

/**
 * Tous les fichiers du disque, triés.
 *
 * @return list<string>
 */
function processFrameFiles(): array
{
    $files = Storage::disk(FrameStoragePrefix::DISK)->allFiles();
    sort($files);

    return array_values($files);
}

/**
 * Une image décodée ne porte ni profil (EXIF, XMP, ICC, IPTC) ni commentaire,
 * et son conteneur WebP ne compte aucun bloc de métadonnées ni d'animation.
 */
function processFrameAssertStripped(string $webp): void
{
    $chunks = SourceImages::riffChunks($webp);

    expect($chunks)->not->toBeEmpty();
    expect(array_diff($chunks, ['VP8 ', 'VP8L']))->toBe([], 'Bloc WebP inattendu : '.implode(',', $chunks));

    $image = new Imagick;
    $image->readImageBlob($webp);

    expect($image->getNumberImages())->toBe(1);
    expect($image->getImageProfiles())->toBe([]);
    expect($image->getImageProperties('comment'))->toBe([]);
    expect($image->getImageColorspace())->toBe(Imagick::COLORSPACE_SRGB);
    expect($webp)->not->toContain(SourceImages::COMMENT);
    expect($webp)->not->toContain('tripleframes-fixture-xmp');

    $image->clear();
}

it('le dérivé est un WebP statique de 1280 × 720 sans métadonnées', function (): void {
    $frame = processFrameRun(processFrameAdded(SourceImages::jpeg(2560, 1440, withMetadata: true)));

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready);
    expect($frame->processing_error)->toBeNull();
    expect($frame->game_width)->toBe(FrameGeometry::GAME_WIDTH);
    expect($frame->game_height)->toBe(FrameGeometry::GAME_HEIGHT);

    $game = processFrameGameBytes($frame);
    $size = getimagesizefromstring($game);

    expect($size)->not->toBeFalse();
    expect([$size[0] ?? null, $size[1] ?? null, $size['mime'] ?? null])
        ->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT, 'image/webp']);

    processFrameAssertStripped($game);

    // Le master aussi : dépouillé, WebP, largeur EXACTE du master, et plus
    // jamais l'original reçu.
    $master = (string) Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->master_path);
    $masterSize = getimagesizefromstring($master);

    expect([$masterSize[0] ?? null, $masterSize[1] ?? null, $masterSize['mime'] ?? null])
        ->toBe([FrameGeometry::MASTER_WIDTH, FrameGeometry::masterHeightFor(2560, 1440), 'image/webp']);
    expect(hash('sha256', $master))->not->toBe($frame->source_hash);

    processFrameAssertStripped($master);

    // Une source à canal alpha ressort opaque, au même format.
    $alpha = processFrameRun(processFrameAdded(SourceImages::png(1920, 1080, withAlpha: true)));
    $decoded = new Imagick;
    $decoded->readImageBlob(processFrameGameBytes($alpha));

    expect($alpha->processing_state)->toBe(FrameProcessingState::Ready);
    expect($decoded->getImageAlphaChannel())->toBeFalse();
    expect([$decoded->getImageWidth(), $decoded->getImageHeight()])
        ->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT]);
});

it('game_bytes est un multiple de 8 192 et ne dépasse jamais 147 456', function (): void {
    // Un plasma à qualité de départ maximale dépasse le plafond : l'encodage
    // DOIT descendre pour tenir.
    config()->set('catalog.curation.webp.game_quality_start', 100);

    $frame = processFrameRun(processFrameAdded(SourceImages::plasma(1920, 1080)));

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready);
    expect(FrameGeometry::gameEncodeCeilingBytes())->toBe(147_456);
    expect($frame->game_bytes)->not->toBeNull();
    expect($frame->game_bytes % FrameGeometry::GAME_PAD_BYTES)->toBe(0);
    expect($frame->game_bytes)->toBeLessThanOrEqual(FrameGeometry::gameEncodeCeilingBytes());
    expect(strlen(processFrameGameBytes($frame)))->toBe($frame->game_bytes);

    // La descente a bien eu lieu : les mêmes pixels, à la qualité de départ,
    // dépassent le plafond.
    $decoded = new Imagick;
    $decoded->readImageBlob(processFrameGameBytes($frame));
    $decoded->setImageFormat('webp');
    $decoded->setImageCompressionQuality(100);

    expect(strlen($decoded->getImageBlob()))->toBeGreaterThan(FrameGeometry::gameEncodeCeilingBytes());
});

it('le padding ne casse pas le décodage', function (): void {
    $frame = processFrameRun(processFrameAdded(SourceImages::jpeg(1920, 1080)));
    $padded = processFrameGameBytes($frame);

    $riffSize = unpack('Vsize', $padded, 4);
    $blockEnd = 8 + (int) (is_array($riffSize) ? $riffSize['size'] : 0);

    // Le champ de taille RIFF borne le bloc réel ; la traîne n'est faite que
    // de NUL.
    expect($blockEnd)->toBeLessThanOrEqual(strlen($padded));
    expect(strlen($padded))->toBe(FrameGeometry::paddedLength($blockEnd));
    expect(strspn($padded, "\0", $blockEnd))->toBe(strlen($padded) - $blockEnd);

    $withPadding = new Imagick;
    $withPadding->readImageBlob($padded);
    $withoutPadding = new Imagick;
    $withoutPadding->readImageBlob(substr($padded, 0, $blockEnd));

    expect($withPadding->getNumberImages())->toBe(1);
    expect([$withPadding->getImageWidth(), $withPadding->getImageHeight()])
        ->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT]);
    expect($withPadding->getImageSignature())->toBe($withoutPadding->getImageSignature());
});

it('published_hash est l\'empreinte des octets paddés', function (): void {
    $frame = processFrameRun(processFrameAdded(SourceImages::jpeg(1920, 1080)));
    $padded = processFrameGameBytes($frame);

    expect($frame->published_hash)->toBe(hash('sha256', $padded));
    expect($frame->game_bytes)->toBe(strlen($padded));
    expect($frame->game_bytes % FrameGeometry::GAME_PAD_BYTES)->toBe(0);
    // Jamais l'empreinte de l'original : c'est `source_hash`, posé une fois.
    expect($frame->published_hash)->not->toBe($frame->source_hash);
});

it('un WebP animé échoue en source_animated', function (): void {
    // Largeur exacte du master : la normalisation n'est jamais sautée pour
    // l'original reçu, même déjà WebP de 1920 de large.
    $frame = processFrameRun(processFrameAdded(SourceImages::animatedWebp(FrameGeometry::MASTER_WIDTH, 1080)));

    expect($frame->processing_state)->toBe(FrameProcessingState::Failed);
    expect($frame->processing_error)->toBe(FrameProcessingFailure::SourceAnimated);
    expect($frame->game_path)->toBeNull();
});

it('une source de moins de 1 280 px échoue en source_too_small', function (): void {
    $frame = processFrameRun(processFrameAdded(SourceImages::jpeg(FrameGeometry::GAME_WIDTH - 1, 720)));

    expect($frame->processing_state)->toBe(FrameProcessingState::Failed);
    expect($frame->processing_error)->toBe(FrameProcessingFailure::SourceTooSmall);
    expect($frame->game_path)->toBeNull();

    // Exactement 1 280 px passe, sur-échantillonnée jusqu'au master.
    $edge = processFrameRun(processFrameAdded(SourceImages::jpeg(FrameGeometry::GAME_WIDTH, 720)));

    expect($edge->processing_state)->toBe(FrameProcessingState::Ready);
});

it('le job refuse une frame withdrawn sans écrire de fichier', function (): void {
    $source = SourceImages::jpeg(1920, 1080);
    $frame = processFrameAdded($source, [
        'availability' => ContentAvailability::Withdrawn,
        'availability_changed_at' => now(),
    ]);
    $before = processFrameFiles();

    $frame = processFrameRun($frame);

    expect(processFrameFiles())->toBe($before);
    expect(Storage::disk(FrameStoragePrefix::DISK)->get((string) $frame->master_path))->toBe($source);
    expect($frame->availability)->toBe(ContentAvailability::Withdrawn);
    expect($frame->processing_state)->toBe(FrameProcessingState::Failed);
    expect($frame->processing_error)->toBe(FrameProcessingFailure::Withdrawn);
    expect($frame->game_path)->toBeNull();
});

it('le job refuse de réécrire une frame published', function (): void {
    $frame = Frame::factory()->published()->create();
    $before = DB::table('frame')->where('id', $frame->id)->first();
    $files = processFrameFiles();
    $bytes = processFrameGameBytes($frame);

    $after = processFrameRun($frame);

    // Ni la ligne — pas même son état de traitement, qui la sortirait du jeu —
    // ni les fichiers.
    expect(DB::table('frame')->where('id', $frame->id)->first())->toEqual($before);
    expect(processFrameFiles())->toBe($files);
    expect(processFrameGameBytes($after))->toBe($bytes);
    expect($after->processing_state)->toBe(FrameProcessingState::Ready);
    expect($after->isServable())->toBeTrue();
});

it('chaque échec pose failed et une clé FrameProcessingFailure', function (FrameProcessingFailure $expected, Closure $arrange, ?string $thrown = null): void {
    /** @var Frame $frame */
    $frame = $arrange();

    if ($thrown === null) {
        $frame = processFrameRun($frame);
    } else {
        // Une panne qui n'est pas un échec connu de la chaîne remonte : la
        // file la rejoue, puis `failed()` la consigne en `unexpected`.
        expect(fn () => ProcessFrameImage::dispatchSync($frame->id))->toThrow($thrown);

        $frame->refresh();
    }

    expect($frame->processing_state)->toBe(FrameProcessingState::Failed);
    expect($frame->processing_error)->toBe($expected);
    expect($frame->game_path)->toBeNull();
    // La colonne porte la CLÉ, jamais un message brut, et la clé existe.
    expect(DB::table('frame')->where('id', $frame->id)->value('processing_error'))->toBe($expected->value);
    expect($expected->value)->toStartWith('admin.frame.processing_error.');
    expect(Lang::has($expected->value, 'fr', false))->toBeTrue();
    expect(Storage::disk(FrameStoragePrefix::DISK)->allFiles(rtrim(FrameStoragePrefix::Game->value, '/')))->toBe([]);
})->with([
    'source_missing' => [
        FrameProcessingFailure::SourceMissing,
        fn (): Frame => Frame::factory()->create(['master_path' => FrameStoragePrefix::Master->newPath()]),
    ],
    'source_unreadable' => [
        FrameProcessingFailure::SourceUnreadable,
        fn (): Frame => processFrameAdded(SourceImages::truncatedJpeg()),
    ],
    'source_format' => [
        FrameProcessingFailure::SourceFormat,
        // Le format est refusé sur l'en-tête, avant toute dimension.
        fn (): Frame => processFrameAdded(SourceImages::gif(64, 36)),
    ],
    'source_animated' => [
        FrameProcessingFailure::SourceAnimated,
        fn (): Frame => processFrameAdded(SourceImages::animatedWebp(1920, 1080)),
    ],
    'source_too_small' => [
        FrameProcessingFailure::SourceTooSmall,
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1024, 576)),
    ],
    'source_aspect (portrait)' => [
        FrameProcessingFailure::SourceAspect,
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1280, 1600)),
    ],
    'source_aspect (aucun cadre admis)' => [
        FrameProcessingFailure::SourceAspect,
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1920, 300)),
    ],
    'crop_invalid' => [
        FrameProcessingFailure::CropInvalid,
        // Un cadre valable sur un master 16:9 déborde d'un master plus bas.
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1920, 800), [
            'crop_x' => 192,
            'crop_y' => 108,
            'crop_width' => 1536,
            'crop_height' => 864,
        ]),
    ],
    'too_heavy' => [
        FrameProcessingFailure::TooHeavy,
        function (): Frame {
            // Une seule qualité essayée : le bruit la dépasse de loin.
            config()->set('catalog.curation.webp.game_quality_start', 40);

            return processFrameAdded(SourceImages::noise(1920, 1080));
        },
    ],
    'resource_limit' => [
        FrameProcessingFailure::ResourceLimit,
        function (): Frame {
            config()->set('catalog.curation.imagick.width_px', 1600);

            return processFrameAdded(SourceImages::jpeg(1920, 1080));
        },
    ],
    'withdrawn' => [
        FrameProcessingFailure::Withdrawn,
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1920, 1080), [
            'availability' => ContentAvailability::Withdrawn,
        ]),
    ],
    'unexpected' => [
        FrameProcessingFailure::Unexpected,
        function (): Frame {
            // Une configuration illisible n'est pas un échec connu de la chaîne.
            config()->set('catalog.curation.imagick.memory_mb', 'illisible');

            return processFrameAdded(SourceImages::jpeg(1920, 1080));
        },
        InvalidArgumentException::class,
    ],
]);

it('Relancer n\'est offert qu\'à un échec rejouable', function (): void {
    $retryable = array_values(array_filter(
        FrameProcessingFailure::cases(),
        static fn (FrameProcessingFailure $failure): bool => $failure->isRetryable(),
    ));

    expect($retryable)->toBe([FrameProcessingFailure::ResourceLimit, FrameProcessingFailure::Unexpected]);

    foreach (FrameProcessingFailure::cases() as $failure) {
        $frame = Frame::factory()->processingFailed($failure)->create();
        $props = AdminCatalogPresenter::movieFrame($frame);

        expect($props['processing_error'])->toBe($failure->value);
        expect($props['is_retryable'])->toBe($failure->isRetryable(), $failure->name);

        // La fixture suit la chaîne réelle : « Relancer » n'est offert que là
        // où les octets qu'il réutilise sont encore sur le disque.
        expect(Storage::disk(FrameStoragePrefix::DISK)->exists((string) $frame->master_path))
            ->toBe($failure->isRetryable(), $failure->name);
    }

    // Jamais hors d'un échec : une frame en attente ou prête n'offre rien.
    expect(AdminCatalogPresenter::movieFrame(Frame::factory()->create())['is_retryable'])->toBeFalse();
    expect(AdminCatalogPresenter::movieFrame(Frame::factory()->withFiles()->create())['is_retryable'])->toBeFalse();

    // Et de bout en bout : l'échec définitif écrit par le job n'offre pas
    // « Relancer », l'échec rejouable l'offre.
    $definitive = processFrameRun(processFrameAdded(SourceImages::jpeg(1024, 576)));
    config()->set('catalog.curation.imagick.width_px', 1600);
    $transient = processFrameRun(processFrameAdded(SourceImages::jpeg(1920, 1080)));

    expect(AdminCatalogPresenter::movieFrame($definitive)['is_retryable'])->toBeFalse();
    expect(AdminCatalogPresenter::movieFrame($transient)['is_retryable'])->toBeTrue();
});

it('chaque traitement écrit un nouveau game_path et supprime l\'ancien', function (): void {
    $frame = processFrameRun(processFrameAdded(SourceImages::jpeg(1920, 1080)));
    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $first = (string) $frame->game_path;
    $master = (string) $disk->get((string) $frame->master_path);

    expect($disk->exists($first))->toBeTrue();

    // Un re-recadrage en place : nouveau rectangle, `pending`, nouveau job.
    $frame->forceFill([
        'crop_x' => 0,
        'crop_y' => 0,
        'crop_width' => FrameGeometry::GAME_WIDTH,
        'crop_height' => FrameGeometry::GAME_HEIGHT,
        'processing_state' => FrameProcessingState::Pending,
    ])->save();

    $frame = processFrameRun($frame);
    $second = (string) $frame->game_path;

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready);
    expect($second)->not->toBe($first);
    expect(FrameStoragePrefix::Game->owns($second))->toBeTrue();
    expect($disk->exists($second))->toBeTrue();
    expect($disk->exists($first))->toBeFalse();
    expect($frame->published_hash)->toBe(hash('sha256', (string) $disk->get($second)));
    // Le master déjà normalisé n'est pas réencodé : aucune perte de génération.
    expect($disk->get((string) $frame->master_path))->toBe($master);
    expect($disk->allFiles(rtrim(FrameStoragePrefix::Game->value, '/')))->toBe([$second]);
});

it('le job part sur default et jamais sur game', function (): void {
    Queue::fake();

    $frame = Frame::factory()->create();

    ProcessFrameImage::dispatch($frame->id);

    Queue::assertPushedOn('default', ProcessFrameImage::class);
    Queue::assertPushed(ProcessFrameImage::class, function (ProcessFrameImage $job) use ($frame): bool {
        return $job->queue === 'default'
            && $job->queue !== 'game'
            && $job->afterCommit === true
            && $job->frameId === $frame->id
            && $job->uniqueId() === (string) $frame->id
            && $job->tries === 3
            && $job->backoff === [30, 120];
    });

    $job = new ProcessFrameImage($frame->id);

    expect($job)->toBeInstanceOf(ShouldQueue::class);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect(ProcessFrameImage::QUEUE)->toBe('default');
});

it('Imagick sait encoder le WebP', function (): void {
    // Jamais sauté : sans Imagick ou sans codec WebP, la CI doit échouer.
    expect(extension_loaded('imagick'))->toBeTrue();

    $image = new Imagick;
    $image->newPseudoImage(FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT, 'gradient:navy-orange');
    $image->setImageFormat('webp');
    $image->setImageCompressionQuality(80);
    $webp = $image->getImageBlob();
    $image->clear();

    expect(substr($webp, 0, 4))->toBe('RIFF');
    expect(substr($webp, 8, 4))->toBe('WEBP');

    $decoded = new Imagick;
    $decoded->readImageBlob($webp);

    expect($decoded->getImageFormat())->toBe('WEBP');
    expect([$decoded->getImageWidth(), $decoded->getImageHeight()])
        ->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT]);

    // Les limites que pose la chaîne existent dans cette extension.
    foreach (['MEMORY', 'MAP', 'AREA', 'WIDTH', 'HEIGHT', 'TIME'] as $type) {
        expect(defined(Imagick::class.'::RESOURCETYPE_'.$type))->toBeTrue($type);
    }
});

it('un échec définitif au premier traitement ne laisse aucun original sur le disque', function (Closure $arrange, FrameProcessingFailure $expected): void {
    /** @var Frame $frame */
    $frame = $arrange();
    $path = (string) $frame->master_path;

    expect(Storage::disk(FrameStoragePrefix::DISK)->exists($path))->toBeTrue();

    $frame = processFrameRun($frame);

    expect($frame->processing_error)->toBe($expected);
    expect($expected->isRetryable())->toBeFalse();
    // Le chemin reste posé (colonne UNIQUE, jamais réaffectée), le fichier non.
    expect($frame->master_path)->toBe($path);
    expect($frame->game_path)->toBeNull();
    expect(processFrameFiles())->toBe([]);
})->with([
    'refusée avant la normalisation' => [
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1024, 576, withMetadata: true)),
        FrameProcessingFailure::SourceTooSmall,
    ],
    'refusée après la normalisation' => [
        fn (): Frame => processFrameAdded(SourceImages::jpeg(1920, 800), [
            'crop_x' => 192,
            'crop_y' => 108,
            'crop_width' => 1536,
            'crop_height' => 864,
        ]),
        FrameProcessingFailure::CropInvalid,
    ],
]);

it('un échec rejouable garde les octets que Relancer réutilise', function (): void {
    $source = SourceImages::jpeg(1920, 1080, withMetadata: true);
    config()->set('catalog.curation.imagick.width_px', 1600);

    $frame = processFrameRun(processFrameAdded($source));
    $disk = Storage::disk(FrameStoragePrefix::DISK);

    expect($frame->processing_error)->toBe(FrameProcessingFailure::ResourceLimit);
    expect($frame->processing_error?->isRetryable())->toBeTrue();
    expect($disk->get((string) $frame->master_path))->toBe($source);

    // « Relancer » : mêmes octets, même rectangle, un nouveau job.
    config()->set('catalog.curation.imagick.width_px', 8_192);
    $frame->forceFill(['processing_state' => FrameProcessingState::Pending])->save();

    $frame = processFrameRun($frame);

    expect($frame->processing_state)->toBe(FrameProcessingState::Ready);
    expect($frame->processing_error)->toBeNull();
    expect($frame->source_hash)->toBe(hash('sha256', $source));
    // L'original a servi, puis n'est plus conservé : le master l'a remplacé.
    expect(hash('sha256', (string) $disk->get((string) $frame->master_path)))->not->toBe($frame->source_hash);
    expect($disk->exists((string) $frame->game_path))->toBeTrue();
});
