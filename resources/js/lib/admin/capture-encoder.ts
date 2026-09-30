/**
 * La préparation d'une capture dans le navigateur (spec 20 § 5.4, lot L20-33,
 * D38 du 28/09 ; R-46) : le navigateur normalise la source, le serveur
 * dérive toujours le jeu du master et du rectangle, jamais le navigateur.
 *
 * Une capture 1080p ou 4K dépasse les bornes PHP de téléversement, et
 * dépasser `post_max_size` vide `$_POST`, donc le jeton CSRF : un 419 muet,
 * jamais une erreur de validation (CLAUDE.md § 8). La source est donc ramenée
 * ici à `masterWidth` de large, dans l'espace même du master, puis encodée en
 * WebP sous le plafond d'entrée (`frameUploadMaxKilobytes`), en descendant la
 * qualité pas à pas. Le rectangle que le curateur pose se lit alors dans
 * l'espace que le serveur retrouvera sur le fichier reçu :
 * `masterHeightFor(masterWidth, h) === h`.
 *
 * Tout ce qui décide est pur et testé sans DOM (C18 § 2.4) ; le décodage et
 * le canevas sont injectables (`CaptureDeps`), leur version DOM est le
 * défaut. Le serveur revalide tout — format par `finfo`, poids, largeur,
 * plancher —, puis le job renormalise le master : `stripImage()`, refus de
 * l'animation, réencodage (§ 5.5).
 *
 * Les pas de qualité sont des constantes d'encodage du navigateur, pas des
 * valeurs de jeu : aucune n'atteint le serveur, qui réencode le master.
 */

import {
    defaultCrop,
    FRAME_GEOMETRY,
    masterHeightFor,
} from '@/lib/frame-geometry';
import type { CropLimits } from '@/lib/frame-geometry';
import type { AdminFrameLimits } from '@/types/admin';

/** Le seul format qu'accepte la voie capture (`mimetypes:image/webp`). */
export const CAPTURE_MIME_TYPE = 'image/webp';

/** Le nom du fichier envoyé : le serveur n'en lit rien, pas même l'extension. */
export const CAPTURE_FILE_NAME = 'capture.webp';

/**
 * Qualités d'encodage essayées, de la meilleure à la plus basse : la première
 * qui passe sous le plafond d'entrée l'emporte.
 */
export const CAPTURE_QUALITY_STEPS: readonly number[] = [
    0.92, 0.85, 0.78, 0.7, 0.62, 0.55, 0.5,
];

/** La règle `max:` de Laravel compte les fichiers en Ko de 1 024 octets. */
const BYTES_PER_KILOBYTE = 1024;

const MS_PER_SECOND = 1000;
const SECONDS_PER_MINUTE = 60;
const SECONDS_PER_HOUR = 3600;

/**
 * Le minutage d'une capture : `h:mm:ss`, heures sur un ou deux chiffres,
 * minutes et secondes de 00 à 59 — le motif de `FrameCaptureStoreRequest`.
 */
const TIMECODE_PATTERN = /^(\d{1,2}):([0-5]\d):([0-5]\d)$/;

/** Ce que la préparation lit des limites de la banque. */
export type CaptureLimits = CropLimits &
    Pick<AdminFrameLimits, 'frameUploadMaxKilobytes'>;

/** La taille du fichier préparé : celle du master. */
export type CaptureTarget = {
    width: number;
    height: number;
};

/** Un encodeur à une qualité donnée ; `null` quand le navigateur échoue. */
export type CaptureEncoder = (quality: number) => Promise<Blob | null>;

/**
 * Une source décodée : ses dimensions, de quoi la dessiner à la taille cible
 * (`null` si le navigateur n'offre aucun canevas), et de quoi la libérer.
 */
export type DecodedCapture = {
    width: number;
    height: number;
    render: (target: CaptureTarget) => CaptureEncoder | null;
    close: () => void;
};

/** Les dépendances du navigateur : le décodage lève sur une image illisible. */
export type CaptureDeps = {
    decode: (source: Blob) => Promise<DecodedCapture>;
};

export type EncodedCapture =
    | { status: 'ok'; blob: Blob; quality: number }
    | { status: 'too_heavy' | 'unsupported' };

export type CaptureRefusal =
    | 'unreadable'
    | 'too_small'
    | 'too_heavy'
    | 'unsupported';

export type NormalizedCapture =
    | { status: 'ok'; blob: Blob; masterHeight: number }
    | { status: CaptureRefusal };

/**
 * La taille du fichier préparé pour une source, ou `null` si elle ne peut
 * donner aucune image de jeu : moins large qu'une image de jeu, en portrait,
 * ou sans aucun cadre admis par le plancher — le refus même de
 * `FrameGeometry::acceptsSource()`.
 */
