/**
 * État pur du recadreur (spec 20 § 6.3 et § 6.4, lots L20-9a et L20-9b).
 *
 * Tout ce que le recadreur sait faire du cadre passe par ici : l'ouvrir sur
 * un visuel, le déplacer, l'élargir ou le resserrer, le centrer, le rendre à
 * son défaut, traduire une touche en geste, mesurer le temps de recadrage —
 * et, depuis L20-9b, le redimensionner par une poignée d'angle, par la
 * molette ou par le pincement. Aucune fonction ne lit le DOM ni l'horloge :
 * les instants et les positions du pointeur arrivent en paramètre, pour que
 * Vitest les prouve sans environnement DOM (C18 § 2.4) et que le composant ne
 * fasse que brancher ces fonctions sur ses événements.
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
 * Un cran de molette, tel que les navigateurs le rapportent : 100 pixels en
 * mode pixel (Chrome, Edge, Safari), 3 lignes en mode ligne (Firefox).
 *
 * C'est aussi l'échelle du pincement d'un pavé tactile, que Chromium rapporte
 * comme une molette avec Ctrl de `deltaY = −100 × ln(s)` pour un pincement
 * d'échelle `s` : une largeur multipliée par `exp(−deltaY / 100)` suit donc
 * exactement l'écartement des doigts.
 */
const WHEEL_NOTCH_PIXELS = 100;
const WHEEL_NOTCH_LINES = 3;

/**
 * Plus grand `deltaY`, en pixels, qu'un seul événement molette peut peser.
 * Un cran de souris arrive d'un bloc (100 pixels, 3 lignes, une page) : il ne
 * change la largeur que d'un facteur `exp(±0,1)`, environ 10 %, et il faut
 * une dizaine de crans pour traverser le plancher. Un pincement de pavé
 * tactile arrive découpé en nombreux petits événements, sous ce plafond : il
 * suit les doigts.
 */
const WHEEL_EVENT_MAX_PIXELS = 10;

/** `WheelEvent.deltaMode` : unités de `deltaY` (DOM Level 3 Events). */
const WHEEL_DELTA_LINE = 1;
const WHEEL_DELTA_PAGE = 2;

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
 * Les quatre coins du cadre, où se saisissent ses poignées (L20-9b) : nord-
 * ouest, nord-est, sud-ouest, sud-est.
 */
export const CROP_CORNERS = ['nw', 'ne', 'sw', 'se'] as const;

export type CropCorner = (typeof CROP_CORNERS)[number];

/** Un point de l'espace du master, en pixels (éventuellement fractionnaires). */
export type CropPoint = {
    x: number;
    y: number;
};

/**
 * Les gestes du recadreur. Les pas (`move`, `resize`) sont signés et se
 * comptent en `CROP_STEP_PX` ; `moveTo` place le coin haut-gauche en pixels
 * du master (glisser l'intérieur du cadre).
 *
 * Gestes de pointeur avancés (L20-9b) : `resizeCorner` amène le coin
 * `corner` au plus près du point `(x, y)` du master, le coin opposé restant
 * fixe (poignée d'angle) ; `resizeTo` vise une largeur autour du centre du
 * cadre (pincement). La molette, elle, se traduit en `resize` par
 * `cropWheelStep()`.
 */
export type CropCommand =
    | { kind: 'move'; dx: number; dy: number }
    | { kind: 'moveTo'; x: number; y: number }
    | { kind: 'resize'; steps: number }
    | { kind: 'resizeTo'; width: number }
    | { kind: 'resizeCorner'; corner: CropCorner; x: number; y: number }
    | { kind: 'center' }
    | { kind: 'reset' };

/**
 * Ce que le recadreur lit d'un événement molette. Structurellement compatible
 * avec un `WheelEvent` du DOM, et constructible à la main dans un test.
 */
export type CropWheelInput = {
    deltaY: number;
    deltaMode: number;
};

/**
 * Un cran de molette traduit en pas : `steps` entiers à appliquer tout de
 * suite (positifs = élargir), `carry` la fraction de pas retenue pour
 * l'événement suivant.
 */
export type CropWheelStep = {
    steps: number;
    carry: number;
};

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

/** Largeur arrondie au multiple du pas le plus proche. */
function snapWidth(width: number): number {
    return Math.round(width / CROP_STEP_PX) * CROP_STEP_PX;
}

