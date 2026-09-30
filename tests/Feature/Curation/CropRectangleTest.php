<?php

use App\Settings\PlatformLimits;
use App\Support\Frames\CropRect;
use App\Support\Frames\CropViolation;
use App\Support\Frames\FrameGeometry;
use Tests\Support\Frames\CropCases;

/*
|--------------------------------------------------------------------------
| Plancher de recadrage à double borne — contrat C9 (spec 20 § 5.2, D6 du 23/09)
|--------------------------------------------------------------------------
|
| Un cadre admis est en 16:9 exact, tient dans le master, n'est pas plus
| étroit que `frameCropMinWidthPx`, et ne dépasse `frameCropMaxWidthPercent`
| ni de la largeur ni de la hauteur du master : la surface couverte reste sous
| `pct² ÷ 100` %, quel que soit le ratio de la source.
|
| Les cas aux bornes viennent du jeu partagé `tests/Fixtures/Frames/crop-cases.json`,
| que Vitest rejoue contre le miroir client : chaque test ci-dessous vérifie
| les cas de sa règle, et c'est ce qui rend le miroir comparable. La garde de
| bornes de `PlatformLimits` elle-même est prouvée par `PlatformLimitsTest`
| (spec 50, R-04).
|
*/

/**
 * `PlatformLimits` aux bornes de plancher d'un cas, construite par la
 * configuration comme en production.
 *
 * @param  array{frameCropMaxWidthPercent: int, frameCropMinWidthPx: int}  $limits
 */
function cropRectangleLimits(array $limits): PlatformLimits
{
    platformLimitsConfigure([
        'frame_crop_max_width_percent' => $limits['frameCropMaxWidthPercent'],
        'frame_crop_min_width_px' => $limits['frameCropMinWidthPx'],
    ]);

    return PlatformLimits::current();
}

/**
 * Chaque cas d'une règle du jeu partagé rend son verdict écrit à la main.
 */
function cropRectangleAssertRule(string $rule): void
{
    foreach (CropCases::violations($rule) as $label => $case) {
        $violation = FrameGeometry::violation($case['crop'], $case['masterHeight'], cropRectangleLimits($case['limits']));

        expect($violation)->toBe($case['expected'], $label);
    }
}

/**
 * Un cadre 16:9 de largeur donnée, centré sur le master.
 */
function cropRectangleCentered(int $width, int $masterHeight): CropRect
{
    $height = intdiv($width * FrameGeometry::ASPECT_HEIGHT, FrameGeometry::ASPECT_WIDTH);

    return new CropRect(
        x: intdiv(FrameGeometry::MASTER_WIDTH - $width, 2),
        y: intdiv($masterHeight - $height, 2),
        width: $width,
        height: $height,
    );
}

it('un cadre hors 16:9 exact est refusé', function (): void {
    cropRectangleAssertRule('aspect');

    $limits = PlatformLimits::current();
    $default = FrameGeometry::defaultCrop(1080, $limits);

    // Un pixel de hauteur ou de largeur suffit.
    foreach ([
        new CropRect($default->x, $default->y, $default->width, $default->height + 1),
        new CropRect($default->x, $default->y, $default->width, $default->height - 1),
        new CropRect($default->x, $default->y, $default->width - 1, $default->height),
    ] as $crop) {
        expect(FrameGeometry::violation($crop, 1080, $limits))->toBe(CropViolation::Aspect);
    }

    expect(FrameGeometry::violation($default, 1080, $limits))->toBeNull();
});

it('un cadre plus large que le plancher est refusé', function (): void {
    cropRectangleAssertRule('width_cap');

    // Le backdrop brut redimensionné n'est jamais publiable (D6 du 23/09),
    // quel que soit le calibrage admis par la garde de `PlatformLimits`.
    for ($percent = PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT; $percent <= PlatformLimits::MAX_FRAME_CROP_MAX_WIDTH_PERCENT; $percent++) {
        $limits = cropRectangleLimits([
            'frameCropMaxWidthPercent' => $percent,
            'frameCropMinWidthPx' => PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX,
        ]);

        expect(FrameGeometry::violation(new CropRect(0, 0, FrameGeometry::MASTER_WIDTH, 1080), 1080, $limits))
            ->toBe(CropViolation::TooWide, "{$percent} %");
    }
});

