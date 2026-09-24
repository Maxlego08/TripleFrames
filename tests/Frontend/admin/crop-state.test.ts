import { describe, expect, it } from 'vite-plus/test';
import {
    applyCropCommand,
    canNarrow,
    canWiden,
    CROP_FAST_STEPS,
    CROP_STEP_PX,
    cropKeyCommand,
    cropSecondsAt,
    cropStateViolation,
    cropWidthBounds,
    openCrop,
} from '@/lib/admin/crop-state';
import type {
    CropCommand,
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
 * État pur du recadreur (spec 20 § 6.3 et § 6.4, lot L20-9a).
 *
 * Le recadreur ne fait que brancher ces fonctions sur ses événements : ce que
 * font une flèche, `+`, `-`, Maj ou Origine, et ce que mesure `crop_seconds`,
 * se prouve ici sans DOM (C18 § 2.4). Les bornes du plancher sont celles du
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
});
