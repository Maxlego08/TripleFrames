/**
 * Miroir client, NON autoritaire, de `App\Support\Frames\FrameGeometry`
 * (contrat C9, spec 20 § 5.1 et § 5.2 ; D5 et D6 du 23/09).
 *
 * Il sert le retour immédiat du recadreur : cadre par défaut, bornes du
 * plancher, violation nommée avant tout envoi. Le serveur ne lui fait jamais
 * confiance : le contrôleur revalide le cadre sur la hauteur tirée des
 * métadonnées TMDB, puis le job sur le master réel.
 *
 * Mêmes formules, en arithmétique entière (`intdiv` de PHP, tronqué vers
 * zéro) : un cadre admis ici l'est là-bas, et réciproquement. Le jeu de cas
 * partagé `tests/Fixtures/Frames/crop-cases.json` le prouve des deux côtés
 * (`CropRectangleTest` en Pest, `frame-geometry.test.ts` en Vitest), et
 * `FrameGeometryTest` vérifie que `FRAME_GEOMETRY` reprend les constantes PHP.
 *
 * Deux écarts assumés, parce qu'un miroir d'affichage ne doit jamais lever :
 * `masterHeightFor()` rend 0 pour des dimensions non positives, et
 * `defaultCrop()` rend `null` quand aucun cadre n'est admis — là où la
 * version PHP lève une exception.
 */

/** Constantes de format, jamais surchargeables : miroir de `FrameGeometry`. */
export const FRAME_GEOMETRY = {
    aspectWidth: 16,
    aspectHeight: 9,
    gameWidth: 1280,
    gameHeight: 720,
    masterWidth: 1920,
} as const;

/** L'unité : 100 %, miroir de `PlatformLimits::FULL_PERCENT`. */
const FULL_PERCENT = 100;

/** Rectangle de recadrage, en pixels de l'espace du master. */
export type CropRect = {
    x: number;
    y: number;
    width: number;
    height: number;
};

/**
 * Causes de refus, dans l'ordre d'évaluation : miroir de l'enum PHP
 * `CropViolation`, nommées par `admin.validation.crop.*`.
 */
export const CROP_VIOLATIONS = [
    'aspect',
    'too_wide',
    'too_narrow',
    'out_of_bounds',
] as const;

export type CropViolation = (typeof CROP_VIOLATIONS)[number];

/**
 * Les deux bornes du plancher, telles que le back-office les reçoit de
 * `PlatformLimits` (prop `limits: AdminFrameLimits`, R-07).
 */
export type CropLimits = {
    frameCropMaxWidthPercent: number;
    frameCropMinWidthPx: number;
};

/** `intdiv` de PHP : quotient entier tronqué vers zéro. */
function intdiv(dividend: number, divisor: number): number {
    return Math.trunc(dividend / divisor);
}

/** Hauteur 16:9 d'une largeur multiple du pas. */
function heightFor(width: number): number {
    return intdiv(
        width * FRAME_GEOMETRY.aspectHeight,
        FRAME_GEOMETRY.aspectWidth,
    );
}

/**
 * Hauteur du master d'une source : ramenée à `masterWidth` de large, arrondie
 * au plus proche, demi vers le haut. 0 pour des dimensions non positives —
 * aucun cadre n'y est admis.
 */
export function masterHeightFor(
    sourceWidth: number,
    sourceHeight: number,
): number {
    if (sourceWidth < 1 || sourceHeight < 1) {
        return 0;
    }

    return intdiv(
        sourceHeight * FRAME_GEOMETRY.masterWidth + intdiv(sourceWidth, 2),
        sourceWidth,
    );
}

/**
 * Largeur du plus grand cadre admis sur ce master, multiple du pas : borne de
 * largeur et borne de hauteur du plancher, la plus stricte des deux.
 */
export function maxCropWidth(masterHeight: number, limits: CropLimits): number {
    const percent = limits.frameCropMaxWidthPercent;

    const stepsByWidth = intdiv(
        percent * FRAME_GEOMETRY.masterWidth,
        FULL_PERCENT * FRAME_GEOMETRY.aspectWidth,
    );
    const stepsByHeight = intdiv(
        percent * masterHeight,
        FULL_PERCENT * FRAME_GEOMETRY.aspectHeight,
    );

    return Math.min(stepsByWidth, stepsByHeight) * FRAME_GEOMETRY.aspectWidth;
}

/**
 * Cadre par défaut : le plus grand cadre admis, centré. `null` quand aucun
 * cadre n'est admis sur ce master (la source serait refusée en
 * `source_aspect`).
 */
export function defaultCrop(
    masterHeight: number,
    limits: CropLimits,
): CropRect | null {
    const width = maxCropWidth(masterHeight, limits);

    if (width < limits.frameCropMinWidthPx) {
        return null;
    }

    const height = heightFor(width);

    return {
        x: intdiv(FRAME_GEOMETRY.masterWidth - width, 2),
        y: intdiv(masterHeight - height, 2),
        width,
        height,
    };
}

/**
 * Cause du refus d'un cadre sur ce master, ou `null` s'il est admis. Ordre
 * d'évaluation : ratio, plancher (deux bornes), largeur minimale,
 * débordement.
 */
export function cropViolation(
    crop: CropRect,
    masterHeight: number,
    limits: CropLimits,
): CropViolation | null {
    if (
        crop.width % FRAME_GEOMETRY.aspectWidth !== 0 ||
        crop.height * FRAME_GEOMETRY.aspectWidth !==
            crop.width * FRAME_GEOMETRY.aspectHeight
    ) {
        return 'aspect';
    }

    const percent = limits.frameCropMaxWidthPercent;

    if (
        crop.width * FULL_PERCENT > percent * FRAME_GEOMETRY.masterWidth ||
        crop.height * FULL_PERCENT > percent * masterHeight
    ) {
        return 'too_wide';
    }

    if (crop.width < limits.frameCropMinWidthPx) {
        return 'too_narrow';
    }

    if (
        crop.x < 0 ||
        crop.y < 0 ||
        crop.x + crop.width > FRAME_GEOMETRY.masterWidth ||
        crop.y + crop.height > masterHeight
    ) {
        return 'out_of_bounds';
    }

    return null;
}
