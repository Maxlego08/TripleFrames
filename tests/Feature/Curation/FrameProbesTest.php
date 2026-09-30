<?php

use App\Enums\ContentAvailability;
use App\Jobs\Curation\ProcessFrameImage;
use App\Models\Frame;
use App\Models\FrameReview;
use App\Settings\PlatformLimits;
use App\Support\Frames\FrameGeometry;
use App\Support\Frames\FrameProbes;
use App\Support\Frames\FrameStoragePrefix;
use App\Support\Frames\WebpPadding;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Frames\ImagickLimits;
use Tests\Support\Frames\SourceImages;

/*
|--------------------------------------------------------------------------
| Sondes de frame — spec 20 § 5.9 (E10-18, E10-19)
|--------------------------------------------------------------------------
|
| Les quatre requêtes de `FrameProbes`, que la sonde `integrity` de la spec
| 100 exécute en production. Chacune doit compter zéro sur un catalogue
| conforme : le catalogue de démonstration, une frame de chaque état de la
| fabrique, et une frame réellement produite par la chaîne Imagick. Chaque
| test prouve aussi que sa sonde n'est pas vide de sens, en y faisant entrer
| une frame fautive.
|
*/

beforeEach(function (): void {
    Storage::fake(FrameStoragePrefix::DISK);
    ImagickLimits::capture();

    $this->seed(DatabaseSeeder::class);

    // Une frame de chaque état de la fabrique, et une frame traitée par la
    // chaîne réelle, à côté du catalogue de démonstration.
    Frame::factory()->create();
    Frame::factory()->withFiles()->create();
    Frame::factory()->published()->create();
    Frame::factory()->processingFailed()->create();

    $source = SourceImages::jpeg(1920, 1080);
    $path = FrameStoragePrefix::Master->newPath();
    Storage::disk(FrameStoragePrefix::DISK)->put($path, $source);

    $processed = Frame::factory()->create([
        'master_path' => $path,
        'source_hash' => hash('sha256', $source),
    ]);

    ProcessFrameImage::dispatch($processed->id);

    $this->processed = $processed->refresh();
});

afterEach(function (): void {
    ImagickLimits::restore();
});

it('aucune frame published sans published_review_id', function (): void {
    expect(Frame::query()->where('availability', ContentAvailability::Published->value)->count())->toBeGreaterThan(0);
    expect(FrameProbes::publishedWithoutReview()->count())->toBe(0);

    // La sonde voit une frame publiée sans preuve.
    $orphan = Frame::factory()->published()->create();
    $orphan->forceFill(['published_review_id' => null])->save();

    expect(FrameProbes::publishedWithoutReview()->pluck('id')->all())->toBe([$orphan->id]);
});

it('le published_hash de toute frame published égale le reviewed_hash de sa revue', function (): void {
    expect(FrameProbes::publishedHashMismatch()->count())->toBe(0);

    // Des octets servis qui ne sont pas ceux que la revue a hachés.
    $rehashed = Frame::factory()->published()->create();
    $rehashed->forceFill(['published_hash' => hash('sha256', 'autres octets')])->save();

    // Une preuve empruntée à une autre frame, à l'empreinte pourtant égale :
    // les fixtures servent toutes les mêmes octets.
    $borrowed = Frame::factory()->published()->create();
    $lender = Frame::factory()->published()->create();
    $borrowed->forceFill(['published_review_id' => $lender->published_review_id])->save();

    expect(FrameReview::query()->findOrFail($lender->published_review_id)->reviewed_hash)
        ->toBe($borrowed->published_hash);

    // Une preuve qui n'existe pas.
    $ghost = Frame::factory()->published()->create();
    $ghost->forceFill(['published_review_id' => (int) FrameReview::query()->max('id') + 1_000])->save();

    expect(FrameProbes::publishedHashMismatch()->pluck('frame.id')->sort()->values()->all())
        ->toBe(collect([$rehashed->id, $borrowed->id, $ghost->id])->sort()->values()->all());
});