it('un cadre plus étroit que la largeur minimale est refusé', function (): void {
    cropRectangleAssertRule('min_width');

    $limits = PlatformLimits::current();
    $minimum = $limits->frameCropMinWidthPx;

    expect(FrameGeometry::violation(cropRectangleCentered($minimum, 1080), 1080, $limits))->toBeNull();
    expect(FrameGeometry::violation(cropRectangleCentered($minimum - FrameGeometry::ASPECT_WIDTH, 1080), 1080, $limits))
        ->toBe(CropViolation::TooNarrow);
});

it('un cadre qui déborde du master est refusé', function (): void {
    cropRectangleAssertRule('bounds');

    $limits = PlatformLimits::current();
    $default = FrameGeometry::defaultCrop(1080, $limits);
    $right = FrameGeometry::MASTER_WIDTH - $default->width;
    $bottom = 1080 - $default->height;

    // Les quatre bords, à un pixel près de chaque côté.
    foreach ([
        [new CropRect(0, $default->y, $default->width, $default->height), null],
        [new CropRect(-1, $default->y, $default->width, $default->height), CropViolation::OutOfBounds],
        [new CropRect($right, $default->y, $default->width, $default->height), null],
        [new CropRect($right + 1, $default->y, $default->width, $default->height), CropViolation::OutOfBounds],
        [new CropRect($default->x, 0, $default->width, $default->height), null],
        [new CropRect($default->x, -1, $default->width, $default->height), CropViolation::OutOfBounds],
        [new CropRect($default->x, $bottom, $default->width, $default->height), null],
        [new CropRect($default->x, $bottom + 1, $default->width, $default->height), CropViolation::OutOfBounds],
    ] as [$crop, $expected]) {
        expect(FrameGeometry::violation($crop, 1080, $limits))->toBe($expected, json_encode($crop->toArray(), JSON_THROW_ON_ERROR));
    }
});

it('le cadre par défaut est le plus grand cadre admis, centré', function (): void {
    foreach (CropCases::defaults() as $label => $case) {
        $limits = cropRectangleLimits($case['limits']);

        if ($case['expected'] === null) {
            // Aucun cadre admis : la source est refusée en `source_aspect`.
            expect(FrameGeometry::maxCropWidth($case['masterHeight'], $limits))->toBeLessThan($limits->frameCropMinWidthPx, $label);
            expect(fn (): CropRect => FrameGeometry::defaultCrop($case['masterHeight'], $limits))
                ->toThrow(InvalidArgumentException::class);

            continue;
        }

        $default = FrameGeometry::defaultCrop($case['masterHeight'], $limits);

        expect($default->toArray())->toBe($case['expected']->toArray(), $label);
        expect(FrameGeometry::violation($default, $case['masterHeight'], $limits))->toBeNull($label);

        // Le plus grand : un pas de plus, centré, est refusé.
        $larger = cropRectangleCentered($default->width + FrameGeometry::ASPECT_WIDTH, $case['masterHeight']);

        expect(FrameGeometry::violation($larger, $case['masterHeight'], $limits))->toBe(CropViolation::TooWide, $label);

        // Centré : marges égales, à un demi-pixel près en hauteur.
        expect(2 * $default->x + $default->width)->toBe(FrameGeometry::MASTER_WIDTH, $label);
        expect(abs($case['masterHeight'] - $default->height - 2 * $default->y))->toBeLessThanOrEqual(1, $label);
    }

    // Au défaut de la plateforme, sur un backdrop 16:9 : 1536 × 864 (spec 20 § 5.2).
    platformLimitsConfigure([
        'frame_crop_max_width_percent' => PlatformLimits::DEFAULT_FRAME_CROP_MAX_WIDTH_PERCENT,
        'frame_crop_min_width_px' => PlatformLimits::DEFAULT_FRAME_CROP_MIN_WIDTH_PX,
    ]);

    expect(FrameGeometry::defaultCrop(1080, PlatformLimits::current())->toArray())
        ->toBe(['x' => 192, 'y' => 108, 'width' => 1536, 'height' => 864]);
});

