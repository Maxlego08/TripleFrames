import { describe, expect, it } from 'vite-plus/test';
import {
    applyCropCommand,
    canNarrow,
    canWiden,
    CROP_CORNERS,
    CROP_FAST_STEPS,
    CROP_STEP_PX,
    cropCornerPoint,
    cropKeyCommand,
    cropPinchCommand,
    cropSecondsAt,
    cropStateViolation,
    cropWheelStep,
    cropWidthBounds,
    openCrop,
} from '@/lib/admin/crop-state';
import type {
    CropCommand,
    CropCorner,
    CropKeyInput,
    CropState,
} from '@/lib/admin/crop-state';
import {
    defaultCrop,
    FRAME_GEOMETRY,
    masterHeightFor,
    maxCropWidth,
} from '@/lib/frame-geometry';
import type { CropLimits, CropRect } from '@/lib/frame-geometry';

/*
 * État pur du recadreur (spec 20 § 6.3 et § 6.4, lots L20-9a et L20-9b).
 *
 * Le recadreur ne fait que brancher ces fonctions sur ses événements : ce que
 * font une flèche, `+`, `-`, Maj ou Origine, une poignée d'angle, la molette
 * ou le pincement, et ce que mesure `crop_seconds`, se prouve ici sans DOM
 * (C18 § 2.4). Les bornes du plancher sont celles du
 * réglage par défaut de `PlatformLimits` (D6 du 23/09) ; chaque cas les porte
 * lui-même, indépendamment de la configuration.
 */

const LIMITS: CropLimits = {
    frameCropMaxWidthPercent: 80,
    frameCropMinWidthPx: 640,
};

/** Masters de sources réelles : 16:9, scope 2,39:1, 2:1 et 4:3. */
const MASTERS = [
    { label: '16:9', height: masterHeightFor(3840, 2160) },
    { label: 'scope 2,39:1', height: masterHeightFor(2048, 858) },
    { label: '2:1', height: masterHeightFor(2000, 1000) },
    { label: '4:3', height: masterHeightFor(1600, 1200) },
];

/** Ouvre un visuel à l'instant `openedAtMs`, sans jamais rendre `null`. */
function open(
    masterHeight: number,
    openedAtMs = 0,
    initial: CropRect | null = null,
): CropState {
    const state = openCrop(masterHeight, LIMITS, openedAtMs, initial);

    if (state === null) {
        throw new Error(`aucun cadre admis sur un master de ${masterHeight}`);
    }

    return state;
}

/** Frappe une touche sur le cadre focalisé ; une touche inerte ne change rien. */
function press(
    state: CropState,
    key: string,
    modifiers: Partial<Omit<CropKeyInput, 'key'>> = {},
): CropState {
    const command = cropKeyCommand({ key, shiftKey: false, ...modifiers });

    return command === null ? state : applyCropCommand(state, command);
}

/** Frappe la même touche `times` fois. */
function pressTimes(
    state: CropState,
    key: string,
    times: number,
    modifiers: Partial<Omit<CropKeyInput, 'key'>> = {},
): CropState {
    let current = state;

    for (let index = 0; index < times; index += 1) {
        current = press(current, key, modifiers);
    }

    return current;
}

/** Le cadre respecte 16:9 exact, le pas de 16, le plancher et le master. */
function expectAdmitted(state: CropState): void {
    const { crop, masterHeight } = state;
    const bounds = cropWidthBounds(state);

    expect(crop.width % CROP_STEP_PX).toBe(0);
    expect(crop.height * FRAME_GEOMETRY.aspectWidth).toBe(
        crop.width * FRAME_GEOMETRY.aspectHeight,
    );
    expect(crop.width).toBeGreaterThanOrEqual(bounds.min);
    expect(crop.width).toBeLessThanOrEqual(bounds.max);
    expect(crop.x).toBeGreaterThanOrEqual(0);
    expect(crop.y).toBeGreaterThanOrEqual(0);
    expect(crop.x + crop.width).toBeLessThanOrEqual(FRAME_GEOMETRY.masterWidth);
    expect(crop.y + crop.height).toBeLessThanOrEqual(masterHeight);
    expect(cropStateViolation(state)).toBeNull();
}