it('aucune frame ready hors 1280 × 720 ou hors padding', function (): void {
    expect($this->processed->processing_state->value)->toBe('ready');
    expect(FrameProbes::readyOutOfFormat()->count())->toBe(0);

    // Et sur le disque : chaque frame prête sert exactement `game_bytes`
    // octets, un WebP de 1280 × 720 déjà paddé.
    $disk = Storage::disk(FrameStoragePrefix::DISK);
    $ready = Frame::query()->where('processing_state', 'ready')->get();

    expect($ready)->not->toBeEmpty();

    foreach ($ready as $frame) {
        $bytes = (string) $disk->get((string) $frame->game_path);
        $size = getimagesizefromstring($bytes);

        expect(strlen($bytes))->toBe($frame->game_bytes);
        expect(WebpPadding::pad($bytes))->toBe($bytes);
        expect([$size[0] ?? null, $size[1] ?? null])->toBe([FrameGeometry::GAME_WIDTH, FrameGeometry::GAME_HEIGHT]);
    }

    $unpadded = Frame::factory()->withFiles()->create();
    $unpadded->forceFill(['game_bytes' => FrameGeometry::GAME_PAD_BYTES + 1])->save();

    $oversized = Frame::factory()->withFiles()->create();
    $oversized->forceFill(['game_width' => FrameGeometry::MASTER_WIDTH, 'game_height' => 1080])->save();

    $heavy = Frame::factory()->withFiles()->create();
    $heavy->forceFill(['game_bytes' => FrameGeometry::GAME_MAX_BYTES + FrameGeometry::GAME_PAD_BYTES])->save();

    expect(FrameProbes::readyOutOfFormat()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$unpadded->id, $oversized->id, $heavy->id])->sort()->values()->all());
});

it('la requête d\'audit du plancher ne renvoie aucune frame', function (): void {
    $limits = PlatformLimits::current();

    expect(FrameProbes::cropFloorViolations($limits)->count())->toBe(0);

    // Une frame qui reprend le visuel presque entier, une hors 16:9.
    $wholeCrop = ['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1920, 'crop_height' => 1080];
    $whole = Frame::factory()->withFiles()->create($wholeCrop);
    $skewed = Frame::factory()->withFiles()->create(['crop_width' => 1280, 'crop_height' => 700]);

    // Hors d'atteinte de tout geste, donc hors de l'audit : une frame retirée
    // (re-recadrage `locked`) et une frame en échec au premier traitement, sans
    // dérivé (re-recadrage `not_ready`), qui n'entrera en jeu qu'après un
    // traitement réussi, lequel revalide le plancher.
    $withdrawn = Frame::factory()->withFiles()->withdrawn()->create($wholeCrop);
    $failed = Frame::factory()->processingFailed()->create($wholeCrop);

    $listed = FrameProbes::cropFloorViolations($limits)->pluck('id');

    expect($listed->sort()->values()->all())->toBe(collect([$whole->id, $skewed->id])->sort()->values()->all());
    expect($listed->all())->not->toContain($withdrawn->id, $failed->id);

    // Le plancher suit la configuration : durci, il rend non conformes les
    // cadres déjà enregistrés, que l'audit liste pour être recadrés — toutes
    // les frames qui ont un dérivé et ne sont pas retirées, et elles seules.
    platformLimitsConfigure(['frame_crop_max_width_percent' => PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT]);

    $correctable = Frame::query()->get()
        ->filter(static fn (Frame $frame): bool => $frame->game_path !== null
            && $frame->availability !== ContentAvailability::Withdrawn)
        ->pluck('id');

    expect($correctable)->not->toBeEmpty();
    expect(FrameProbes::cropFloorViolations(PlatformLimits::current())->pluck('id')->sort()->values()->all())
        ->toBe($correctable->sort()->values()->all());
});
