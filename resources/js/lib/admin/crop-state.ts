/**
 * État pur du recadreur (spec 20 § 6.3 et § 6.4, lot L20-9a).
 *
 * Tout ce que le recadreur sait faire du cadre passe par ici : l'ouvrir sur
 * un visuel, le déplacer, l'élargir ou le resserrer, le centrer, le rendre à
 * son défaut, traduire une touche en geste, mesurer le temps de recadrage.
 * Aucune fonction ne lit le DOM ni l'horloge : les instants arrivent en
 * paramètre, pour que Vitest les prouve sans environnement DOM (C18 § 2.4) et
 * que le composant ne fasse que brancher ces fonctions sur ses événements.
 *
 * Le rectangle vit dans l'**espace du master** (1920 de large, hauteur
 * `masterHeightFor()` de la source) : c'est lui que le serveur revalide
 * (§ 5.2). Chaque geste le garde dans le plancher de D6 du 23/09 — largeur
 * entre `frameCropMinWidthPx` et `maxCropWidth()`, multiple de 16, 16:9 exact —
 * et à l'intérieur du master. Le miroir `frame-geometry.ts` porte les
 * formules ; ce module n'en réécrit aucune.
 *
 * Un cadre initial hors plancher (re-recadrage d'une image publiée avant un
 * durcissement du plancher, § 5.2) est gardé tel quel à l'ouverture, pour que
 * sa violation s'affiche ; le premier geste de taille le ramène dans les
 * bornes, le premier geste de position dans le master.
 */

import {
    cropViolation,
    defaultCrop,
    FRAME_GEOMETRY,
    maxCropWidth,
} from '@/lib/frame-geometry';
import type { CropLimits, CropRect, CropViolation } from '@/lib/frame-geometry';

/**
 * Un pas du cadre, en pixels du master : l'unité du ratio,
 * `FrameGeometry::ASPECT_WIDTH` (§ 6.4). Un pas de largeur ajoute 16 pixels
 * de large et 9 de haut ; un pas de déplacement décale de 16 pixels.
 */
export const CROP_STEP_PX = FRAME_GEOMETRY.aspectWidth;

/** Nombre de pas d'un geste fait avec Maj (§ 6.4). */
export const CROP_FAST_STEPS = 4;

/** Millisecondes par seconde : `crop_seconds` se poste en secondes entières. */
const MS_PER_SECOND = 1000;

/**
 * Le cadre d'un visuel ouvert dans le recadreur, et de quoi le juger.
 *
 * `openedAtMs` est l'instant, sur une horloge monotone
 * (`performance.now()`), où le visuel s'est ouvert dans le cadre : le temps de
 * recadrage court de là jusqu'à l'envoi (§ 6.3, § 10.1). Aucun geste ne le
 * touche ; seule l'ouverture d'un autre visuel le remet à zéro.
 */
export type CropState = {
    crop: CropRect;
    masterHeight: number;
    limits: CropLimits;
    openedAtMs: number;
};

/**
 * Les gestes du recadreur. Les pas (`move`, `resize`) sont signés et se
 * comptent en `CROP_STEP_PX` ; `moveTo` place le coin haut-gauche en pixels
 * du master (glisser l'intérieur du cadre).
 */
export type CropCommand =
    | { kind: 'move'; dx: number; dy: number }
    | { kind: 'moveTo'; x: number; y: number }
    | { kind: 'resize'; steps: number }
    | { kind: 'center' }
    | { kind: 'reset' };

/**
 * Ce que le recadreur lit d'un événement clavier. Structurellement compatible
 * avec un `KeyboardEvent` de React comme du DOM, et constructible à la main
 * dans un test.
 */
export type CropKeyInput = {
    key: string;
    /** Position physique de la touche (`KeyboardEvent.code`). */
    code?: string;
    shiftKey: boolean;
    altKey?: boolean;
    ctrlKey?: boolean;
    metaKey?: boolean;
};

/** Bornes de la largeur d'un cadre sur ce master : le plancher de D6. */
export type CropWidthBounds = {
    min: number;
    max: number;
};