/**
 * Donne au cadre la largeur admise la plus proche de `width`, autour de son
 * centre, dans les bornes du plancher. La largeur est arrondie au multiple du
 * pas, la hauteur en découle exactement : 16:9 garanti. Le décalage vertical
 * d'un demi-pixel est tronqué vers zéro, si bien qu'élargir puis resserrer
 * d'autant rend le cadre de départ, loin des bords.
 */
function resizeAround(state: CropState, width: number): CropRect {
    const { crop } = state;
    const bounds = cropWidthBounds(state);
    const next = clamp(snapWidth(width), bounds.min, bounds.max);
    const height = heightFor(next);

    return place(
        state,
        { x: crop.x, y: crop.y, width: next, height },
        crop.x + Math.trunc((crop.width - next) / 2),
        crop.y + Math.trunc((crop.height - height) / 2),
    );
}

/**
 * Élargit (`steps > 0`) ou resserre (`steps < 0`) le cadre d'autant de pas,
 * autour de son centre (boutons, clavier, molette).
 */
function resize(state: CropState, steps: number): CropRect {
    return resizeAround(
        state,
        snapWidth(state.crop.width) + steps * CROP_STEP_PX,
    );
}

/** Vrai si la poignée `corner` est sur le bord gauche, sur le bord haut. */
function cornerSides(corner: CropCorner): { left: boolean; top: boolean } {
    return {
        left: corner === 'nw' || corner === 'sw',
        top: corner === 'nw' || corner === 'ne',
    };
}

/** Le coin diagonalement opposé : celui qui reste fixe sous la poignée. */
function oppositeCorner(corner: CropCorner): CropCorner {
    switch (corner) {
        case 'nw':
            return 'se';
        case 'ne':
            return 'sw';
        case 'sw':
            return 'ne';
        case 'se':
            return 'nw';
    }
}

/**
 * Position d'un coin du cadre dans le master. Le composant s'en sert pour
 * retenir, au moment où une poignée est saisie, l'écart entre le pointeur et
 * le coin : le coin ne saute pas sous le pointeur.
 */
export function cropCornerPoint(crop: CropRect, corner: CropCorner): CropPoint {
    const { left, top } = cornerSides(corner);

    return {
        x: left ? crop.x : crop.x + crop.width,
        y: top ? crop.y : crop.y + crop.height,
    };
}

/**
 * Redimensionne le cadre par sa poignée `corner`, amenée au point `(x, y)`
 * du master ; le coin opposé reste fixe (L20-9b).
 *
 * - **Ratio verrouillé** : la largeur visée est la plus grande des deux
 *   étendues que le pointeur demande depuis le coin fixe — l'horizontale, et
 *   la verticale ramenée en largeur par le 16:9 —, si bien que le cadre
 *   rejoint toujours le pointeur ; la hauteur en découle exactement.
 * - **Largeur arrondie au multiple de 16** le plus proche.
 * - **Les deux bornes du plancher** : jamais plus serré que
 *   `frameCropMinWidthPx`, jamais plus large que `maxCropWidth()` — qui porte
 *   déjà la borne de largeur et la borne de hauteur de D6 du 23/09.
 * - **Dans le master** : jamais plus large que la place laissée entre le coin
 *   fixe et les bords, en pas entiers. Un pointeur passé au-delà du coin fixe
 *   rend le cadre le plus serré ; le cadre ne se retourne jamais.
 *
 * Seul un cadre initial hors plancher (re-recadrage après un durcissement,
 * § 5.2) peut manquer de place pour la largeur minimale depuis son coin
 * fixe : le cadre prend alors la largeur minimale et glisse dans le master,
 * comme au premier geste de taille des boutons.
 */
