import { describe, expect, it } from 'vite-plus/test';
import {
    CAPTURE_MIME_TYPE,
    CAPTURE_QUALITY_STEPS,
    captureMaxBytes,
    captureTarget,
    encodeUnderCeiling,
    normalizeCapture,
    parseTimecode,
    pickClipboardImage,
} from '@/lib/admin/capture-encoder';
import type {
    CaptureEncoder,
    CaptureLimits,
    CaptureTarget,
    DecodedCapture,
} from '@/lib/admin/capture-encoder';
import { FRAME_GEOMETRY } from '@/lib/frame-geometry';

/*
 * La préparation d'une capture dans le navigateur (spec 20 § 5.4, lot
 * L20-33, D38 du 28/09) : taille cible, encodage sous le plafond d'entrée,
 * minutage, collage. Tout se prouve sans DOM (C18 § 2.4) : le décodage et le
 * canevas sont des doublures, et aucune image réelle n'est lue — des
 * octets vides d'une taille donnée suffisent à juger un poids.
 *
 * Les bornes sont celles du réglage par défaut de `PlatformLimits` (D6 du
 * 23/09) ; chaque cas les porte lui-même, indépendamment de la
 * configuration.
 */

const LIMITS: CaptureLimits = {
    frameCropMaxWidthPercent: 80,
    frameCropMinWidthPx: 640,
    frameUploadMaxKilobytes: 1536,
};

/** Un fichier de `size` octets, au format `type`. */
function blobOf(size: number, type: string = CAPTURE_MIME_TYPE): Blob {
    return new Blob([new Uint8Array(size)], { type });
}

/**
 * Un encodeur dont le poids suit la qualité, et qui garde trace des qualités
 * essayées, dans l'ordre.
 */
function weighingEncoder(
    bytesAt: (quality: number) => number,
    type: string = CAPTURE_MIME_TYPE,
): { encode: CaptureEncoder; qualities: number[] } {
    const qualities: number[] = [];

    return {
        qualities,
        encode: (quality) => {
            qualities.push(quality);

            return Promise.resolve(blobOf(bytesAt(quality), type));
        },
    };
}

type FakeSource = {
    width: number;
    height: number;
    encoder?: CaptureEncoder | null;
    renderThrows?: boolean;
};

/** Une source décodée factice, et ce que la préparation lui a demandé. */
function fakeDecoded(source: FakeSource): {
    decoded: DecodedCapture;
    targets: CaptureTarget[];
    closed: () => number;
} {
    const targets: CaptureTarget[] = [];
    let closes = 0;

    return {
        targets,
        closed: () => closes,
        decoded: {
            width: source.width,
            height: source.height,
            render: (target) => {
                targets.push(target);

                if (source.renderThrows === true) {
                    throw new Error('canevas indisponible');
                }

                return source.encoder === undefined
                    ? () => Promise.resolve(blobOf(1))
                    : source.encoder;
            },
            close: () => {
                closes += 1;
            },
        },
    };
}