function clamp(value: number, low: number, high: number): number {
    return Math.min(Math.max(value, low), high);
}

/** Hauteur 16:9 exacte d'une largeur multiple du pas. */
function heightFor(width: number): number {
    return (width / FRAME_GEOMETRY.aspectWidth) * FRAME_GEOMETRY.aspectHeight;
}

/**
 * Ouvre un visuel dans le cadre : le cadre par défaut (le plus grand admis,
 * centré), ou le cadre `initial` d'une image qu'on re-recadre. `null` quand
 * aucun cadre n'est admis sur ce master — la source serait refusée en
 * `source_aspect`, et l'éditeur affiche ce refus au lieu du recadreur.
 */
export function openCrop(
    masterHeight: number,
    limits: CropLimits,
    openedAtMs: number,
    initial: CropRect | null = null,
): CropState | null {
    const fallback = defaultCrop(masterHeight, limits);

    if (fallback === null) {
        return null;
    }

    return {
        crop: initial === null ? fallback : { ...initial },
        masterHeight,
        limits,
        openedAtMs,
    };
}

/** Largeur la plus serrée et la plus large admises sur ce master. */
export function cropWidthBounds(state: CropState): CropWidthBounds {
    return {
        min: state.limits.frameCropMinWidthPx,
        max: maxCropWidth(state.masterHeight, state.limits),
    };
}

/** Vrai si « Plus large » change encore le cadre. */
export function canWiden(state: CropState): boolean {
    return state.crop.width < cropWidthBounds(state).max;
}

/** Vrai si « Plus serré » change encore le cadre. */
export function canNarrow(state: CropState): boolean {
    return state.crop.width > cropWidthBounds(state).min;
}

/** Cause du refus du cadre courant, ou `null` s'il est admis. */
export function cropStateViolation(state: CropState): CropViolation | null {
    return cropViolation(state.crop, state.masterHeight, state.limits);
}

/**
 * Part de la surface du master couverte par le cadre, en pour cent (non
 * arrondie). Au plus le carré de la fraction configurée, quel que soit le
 * ratio de la source (§ 5.2).
 */
export function cropSurfacePercent(state: CropState): number {
    const master = FRAME_GEOMETRY.masterWidth * state.masterHeight;

    if (master <= 0) {
        return 0;
    }

    return (state.crop.width * state.crop.height * 100) / master;
}

/**
 * Place le coin haut-gauche au plus près de `(x, y)` sans que le cadre sorte
 * du master. Les coordonnées sont des pixels entiers du master.
 */
function place(
    state: CropState,
    crop: CropRect,
    x: number,
    y: number,
): CropRect {
    return {
        ...crop,
        x: clamp(
            Math.round(x),
            0,
            Math.max(0, FRAME_GEOMETRY.masterWidth - crop.width),
        ),
        y: clamp(
            Math.round(y),
            0,
            Math.max(0, state.masterHeight - crop.height),
        ),
    };
}

/**
 * Élargit (`steps > 0`) ou resserre (`steps < 0`) le cadre autour de son
 * centre, dans les bornes du plancher. La largeur reste un multiple du pas,
 * la hauteur en découle exactement : 16:9 garanti. Le décalage vertical d'un
 * demi-pixel est tronqué vers zéro, si bien qu'élargir puis resserrer d'autant
 * rend le cadre de départ, loin des bords.
 */
function resize(state: CropState, steps: number): CropRect {
    const { crop } = state;
    const bounds = cropWidthBounds(state);
    const snapped = Math.round(crop.width / CROP_STEP_PX) * CROP_STEP_PX;
    const width = clamp(snapped + steps * CROP_STEP_PX, bounds.min, bounds.max);
    const height = heightFor(width);

    return place(
        state,
        { x: crop.x, y: crop.y, width, height },
        crop.x + Math.trunc((crop.width - width) / 2),
        crop.y + Math.trunc((crop.height - height) / 2),
    );
}

function sameRect(left: CropRect, right: CropRect): boolean {
    return (
        left.x === right.x &&
        left.y === right.y &&
        left.width === right.width &&
        left.height === right.height
    );
}