/**
 * Générateur de Park et Miller, déterministe et exact en flottants : une
 * suite de gestes rejouable. La graine doit être non nulle.
 */
function sequence(seed: number, length: number): CropCommand[] {
    const commands: CropCommand[] = [];
    let value = seed;

    for (let index = 0; index < length; index += 1) {
        value = (value * 48271) % 2147483647;
        const pick = value % 7;
        const amount = (value % 9) - 4;

        if (pick === 0) {
            commands.push({ kind: 'move', dx: amount * 3, dy: 0 });
        } else if (pick === 1) {
            commands.push({ kind: 'move', dx: 0, dy: amount * 3 });
        } else if (pick === 2) {
            commands.push({ kind: 'resize', steps: amount * 2 });
        } else if (pick === 3) {
            commands.push({
                kind: 'moveTo',
                x: (value % 4000) - 1000,
                y: (value % 3000) - 1000,
            });
        } else if (pick === 4) {
            commands.push({ kind: 'center' });
        } else if (pick === 5) {
            commands.push({ kind: 'resize', steps: amount * 10 });
        } else {
            commands.push({ kind: 'move', dx: amount * 20, dy: -amount * 20 });
        }
    }

    return commands;
}

/** Le coin qui reste fixe sous chaque poignée. */
const OPPOSITE: Record<CropCorner, CropCorner> = {
    nw: 'se',
    ne: 'sw',
    sw: 'ne',
    se: 'nw',
};

/** Tire la poignée `corner` jusqu'au point `(x, y)` du master. */
function pull(
    state: CropState,
    corner: CropCorner,
    x: number,
    y: number,
): CropState {
    return applyCropCommand(state, { kind: 'resizeCorner', corner, x, y });
}

/**
 * Sens de la poignée vu du coin fixe : `+1` vers la droite ou le bas, `-1`
 * vers la gauche ou le haut.
 */
function outwards(corner: CropCorner): { sx: number; sy: number } {
    return {
        sx: corner === 'nw' || corner === 'sw' ? -1 : 1,
        sy: corner === 'nw' || corner === 'ne' ? -1 : 1,
    };
}

/**
 * Vrai si le cadre peut encore grandir d'un pas par la poignée `corner` sans
 * sortir du master ni dépasser la borne haute du plancher.
 */
function canGrowBy(state: CropState, corner: CropCorner): boolean {
    const { crop, masterHeight } = state;
    const { sx, sy } = outwards(corner);
    const roomX =
        sx < 0 ? crop.x : FRAME_GEOMETRY.masterWidth - crop.x - crop.width;
    const roomY = sy < 0 ? crop.y : masterHeight - crop.y - crop.height;

    return (
        crop.width < cropWidthBounds(state).max &&
        roomX >= FRAME_GEOMETRY.aspectWidth &&
        roomY >= FRAME_GEOMETRY.aspectHeight
    );
}

/**
 * Suite rejouable de poignées tirées vers des points du master et d'au-delà
 * (même générateur que `sequence`).
 */
function pulls(seed: number, length: number): CropCommand[] {
    const commands: CropCommand[] = [];
    let value = seed;

    for (let index = 0; index < length; index += 1) {
        value = (value * 48271) % 2147483647;

        commands.push({
            kind: 'resizeCorner',
            corner: CROP_CORNERS[value % CROP_CORNERS.length],
            x: (value % 3000) - 500 + (value % 7) / 7,
            y: (value % 2500) - 500 + (value % 5) / 5,
        });
    }

    return commands;
}