describe('encodeUnderCeiling', () => {
    it("l'encodeur descend la qualité jusqu'à passer sous le plafond d'entrée", async () => {
        const maxBytes = captureMaxBytes(LIMITS);
        // Au-dessus du plafond jusqu'à 0,78 compris, dessous à partir de 0,7.
        const { encode, qualities } = weighingEncoder((quality) =>
            quality > 0.75 ? maxBytes + 1 : maxBytes,
        );

        const result = await encodeUnderCeiling(encode, maxBytes);

        expect(result.status).toBe('ok');
        expect(qualities).toEqual([0.92, 0.85, 0.78, 0.7]);

        if (result.status === 'ok') {
            expect(result.quality).toBe(0.7);
            expect(result.blob.size).toBe(maxBytes);
            expect(result.blob.type).toBe(CAPTURE_MIME_TYPE);
        }
    });

    it("s'arrête à la meilleure qualité qui passe, sans descendre plus bas", async () => {
        const { encode, qualities } = weighingEncoder(() => 1);

        const result = await encodeUnderCeiling(encode, 1);

        expect(result).toMatchObject({ status: 'ok', quality: 0.92 });
        expect(qualities).toEqual([0.92]);
    });

    it('le plafond jamais atteint rend « trop lourd », après toutes les qualités', async () => {
        const { encode, qualities } = weighingEncoder(() => 2048);

        const result = await encodeUnderCeiling(encode, 2047);

        expect(result).toEqual({ status: 'too_heavy' });
        expect(qualities).toEqual([...CAPTURE_QUALITY_STEPS]);
    });

    it('un navigateur qui retombe sur un autre format que le WebP est « non pris en charge » dès le premier essai', async () => {
        const png = weighingEncoder(() => 1, 'image/png');

        expect(await encodeUnderCeiling(png.encode, 1024)).toEqual({
            status: 'unsupported',
        });
        expect(png.qualities).toEqual([0.92]);

        const failing: CaptureEncoder = () => Promise.resolve(null);

        expect(await encodeUnderCeiling(failing, 1024)).toEqual({
            status: 'unsupported',
        });
    });

    it('les qualités essayées descendent strictement, entre 0 et 1', () => {
        CAPTURE_QUALITY_STEPS.forEach((quality, index) => {
            expect(quality).toBeGreaterThan(0);
            expect(quality).toBeLessThanOrEqual(1);

            if (index > 0) {
                expect(quality).toBeLessThan(
                    CAPTURE_QUALITY_STEPS[index - 1] ?? 1,
                );
            }
        });
    });
});

describe('captureMaxBytes', () => {
    it("le plafond d'entrée se compte en Ko de 1 024 octets, comme la règle `max:` de Laravel", () => {
        expect(captureMaxBytes(LIMITS)).toBe(1536 * 1024);
        expect(captureMaxBytes({ frameUploadMaxKilobytes: 1 })).toBe(1024);
    });
});

describe('captureTarget', () => {
    it('ramène toute source admise à la largeur du master, dans son espace', () => {
        expect(captureTarget(1920, 1080, LIMITS)).toEqual({
            width: FRAME_GEOMETRY.masterWidth,
            height: 1080,
        });
        expect(captureTarget(3840, 2160, LIMITS)).toEqual({
            width: FRAME_GEOMETRY.masterWidth,
            height: 1080,
        });
        expect(captureTarget(1280, 720, LIMITS)).toEqual({
            width: FRAME_GEOMETRY.masterWidth,
            height: 1080,
        });
        // Un scope 2,39:1 : la hauteur du master, arrondie au plus proche.
        expect(captureTarget(3840, 1606, LIMITS)).toEqual({
            width: FRAME_GEOMETRY.masterWidth,
            height: 803,
        });
    });

    it("refuse une source moins large qu'une image de jeu, en portrait, ou sans cadre admis", () => {
        expect(captureTarget(1000, 562, LIMITS)).toBeNull();
        expect(
            captureTarget(FRAME_GEOMETRY.gameWidth - 1, 700, LIMITS),
        ).toBeNull();
        expect(captureTarget(1080, 1920, LIMITS)).toBeNull();
        expect(captureTarget(1920, 0, LIMITS)).toBeNull();
        // Un bandeau : aucun cadre ne tient au-dessus du plancher.
        expect(captureTarget(1920, 200, LIMITS)).toBeNull();
    });

    it('admet une source carrée, que le serveur accepte aussi', () => {
        expect(captureTarget(1920, 1920, LIMITS)).toEqual({
            width: FRAME_GEOMETRY.masterWidth,
            height: 1920,
        });
    });
});

describe('parseTimecode', () => {
    it('lit h:mm:ss en millisecondes de secondes entières', () => {
        expect(parseTimecode('0:12:34')).toBe(754_000);
        expect(parseTimecode('00:12:34')).toBe(754_000);
        expect(parseTimecode('1:02:03')).toBe(3_723_000);
        expect(parseTimecode('12:00:00')).toBe(43_200_000);
        expect(parseTimecode('  0:12:34 ')).toBe(754_000);
    });

    it('refuse tout minutage hors de la forme h:mm:ss', () => {
        for (const text of [
            '12:34',
            '0:60:00',
            '0:00:60',
            '0:1:02',
            '100:00:00',
            '0:12:34.5',
            '-0:12:34',
            'abc',
            '',
            '   ',
        ]) {
            expect(parseTimecode(text)).toBeNull();
        }
    });
});