/** Le cadre après un geste, calculé sans rien muter. */
function nextCrop(state: CropState, command: CropCommand): CropRect {
    const { crop } = state;

    switch (command.kind) {
        case 'move':
            return place(
                state,
                crop,
                crop.x + command.dx * CROP_STEP_PX,
                crop.y + command.dy * CROP_STEP_PX,
            );
        case 'moveTo':
            return place(state, crop, command.x, command.y);
        case 'resize':
            return resize(state, command.steps);
        case 'center':
            return place(
                state,
                crop,
                Math.trunc((FRAME_GEOMETRY.masterWidth - crop.width) / 2),
                Math.trunc((state.masterHeight - crop.height) / 2),
            );
        case 'reset':
            return defaultCrop(state.masterHeight, state.limits) ?? crop;
    }
}

/**
 * Applique un geste. Rend l'état **inchangé** (même référence) quand le geste
 * ne déplace rien — un cadre déjà au bord, déjà au plus large : React n'a
 * alors rien à re-rendre.
 */
export function applyCropCommand(
    state: CropState,
    command: CropCommand,
): CropState {
    const crop = nextCrop(state, command);

    return sameRect(crop, state.crop) ? state : { ...state, crop };
}

/**
 * La touche d'un cadre focalisé, traduite en geste (§ 6.4), ou `null` si elle
 * n'en est pas un — elle suit alors son cours normal (Tab, Échap…).
 *
 * - flèches : déplacer d'un pas ; avec Maj, de `CROP_FAST_STEPS` pas ;
 * - `+` (ou `=`, sa touche non majusculée sur les claviers français et
 *   américain) : élargir d'un pas ; `-` (ou `_`) : resserrer ; avec Maj, de
 *   `CROP_FAST_STEPS` pas ;
 * - Origine : cadre par défaut.
 *
 * Sur un clavier AZERTY français, `-` est la touche du 6 de la rangée
 * principale : avec Maj, elle rend `6`. Maj + `6` venu de cette touche
 * (`code` = `Digit6`) resserre donc de `CROP_FAST_STEPS` pas, pour que
 * « Maj + `-` » tienne sa promesse sans pavé numérique. Aucune disposition
 * où Maj + `Digit6` rend `6` n'y porte un autre geste du recadreur.
 *
 * Une touche combinée à Ctrl, Alt ou Méta n'est jamais un geste : Ctrl + `+`
 * reste le zoom du navigateur.
 */
export function cropKeyCommand(input: CropKeyInput): CropCommand | null {
    if (
        input.altKey === true ||
        input.ctrlKey === true ||
        input.metaKey === true
    ) {
        return null;
    }

    if (input.shiftKey && input.key === '6' && input.code === 'Digit6') {
        return { kind: 'resize', steps: -CROP_FAST_STEPS };
    }

    const steps = input.shiftKey ? CROP_FAST_STEPS : 1;

    switch (input.key) {
        case 'ArrowLeft':
            return { kind: 'move', dx: -steps, dy: 0 };
        case 'ArrowRight':
            return { kind: 'move', dx: steps, dy: 0 };
        case 'ArrowUp':
            return { kind: 'move', dx: 0, dy: -steps };
        case 'ArrowDown':
            return { kind: 'move', dx: 0, dy: steps };
        case '+':
        case '=':
            return { kind: 'resize', steps };
        case '-':
        case '_':
            return { kind: 'resize', steps: -steps };
        case 'Home':
            return { kind: 'reset' };
        default:
            return null;
    }
}

/**
 * Temps de recadrage posté en `crop_seconds` : de l'ouverture du visuel dans
 * le cadre à l'envoi, en secondes entières, jamais négatif. Le plafond
 * (`catalog.curation.crop_seconds_max`) est posé par le serveur, seul à le
 * connaître (§ 13.7).
 */
export function cropSecondsAt(state: CropState, sentAtMs: number): number {
    return Math.max(
        0,
        Math.floor((sentAtMs - state.openedAtMs) / MS_PER_SECOND),
    );
}