it('le plancher est lu dans PlatformLimits et suit la configuration', function (): void {
    $default = PlatformLimits::current();

    expect(FrameGeometry::maxCropWidth(1080, $default))->toBe(1536);

    platformLimitsConfigure(['frame_crop_max_width_percent' => 70, 'frame_crop_min_width_px' => 800]);
    $tightened = PlatformLimits::current();

    expect(FrameGeometry::maxCropWidth(1080, $tightened))->toBe(1344);
    expect(FrameGeometry::defaultCrop(1080, $tightened)->width)->toBe(1344);
    expect(FrameGeometry::violation(cropRectangleCentered(1536, 1080), 1080, $tightened))->toBe(CropViolation::TooWide);
    expect(FrameGeometry::violation(cropRectangleCentered(784, 1080), 1080, $tightened))->toBe(CropViolation::TooNarrow);
    expect(FrameGeometry::violation(cropRectangleCentered(800, 1080), 1080, $tightened))->toBeNull();

    // Plafond du calibrage au lot pilote (83, D6 du 23/09).
    platformLimitsConfigure(['frame_crop_max_width_percent' => 83, 'frame_crop_min_width_px' => 640]);

    expect(FrameGeometry::maxCropWidth(1080, PlatformLimits::current()))->toBe(1584);

    // Aucune lecture de configuration hors de `PlatformLimits` : `FrameGeometry`
    // reçoit l'instance, jamais une clé.
    $source = (string) file_get_contents(app_path('Support/Frames/FrameGeometry.php'));

    expect($source)->not->toContain('config(');
    expect($source)->not->toContain('Config::');
    expect($source)->not->toContain('env(');
});

it('sur un master plus large que 16:9, un cadre plus haut que la fraction admise de la hauteur est refusé', function (): void {
    cropRectangleAssertRule('height_cap');

    // 2,39:1 au défaut : la borne de largeur seule admettrait 1536 de large,
    // la borne de hauteur ramène le plus grand cadre à 1136 × 639.
    $limits = PlatformLimits::current();
    $masterHeight = FrameGeometry::masterHeightFor(FrameGeometry::MASTER_WIDTH, 803);

    expect(FrameGeometry::maxCropWidth($masterHeight, $limits))->toBe(1136);

    $tooTall = cropRectangleCentered(1152, $masterHeight);

    expect($tooTall->width * PlatformLimits::FULL_PERCENT)
        ->toBeLessThanOrEqual($limits->frameCropMaxWidthPercent * FrameGeometry::MASTER_WIDTH);
    expect($tooTall->y + $tooTall->height)->toBeLessThanOrEqual($masterHeight);
    expect(FrameGeometry::violation($tooTall, $masterHeight, $limits))->toBe(CropViolation::TooWide);
});

it('la surface couverte ne dépasse jamais le carré de la fraction configurée, quel que soit le ratio du master', function (): void {
    $breaches = [];

    for ($percent = PlatformLimits::MIN_FRAME_CROP_MAX_WIDTH_PERCENT; $percent <= PlatformLimits::MAX_FRAME_CROP_MAX_WIDTH_PERCENT; $percent++) {
        $limits = cropRectangleLimits([
            'frameCropMaxWidthPercent' => $percent,
            'frameCropMinWidthPx' => PlatformLimits::MIN_FRAME_CROP_MIN_WIDTH_PX,
        ]);

        // Toute hauteur de master d'une source en paysage, du panorama au carré.
        for ($masterHeight = 1; $masterHeight <= FrameGeometry::MASTER_WIDTH; $masterHeight++) {
            $width = FrameGeometry::maxCropWidth($masterHeight, $limits);

            if ($width < $limits->frameCropMinWidthPx) {
                continue;
            }

            $largest = FrameGeometry::defaultCrop($masterHeight, $limits);

            // Surface ≤ (pct ÷ 100)² × surface du master, en entiers.
            $covered = $largest->width * $largest->height * PlatformLimits::FULL_PERCENT ** 2;
            $allowed = $percent ** 2 * FrameGeometry::MASTER_WIDTH * $masterHeight;

            if ($covered > $allowed
                || FrameGeometry::violation($largest, $masterHeight, $limits) !== null
                || FrameGeometry::violation(cropRectangleCentered($largest->width + FrameGeometry::ASPECT_WIDTH, $masterHeight), $masterHeight, $limits) === null) {
                $breaches[] = "{$percent} % sur 1920 × {$masterHeight}";
            }
        }
    }

    expect($breaches)->toBe([]);
});