describe('pickClipboardImage', () => {
    it("prend la première image d'un collage, et rien d'autre", () => {
        const text = { type: 'text/plain', name: 'a' };
        const png = { type: 'image/png', name: 'b' };
        const jpeg = { type: 'image/jpeg', name: 'c' };

        expect(pickClipboardImage([text, png, jpeg])).toBe(png);
        expect(pickClipboardImage([text])).toBeNull();
        expect(pickClipboardImage([])).toBeNull();
    });
});

describe('normalizeCapture', () => {
    it('prépare une capture 4K au master, en WebP sous le plafond, et libère la source', async () => {
        const maxBytes = captureMaxBytes(LIMITS);
        const { encode, qualities } = weighingEncoder((quality) =>
            quality > 0.8 ? maxBytes + 1 : maxBytes - 1,
        );
        const fake = fakeDecoded({
            width: 3840,
            height: 2160,
            encoder: encode,
        });
        const sources: Blob[] = [];
        const source = blobOf(4096, 'image/png');

        const result = await normalizeCapture(source, LIMITS, {
            decode: (blob) => {
                sources.push(blob);

                return Promise.resolve(fake.decoded);
            },
        });

        expect(result.status).toBe('ok');

        if (result.status === 'ok') {
            expect(result.masterHeight).toBe(1080);
            expect(result.blob.type).toBe(CAPTURE_MIME_TYPE);
            expect(result.blob.size).toBe(maxBytes - 1);
        }

        expect(sources).toEqual([source]);
        expect(fake.targets).toEqual([
            { width: FRAME_GEOMETRY.masterWidth, height: 1080 },
        ]);
        expect(qualities).toEqual([0.92, 0.85, 0.78]);
        expect(fake.closed()).toBe(1);
    });

    it('une image illisible est « illisible »', async () => {
        const result = await normalizeCapture(blobOf(10, 'image/png'), LIMITS, {
            decode: () => Promise.reject(new Error('illisible')),
        });

        expect(result).toEqual({ status: 'unreadable' });
    });

    it('une source trop petite ou en portrait est refusée sans être dessinée', async () => {
        for (const [width, height] of [
            [1000, 562],
            [1080, 1920],
        ] as const) {
            const fake = fakeDecoded({ width, height });

            const result = await normalizeCapture(blobOf(10), LIMITS, {
                decode: () => Promise.resolve(fake.decoded),
            });

            expect(result).toEqual({ status: 'too_small' });
            expect(fake.targets).toEqual([]);
            expect(fake.closed()).toBe(1);
        }
    });

    it('sans canevas, ou quand le dessin ou l’encodage échoue, la capture est « non prise en charge »', async () => {
        const withoutCanvas = fakeDecoded({
            width: 1920,
            height: 1080,
            encoder: null,
        });
        const throwing = fakeDecoded({
            width: 1920,
            height: 1080,
            renderThrows: true,
        });
        const rejecting = fakeDecoded({
            width: 1920,
            height: 1080,
            encoder: () => Promise.reject(new Error('toBlob')),
        });

        for (const fake of [withoutCanvas, throwing, rejecting]) {
            const result = await normalizeCapture(blobOf(10), LIMITS, {
                decode: () => Promise.resolve(fake.decoded),
            });

            expect(result).toEqual({ status: 'unsupported' });
            expect(fake.closed()).toBe(1);
        }
    });

    it('une capture trop chargée même au plus bas est « trop lourde »', async () => {
        const maxBytes = captureMaxBytes(LIMITS);
        const fake = fakeDecoded({
            width: 1920,
            height: 1080,
            encoder: () => Promise.resolve(blobOf(maxBytes + 1)),
        });

        const result = await normalizeCapture(blobOf(10), LIMITS, {
            decode: () => Promise.resolve(fake.decoded),
        });

        expect(result).toEqual({ status: 'too_heavy' });
    });
});