function resizeFromCorner(
    state: CropState,
    corner: CropCorner,
    x: number,
    y: number,
): CropRect {
    const { crop, masterHeight } = state;
    const { left, top } = cornerSides(corner);
    const anchor = cropCornerPoint(crop, oppositeCorner(corner));
    const { aspectWidth, aspectHeight, masterWidth } = FRAME_GEOMETRY;

    const reachX = left ? anchor.x - x : x - anchor.x;
    const reachY = top ? anchor.y - y : y - anchor.y;
    const wanted = Math.max(reachX, (reachY * aspectWidth) / aspectHeight);

    const roomX = left ? anchor.x : masterWidth - anchor.x;
    const roomY = top ? anchor.y : masterHeight - anchor.y;
    const roomSteps = Math.min(
        Math.floor(roomX / aspectWidth),
        Math.floor(roomY / aspectHeight),
    );

    const bounds = cropWidthBounds(state);
    const widest = Math.max(
        bounds.min,
        Math.min(bounds.max, roomSteps * CROP_STEP_PX),
    );
    const width = clamp(snapWidth(wanted), bounds.min, widest);
    const height = heightFor(width);

    return place(
        state,
        { x: crop.x, y: crop.y, width, height },
        left ? anchor.x - width : anchor.x,
        top ? anchor.y - height : anchor.y,
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
        case 'resizeTo':
            return resizeAround(state, command.width);
        case 'resizeCorner':
            return resizeFromCorner(
                state,
                command.corner,
                command.x,
                command.y,
            );
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

/** Le `deltaY` d'un événement molette, ramené en pixels quel que soit son mode. */
function wheelPixels(input: CropWheelInput): number {
    switch (input.deltaMode) {
        case WHEEL_DELTA_PAGE:
            return input.deltaY * WHEEL_NOTCH_PIXELS;
        case WHEEL_DELTA_LINE:
            return (input.deltaY / WHEEL_NOTCH_LINES) * WHEEL_NOTCH_PIXELS;
        default:
            return input.deltaY;
    }
}

/**
 * Ctrl + molette traduit en pas de taille (L20-9b), à partir de la largeur
 * `width` du cadre. Vers le haut — ou en écartant les doigts sur un pavé
 * tactile, que les navigateurs rapportent comme une molette avec Ctrl — le
 * cadre s'élargit ; vers le bas, il se resserre : le cadre suit le sens du
 * pincement tactile.
 *
 * Le geste est **multiplicatif**, comme le pincement tactile
 * (`cropPinchCommand`) : la largeur visée est multipliée par
 * `exp(−deltaY / 100)`, `deltaY` ramené en pixels et plafonné à
 * `WHEEL_EVENT_MAX_PIXELS` par événement. Un pincement de pavé tactile suit
 * ainsi l'écartement des doigts, et un cran de souris change la largeur
 * d'environ 10 %.
 *
 * Les fractions de pas s'accumulent dans `carry`, que l'appelant repasse à
 * l'événement suivant : la largeur visée, non arrondie, vaut
 * `width + carry × CROP_STEP_PX`, et c'est elle que l'événement multiplie.
 * Un changement de sens repart de la largeur du cadre : le reliquat d'un sens
 * ne retarde jamais l'autre. Les pas sont relatifs, comme ceux des boutons :
 * ils se composent avec tout autre geste. Rien n'est borné ici :
 * `applyCropCommand()` tient le plancher, et un cadre à la borne ne garde
 * jamais plus d'une fraction de pas en réserve.
 */
export function cropWheelStep(
    width: number,
    carry: number,
    input: CropWheelInput,
): CropWheelStep {
    const pixels = clamp(
        wheelPixels(input),
        -WHEEL_EVENT_MAX_PIXELS,
        WHEEL_EVENT_MAX_PIXELS,
    );
    const factor = Math.exp(-pixels / WHEEL_NOTCH_PIXELS);
    const kept = carry * (factor - 1) < 0 ? 0 : carry;
    const target = width + kept * CROP_STEP_PX;
    const total = kept + (target * (factor - 1)) / CROP_STEP_PX;
    // `|| 0` : jamais de zéro négatif, qui ferait échouer `Object.is`.
    const steps = Math.trunc(total) || 0;

    return { steps, carry: total - steps };
}

/**
 * Le pincement traduit en largeur visée (L20-9b) : la largeur du cadre au
 * début du pincement, multipliée par l'écartement des doigts rapporté à leur
 * écartement de départ. `null` quand l'écartement de départ est nul — deux
 * doigts posés au même point ne mesurent rien. Le cadre reste centré ;
 * `applyCropCommand()` arrondit au multiple de 16 et tient le plancher.
 */
export function cropPinchCommand(
    startWidth: number,
    startDistance: number,
    distance: number,
): CropCommand | null {
    if (!(startDistance > 0) || !(distance >= 0)) {
        return null;
    }

    return { kind: 'resizeTo', width: (startWidth * distance) / startDistance };
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