export function captureTarget(
    sourceWidth: number,
    sourceHeight: number,
    limits: CropLimits,
): CaptureTarget | null {
    if (
        sourceWidth < FRAME_GEOMETRY.gameWidth ||
        sourceHeight < 1 ||
        sourceHeight > sourceWidth
    ) {
        return null;
    }

    const height = masterHeightFor(sourceWidth, sourceHeight);

    if (defaultCrop(height, limits) === null) {
        return null;
    }

    return { width: FRAME_GEOMETRY.masterWidth, height };
}

/** Le plafond d'entrée d'une capture, en octets. */
export function captureMaxBytes(
    limits: Pick<AdminFrameLimits, 'frameUploadMaxKilobytes'>,
): number {
    return limits.frameUploadMaxKilobytes * BYTES_PER_KILOBYTE;
}

/**
 * Encode à chaque qualité, de la meilleure à la plus basse, jusqu'à passer
 * sous `maxBytes`. Un navigateur qui ne produit pas de WebP retombe sur un
 * autre format — PNG, historiquement Safari — que le serveur refuserait :
 * c'est `unsupported` dès le premier essai, jamais un envoi voué au refus.
 */
export async function encodeUnderCeiling(
    encode: CaptureEncoder,
    maxBytes: number,
    steps: readonly number[] = CAPTURE_QUALITY_STEPS,
): Promise<EncodedCapture> {
    for (const quality of steps) {
        const blob = await encode(quality);

        if (blob === null || blob.type !== CAPTURE_MIME_TYPE) {
            return { status: 'unsupported' };
        }

        if (blob.size <= maxBytes) {
            return { status: 'ok', blob, quality };
        }
    }

    return { status: 'too_heavy' };
}

/**
 * Le minutage saisi, en millisecondes de secondes entières, ou `null` hors
 * de la forme `h:mm:ss`. Jamais de millisecondes : la revue relit
 * exactement ce qui a été saisi (`ReviewQueue::timecode()`), heures sans
 * zéro de tête — « 00:12:34 » se relit « 0:12:34 ».
 */
export function parseTimecode(text: string): number | null {
    const match = TIMECODE_PATTERN.exec(text.trim());

    if (match === null) {
        return null;
    }

    const [, hours, minutes, seconds] = match;

    return (
        (Number(hours) * SECONDS_PER_HOUR +
            Number(minutes) * SECONDS_PER_MINUTE +
            Number(seconds)) *
        MS_PER_SECOND
    );
}

/** La première image d'un collage, ou `null` : du texte collé suit son cours. */
export function pickClipboardImage<T extends { type: string }>(
    files: readonly T[],
): T | null {
    return files.find((file) => file.type.startsWith('image/')) ?? null;
}

/**
 * Le dessin dans le navigateur. `alpha: false` rend un canevas opaque, noir
 * au départ : une source transparente s'y aplatit sur le noir, comme le
 * serveur aplatit la sienne (§ 5.5).
 */
function renderInDom(
    bitmap: ImageBitmap,
    target: CaptureTarget,
): CaptureEncoder | null {
    const canvas = document.createElement('canvas');

    canvas.width = target.width;
    canvas.height = target.height;

    const context = canvas.getContext('2d', { alpha: false });

    if (context === null) {
        return null;
    }

    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, target.width, target.height);

    return (quality) =>
        new Promise<Blob | null>((resolve) => {
            canvas.toBlob(resolve, CAPTURE_MIME_TYPE, quality);
        });
}

/** Le décodage dans le navigateur : `createImageBitmap`, sans passer par le DOM. */
async function decodeInDom(source: Blob): Promise<DecodedCapture> {
    const bitmap = await createImageBitmap(source);

    return {
        width: bitmap.width,
        height: bitmap.height,
        render: (target) => renderInDom(bitmap, target),
        close: () => bitmap.close(),
    };
}

const DOM_CAPTURE_DEPS: CaptureDeps = { decode: decodeInDom };

/**
 * Prépare une capture : décodage, taille cible, dessin à `masterWidth` de
 * large sur fond noir opaque, puis encodage WebP sous le plafond d'entrée.
 * La source décodée est libérée dès le dessin fait, succès ou refus.
 */
export async function normalizeCapture(
    source: Blob,
    limits: CaptureLimits,
    deps: CaptureDeps = DOM_CAPTURE_DEPS,
): Promise<NormalizedCapture> {
    let decoded: DecodedCapture;

    try {
        decoded = await deps.decode(source);
    } catch {
        return { status: 'unreadable' };
    }

    let target: CaptureTarget | null = null;
    let encoder: CaptureEncoder | null = null;

    try {
        target = captureTarget(decoded.width, decoded.height, limits);
        encoder = target === null ? null : decoded.render(target);
    } catch {
        encoder = null;
    } finally {
        decoded.close();
    }

    if (target === null) {
        return { status: 'too_small' };
    }

    if (encoder === null) {
        return { status: 'unsupported' };
    }

    try {
        const encoded = await encodeUnderCeiling(
            encoder,
            captureMaxBytes(limits),
        );

        return encoded.status === 'ok'
            ? { status: 'ok', blob: encoded.blob, masterHeight: target.height }
            : { status: encoded.status };
    } catch {
        return { status: 'unsupported' };
    }
}