describe('crop-state', () => {
    it("les flèches déplacent le cadre d'un pas et Maj de quatre pas", () => {
        const start = open(masterHeightFor(3840, 2160));
        const { x, y, width, height } = start.crop;

        expect(CROP_STEP_PX).toBe(FRAME_GEOMETRY.aspectWidth);
        expect(CROP_FAST_STEPS).toBe(4);

        expect(press(start, 'ArrowRight').crop).toEqual({
            x: x + CROP_STEP_PX,
            y,
            width,
            height,
        });
        expect(press(start, 'ArrowLeft').crop.x).toBe(x - CROP_STEP_PX);
        expect(press(start, 'ArrowDown').crop.y).toBe(y + CROP_STEP_PX);
        expect(press(start, 'ArrowUp').crop.y).toBe(y - CROP_STEP_PX);

        const fast = { shiftKey: true };

        expect(press(start, 'ArrowRight', fast).crop).toEqual({
            x: x + CROP_FAST_STEPS * CROP_STEP_PX,
            y,
            width,
            height,
        });
        expect(press(start, 'ArrowLeft', fast).crop.x).toBe(
            x - CROP_FAST_STEPS * CROP_STEP_PX,
        );
        expect(press(start, 'ArrowDown', fast).crop.y).toBe(
            y + CROP_FAST_STEPS * CROP_STEP_PX,
        );
        expect(press(start, 'ArrowUp', fast).crop.y).toBe(
            y - CROP_FAST_STEPS * CROP_STEP_PX,
        );

        // Aller et retour : le cadre revient exactement à sa place.
        expect(press(press(start, 'ArrowRight'), 'ArrowLeft').crop).toEqual(
            start.crop,
        );

        // Ctrl, Alt ou Méta : jamais un geste (Ctrl + « + » reste le zoom du
        // navigateur) ; une touche étrangère non plus.
        for (const modifier of ['ctrlKey', 'altKey', 'metaKey'] as const) {
            expect(
                cropKeyCommand({
                    key: 'ArrowRight',
                    shiftKey: false,
                    [modifier]: true,
                }),
            ).toBeNull();
        }

        for (const key of ['Tab', 'Escape', 'Enter', ' ', 'a', '1', '[']) {
            expect(cropKeyCommand({ key, shiftKey: false })).toBeNull();
        }
    });

    it('élargir ou resserrer garde le 16:9 et un multiple de 16', () => {
        const start = open(masterHeightFor(3840, 2160));
        const { width, height } = start.crop;

        const narrower = press(start, '-');

        expect(narrower.crop.width).toBe(width - CROP_STEP_PX);
        expect(narrower.crop.height).toBe(height - FRAME_GEOMETRY.aspectHeight);
        expectAdmitted(narrower);

        const muchNarrower = press(start, '-', { shiftKey: true });

        expect(muchNarrower.crop.width).toBe(
            width - CROP_FAST_STEPS * CROP_STEP_PX,
        );
        expectAdmitted(muchNarrower);

        // `_` est la touche majusculée de `-` sur un clavier américain.
        expect(press(start, '_').crop).toEqual(narrower.crop);

        // Sur un clavier AZERTY français, `-` est la touche du 6 : avec Maj,
        // elle rend « 6 », et resserre alors de quatre pas.
        expect(
            press(start, '6', { shiftKey: true, code: 'Digit6' }).crop,
        ).toEqual(muchNarrower.crop);

        // Un « 6 » sans Maj, ou venu d'une autre touche, n'est pas un geste.
        expect(
            cropKeyCommand({ key: '6', shiftKey: false, code: 'Digit6' }),
        ).toBeNull();
        expect(
            cropKeyCommand({ key: '6', shiftKey: true, code: 'Numpad6' }),
        ).toBeNull();

        // `+` et `=` élargissent, d'un pas ou de quatre avec Maj.
        expect(press(narrower, '+').crop.width).toBe(width);
        expect(press(narrower, '=').crop.width).toBe(width);

        // Le cadre grandit et rétrécit autour de son centre : resserrer puis
        // élargir du même pas rend le cadre de départ, au pixel près.
        expect(press(narrower, '+').crop).toEqual(start.crop);
        expect(press(muchNarrower, '+', { shiftKey: true }).crop).toEqual(
            start.crop,
        );

        // Chaque largeur admise est un multiple du pas, 16:9 exact.
        for (const master of MASTERS) {
            let state = open(master.height);

            for (let index = 0; index < 60; index += 1) {
                state = press(state, index % 3 === 0 ? '+' : '-');
                expectAdmitted(state);
            }
        }
    });

    it('le cadre ne dépasse jamais le plancher ni ne sort du master', () => {
        for (const master of MASTERS) {
            const start = open(master.height);
            const bounds = cropWidthBounds(start);

            expect(bounds.max).toBe(maxCropWidth(master.height, LIMITS));
            expect(bounds.min).toBe(LIMITS.frameCropMinWidthPx);

            // Élargir sans fin s'arrête au plus grand cadre admis.
            const widest = pressTimes(start, '+', 200, { shiftKey: true });

            expect(widest.crop.width).toBe(bounds.max);
            expect(canWiden(widest)).toBe(false);
            expect(press(widest, '+')).toBe(widest);
            expectAdmitted(widest);

            // Resserrer sans fin s'arrête à la largeur minimale.
            const narrowest = pressTimes(start, '-', 200, { shiftKey: true });

            expect(narrowest.crop.width).toBe(bounds.min);
            expect(canNarrow(narrowest)).toBe(false);
            expect(press(narrowest, '-')).toBe(narrowest);
            expectAdmitted(narrowest);

            // Les flèches butent sur les bords du master.
            const topLeft = pressTimes(
                pressTimes(narrowest, 'ArrowLeft', 200, { shiftKey: true }),
                'ArrowUp',
                200,
                { shiftKey: true },
            );

            expect(topLeft.crop.x).toBe(0);
            expect(topLeft.crop.y).toBe(0);
            expectAdmitted(topLeft);

            const bottomRight = pressTimes(
                pressTimes(narrowest, 'ArrowRight', 200, { shiftKey: true }),
                'ArrowDown',
                200,
                { shiftKey: true },
            );

            expect(bottomRight.crop.x).toBe(
                FRAME_GEOMETRY.masterWidth - bottomRight.crop.width,
            );
            expect(bottomRight.crop.y).toBe(
                master.height - bottomRight.crop.height,
            );
            expectAdmitted(bottomRight);

            // Élargi contre un coin, le cadre reste dans le master.
            expectAdmitted(pressTimes(bottomRight, '+', 200));
            expectAdmitted(pressTimes(topLeft, '+', 200));

            // Un glissement au-delà du visuel est retenu au bord.
            const dragged = applyCropCommand(start, {
                kind: 'moveTo',
                x: -5000,
                y: 99999,
            });

            expect(dragged.crop.x).toBe(0);
            expect(dragged.crop.y).toBe(master.height - dragged.crop.height);
            expectAdmitted(dragged);

            // Une longue suite de gestes mêlés ne sort jamais du plancher.
            let state = start;

            for (const command of sequence(master.height, 400)) {
                state = applyCropCommand(state, command);
                expectAdmitted(state);
            }
        }

        // Un master sur lequel aucun cadre n'est admis n'ouvre pas le
        // recadreur : la source serait refusée en `source_aspect`.
        expect(openCrop(masterHeightFor(1920, 400), LIMITS, 0)).toBeNull();

        // Un cadre initial hors plancher (re-recadrage après un durcissement
        // du plancher) s'ouvre tel quel, violation affichée ; le premier geste
        // de taille le ramène dans les bornes.
        const masterHeight = masterHeightFor(3840, 2160);
        const legacy = open(masterHeight, 0, {
            x: 0,
            y: 0,
            width: 1920,
            height: 1080,
        });

        expect(cropStateViolation(legacy)).toBe('too_wide');
        expect(canWiden(legacy)).toBe(false);
        expectAdmitted(press(legacy, '-'));
    });

    it('la touche Origine rétablit le cadre par défaut', () => {
        for (const master of MASTERS) {
            const start = open(master.height);
            const fallback = defaultCrop(master.height, LIMITS);

            expect(start.crop).toEqual(fallback);

            const moved = press(
                pressTimes(
                    pressTimes(start, '-', 5, { shiftKey: true }),
                    'ArrowRight',
                    3,
                    { shiftKey: true },
                ),
                'ArrowUp',
            );

            expect(moved.crop).not.toEqual(fallback);
            expect(press(moved, 'Home').crop).toEqual(fallback);
            expect(press(moved, 'Home', { shiftKey: true }).crop).toEqual(
                fallback,
            );

            // Même geste que le bouton « Cadre par défaut ».
            expect(applyCropCommand(moved, { kind: 'reset' }).crop).toEqual(
                fallback,
            );

            // Ctrl + Origine reste le retour en haut de page du navigateur.
            expect(
                cropKeyCommand({ key: 'Home', shiftKey: false, ctrlKey: true }),
            ).toBeNull();
        }

        // Au re-recadrage, Origine rend le cadre par défaut, pas le cadre
        // d'origine de l'image.
        const masterHeight = masterHeightFor(3840, 2160);
        const recrop = open(masterHeight, 0, {
            x: 0,
            y: 0,
            width: 960,
            height: 540,
        });

        expect(press(recrop, 'Home').crop).toEqual(
            defaultCrop(masterHeight, LIMITS),
        );
    });

    it("le temps de recadrage court de l'ouverture du visuel à l'envoi", () => {
        const masterHeight = masterHeightFor(3840, 2160);
        const openedAtMs = 10_000;
        const opened = open(masterHeight, openedAtMs);

        expect(opened.openedAtMs).toBe(openedAtMs);

        // À l'ouverture, rien n'a encore couru.
        expect(cropSecondsAt(opened, openedAtMs)).toBe(0);

        // Les gestes ne remettent jamais le compteur à zéro.
        let state = opened;

        for (const command of sequence(7, 50)) {
            state = applyCropCommand(state, command);
        }

        state = press(press(state, 'Home'), '-');

        expect(state.openedAtMs).toBe(openedAtMs);

        // Secondes entières écoulées jusqu'à l'envoi, tronquées.
        expect(cropSecondsAt(state, openedAtMs + 42_900)).toBe(42);
        expect(cropSecondsAt(state, openedAtMs + 999)).toBe(0);
        expect(cropSecondsAt(state, openedAtMs + 1_000)).toBe(1);

        // Jamais négatif, même si l'horloge recule.
        expect(cropSecondsAt(state, openedAtMs - 5_000)).toBe(0);

        // Ouvrir un autre visuel repart de son propre instant d'ouverture.
        const nextOpenedAtMs = 60_000;
        const next = open(masterHeightFor(2048, 858), nextOpenedAtMs);

        expect(cropSecondsAt(next, nextOpenedAtMs + 1_500)).toBe(1);
        expect(cropSecondsAt(state, nextOpenedAtMs + 1_500)).toBe(51);
    });

    it("une poignée d'angle garde le 16:9, un multiple de 16 et les deux bornes du plancher", () => {
        expect(CROP_CORNERS).toEqual(['nw', 'ne', 'sw', 'se']);

        for (const master of MASTERS) {
            const start = open(master.height);
            const bounds = cropWidthBounds(start);
            const narrowest = pressTimes(start, '-', 200, { shiftKey: true });

            expect(narrowest.crop.width).toBe(bounds.min);

            for (const corner of CROP_CORNERS) {
                const opposite = OPPOSITE[corner];
                const { sx, sy } = outwards(corner);

                // Tirée loin au-dehors, la poignée bute sur la borne haute du
                // plancher ou sur le bord du master ; le coin opposé ne bouge
                // pas, et le cadre ne sort jamais du master.
                for (const from of [start, narrowest]) {
                    const anchor = cropCornerPoint(from.crop, opposite);
                    const outward = pull(
                        from,
                        corner,
                        anchor.x + sx * 5000,
                        anchor.y + sy * 5000,
                    );

                    expectAdmitted(outward);
                    expect(cropCornerPoint(outward.crop, opposite)).toEqual(
                        anchor,
                    );
                    expect(outward.crop.width).toBeGreaterThanOrEqual(
                        from.crop.width,
                    );
                    expect(canGrowBy(outward, corner)).toBe(false);

                    // Rabattue au-delà du coin fixe, elle rend le cadre le
                    // plus serré admis, sans jamais le retourner.
                    const inward = pull(
                        from,
                        corner,
                        anchor.x - sx * 5000,
                        anchor.y - sy * 5000,
                    );

                    expectAdmitted(inward);
                    expect(inward.crop.width).toBe(bounds.min);
                    expect(cropCornerPoint(inward.crop, opposite)).toEqual(
                        anchor,
                    );
                }

                // Le cadre par défaut est déjà le plus large admis : tirer
                // plus loin ne change rien.
                const anchor = cropCornerPoint(start.crop, opposite);

                expect(
                    pull(start, corner, anchor.x + sx * 5000, anchor.y + sy),
                ).toBe(start);
            }

            // Plaqué dans le coin haut-gauche du master, le cadre le plus
            // serré ne grandit pas par sa poignée nord-ouest (aucune place) ;
            // par la sud-est, il grandit jusqu'à la borne haute exacte.
            const topLeft = applyCropCommand(narrowest, {
                kind: 'moveTo',
                x: 0,
                y: 0,
            });

            expect(topLeft.crop).toEqual({
                x: 0,
                y: 0,
                width: bounds.min,
                height: (bounds.min / 16) * 9,
            });
            expect(pull(topLeft, 'nw', -5000, -5000)).toBe(topLeft);

            const grown = pull(topLeft, 'se', 99999, 99999);

            expect(grown.crop).toEqual({
                x: 0,
                y: 0,
                width: bounds.max,
                height: (bounds.max / 16) * 9,
            });
            expectAdmitted(grown);

            // La largeur visée est arrondie au multiple de 16 le plus proche.
            for (const [x, width] of [
                [1007, 1008],
                [1015, 1008],
                [1017, 1024],
                [1031, 1024],
            ] as const) {
                const rounded = pull(topLeft, 'se', x, 0);

                expect(rounded.crop).toEqual({
                    x: 0,
                    y: 0,
                    width: Math.min(width, bounds.max),
                    height: (Math.min(width, bounds.max) / 16) * 9,
                });
            }

            // Ratio verrouillé : c'est la plus grande des deux étendues qui
            // décide ; un pointeur tiré vers le bas élargit autant qu'il
            // abaisse, et le cadre rejoint toujours le pointeur.
            const tall = pull(topLeft, 'se', 100, 450);

            expect(tall.crop).toEqual({ x: 0, y: 0, width: 800, height: 450 });

            // Une longue suite de poignées tirées, mêlée aux autres gestes, ne
            // sort jamais du plancher ni du master.
            let state = start;
            const mixed = sequence(master.height, 200);

            for (const [index, command] of pulls(
                master.height,
                400,
            ).entries()) {
                state = applyCropCommand(state, command);
                expectAdmitted(state);

                const other = mixed[index % mixed.length];

                if (index % 3 === 0 && other !== undefined) {
                    state = applyCropCommand(state, other);
                    expectAdmitted(state);
                }
            }
        }

        // Un cadre initial hors plancher (re-recadrage après un durcissement)
        // revient dans les bornes et dans le master au premier geste de
        // poignée, qu'il soit trop large ou trop serré.
        const masterHeight = masterHeightFor(3840, 2160);
        const tooWide = open(masterHeight, 0, {
            x: 0,
            y: 0,
            width: 1920,
            height: 1080,
        });

        expect(cropStateViolation(tooWide)).toBe('too_wide');

        for (const corner of CROP_CORNERS) {
            expectAdmitted(pull(tooWide, corner, 960, 540));
        }

        const tooNarrow = open(masterHeight, 0, {
            x: 1600,
            y: 900,
            width: 320,
            height: 180,
        });

        expect(cropStateViolation(tooNarrow)).toBe('too_narrow');

        for (const corner of CROP_CORNERS) {
            expectAdmitted(pull(tooNarrow, corner, 99999, 99999));
            expectAdmitted(pull(tooNarrow, corner, -99999, -99999));
        }
    });

    it('la molette et le pincement élargissent ou resserrent autour du centre, dans les mêmes bornes', () => {
        const PIXEL = 0;
        const LINE = 1;
        const PAGE = 2;

        /** Pas, fractionnaires, d'une largeur multipliée par `factor`. */
        const stepsFor = (width: number, factor: number): number =>
            (width * (factor - 1)) / CROP_STEP_PX;

        // Ctrl + un cran de souris change la largeur d'environ 10 % : vers le
        // haut, le cadre s'élargit, vers le bas, il se resserre. Le cran
        // arrive d'un bloc et pèse au plus 10 pixels, quel que soit le mode
        // du navigateur.
        const up = cropWheelStep(1024, 0, { deltaY: -100, deltaMode: PIXEL });
        const down = cropWheelStep(1024, 0, { deltaY: 100, deltaMode: PIXEL });

        expect(up.steps).toBe(6);
        expect(up.steps + up.carry).toBeCloseTo(
            stepsFor(1024, Math.exp(0.1)),
            10,
        );
        expect(down.steps).toBe(-6);
        expect(down.steps + down.carry).toBeCloseTo(
            stepsFor(1024, Math.exp(-0.1)),
            10,
        );

        for (const notch of [
            { deltaY: 3, deltaMode: LINE },
            { deltaY: 1, deltaMode: PAGE },
            { deltaY: 120, deltaMode: PIXEL },
        ]) {
            expect(cropWheelStep(1024, 0, notch)).toEqual(down);
        }

        for (const notch of [
            { deltaY: -3, deltaMode: LINE },
            { deltaY: -1, deltaMode: PAGE },
        ]) {
            expect(cropWheelStep(1024, 0, notch)).toEqual(up);
        }

        // Un pavé tactile rapporte des fractions : elles s'accumulent en pas
        // entiers, sans zéro négatif.
        const tiny = cropWheelStep(1024, 0, { deltaY: -1, deltaMode: PIXEL });

        expect(tiny.steps).toBe(0);
        expect(tiny.carry).toBeCloseTo(stepsFor(1024, Math.exp(0.01)), 10);
        expect(
            cropWheelStep(1024, tiny.carry, { deltaY: -1, deltaMode: PIXEL })
                .steps,
        ).toBe(1);
        expect(
            Object.is(
                cropWheelStep(1024, 0, { deltaY: 1, deltaMode: PIXEL }).steps,
                0,
            ),
        ).toBe(true);

        // Un changement de sens repart de la largeur du cadre.
        const reversed = cropWheelStep(1024, 0.5, {
            deltaY: 1,
            deltaMode: PIXEL,
        });

        expect(reversed.steps).toBe(0);
        expect(reversed.carry).toBeCloseTo(stepsFor(1024, Math.exp(-0.01)), 10);

        // Le pincement d'un pavé tactile, que Chromium rapporte en petits
        // événements de `deltaY = −100 × ln(s)`, suit l'écartement des
        // doigts : écartés du double, puis ramenés de moitié, les doigts
        // doublent le cadre, puis le rendent, au pas près.
        const trackpad = (from: CropState, scale: number): CropState => {
            let state = from;
            let carry = 0;

            for (let event = 0; event < 20; event += 1) {
                const wheel = cropWheelStep(state.crop.width, carry, {
                    deltaY: -100 * Math.log(scale ** (1 / 20)),
                    deltaMode: PIXEL,
                });

                carry = wheel.carry;
                state = applyCropCommand(state, {
                    kind: 'resize',
                    steps: wheel.steps,
                });
            }

            return state;
        };
        const narrow = applyCropCommand(open(masterHeightFor(3840, 2160)), {
            kind: 'resizeTo',
            width: 704,
        });
        const spread = trackpad(narrow, 2);

        expect(narrow.crop.width).toBe(704);
        expect(Math.abs(spread.crop.width - 1408)).toBeLessThanOrEqual(
            CROP_STEP_PX,
        );
        expectAdmitted(spread);
        expect(
            Math.abs(trackpad(spread, 0.5).crop.width - 704),
        ).toBeLessThanOrEqual(CROP_STEP_PX);

        // Le pincement vise la largeur de départ multipliée par
        // l'écartement relatif des doigts ; deux doigts confondus au départ
        // ne mesurent rien.
        expect(cropPinchCommand(1024, 200, 300)).toEqual({
            kind: 'resizeTo',
            width: 1536,
        });
        expect(cropPinchCommand(1024, 0, 300)).toBeNull();

        for (const master of MASTERS) {
            const start = open(master.height);
            const bounds = cropWidthBounds(start);

            // Molette ou pincement sans fin : les deux bornes du plancher. Un
            // cadre à la borne ne garde qu'une fraction de pas en réserve :
            // le premier cran en sens inverse le fait bouger.
            const wheelOn = (from: CropState, deltaY: number): CropState => {
                let wheeled = from;
                let carry = 0;

                for (let index = 0; index < 100; index += 1) {
                    const wheel = cropWheelStep(wheeled.crop.width, carry, {
                        deltaY,
                        deltaMode: PIXEL,
                    });

                    carry = wheel.carry;
                    wheeled = applyCropCommand(wheeled, {
                        kind: 'resize',
                        steps: wheel.steps,
                    });
                    expectAdmitted(wheeled);
                }

                expect(Math.abs(carry)).toBeLessThan(1);

                return wheeled;
            };
            const narrowest = wheelOn(start, 100);
            const widest = wheelOn(narrowest, -100);

            expect(narrowest.crop.width).toBe(bounds.min);
            expect(widest.crop.width).toBe(bounds.max);

            const reverse = cropWheelStep(widest.crop.width, 0.9, {
                deltaY: 100,
                deltaMode: PIXEL,
            });

            expect(
                applyCropCommand(widest, {
                    kind: 'resize',
                    steps: reverse.steps,
                }).crop.width,
            ).toBeLessThan(bounds.max);

            const pinchedIn = cropPinchCommand(start.crop.width, 400, 1);
            const pinchedOut = cropPinchCommand(start.crop.width, 1, 400);

            if (pinchedIn === null || pinchedOut === null) {
                throw new Error('pincement non mesuré');
            }

            expect(applyCropCommand(start, pinchedIn).crop.width).toBe(
                bounds.min,
            );
            expect(applyCropCommand(start, pinchedOut)).toBe(start);

            // Un pincement arrondit au multiple de 16 et garde le centre du
            // cadre, au demi-pixel vertical près.
            const pinched = applyCropCommand(
                start,
                cropPinchCommand(start.crop.width, 400, 311) ?? {
                    kind: 'reset',
                },
            );
            const expected =
                Math.round((start.crop.width * 311) / 400 / 16) * 16;

            expect(pinched.crop.width).toBe(Math.max(expected, bounds.min));
            expectAdmitted(pinched);
            expect(
                Math.abs(
                    pinched.crop.x +
                        pinched.crop.width / 2 -
                        (start.crop.x + start.crop.width / 2),
                ),
            ).toBeLessThanOrEqual(0.5);
            expect(
                Math.abs(
                    pinched.crop.y +
                        pinched.crop.height / 2 -
                        (start.crop.y + start.crop.height / 2),
                ),
            ).toBeLessThanOrEqual(1);

            // Rendu à l'écartement de départ, le cadre revient exactement.
            const back = cropPinchCommand(start.crop.width, 400, 400);

            if (back === null) {
                throw new Error('pincement non mesuré');
            }

            expect(applyCropCommand(start, back)).toBe(start);
        }
    });
});
