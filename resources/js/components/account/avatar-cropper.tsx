import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';

/** Côté affiché du cadre de recadrage, en pixels CSS (`size-64`). */
const VIEWPORT_PX = 256;

/** Zoom maximal, en multiple du cadrage « couvrant ». */
const MAX_ZOOM = 4;

/** Pas d'un déplacement au clavier, en pixels CSS. */
const KEY_STEP_PX = 8;

/** Qualités essayées à l'encodage, de la meilleure à la plus basse. */
const QUALITIES = [0.92, 0.82, 0.7, 0.55];

type Offset = { x: number; y: number };

export type AvatarCropperLabels = {
    help: string;
    zoom: string;
    preview: string;
    confirm: string;
    cancel: string;
    busy: string;
};

type Props = {
    /** Le fichier choisi, jamais envoyé tel quel. */
    file: File;
    /** Côté du carré envoyé au serveur (`AvatarImage::SOURCE_SIZE_PX`). */
    outputSize: number;
    /** Plafond d'envoi (`PlatformLimits::avatarUploadMaxKilobytes()`). */
    maxKilobytes: number;
    /** Textes DÉJÀ traduits. */
    labels: AvatarCropperLabels;
    busy: boolean;
    onCancel: () => void;
    onCropped: (image: File) => void;
    /** Le fichier ne se décode pas dans le navigateur. */
    onUnreadable: () => void;
};

/**
 * Le recadreur carré d'un avatar de compte (spec 40 § 11.2, D49 du 01/10).
 *
 * Le travail lourd reste dans le navigateur : l'image est placée par
 * glisser (pointeur) ou aux flèches (clavier), zoomée au curseur, puis
 * dessinée sur un canevas de `outputSize` de côté et encodée en WebP — repli
 * JPEG là où le navigateur n'encode pas le WebP —, qualité descendante sous
 * `maxKilobytes`. Le serveur revalide tout et recadre de nouveau au carré
 * central : ce composant ne décide de rien.
 */
export function AvatarCropper({
    file,
    outputSize,
    maxKilobytes,
    labels,
    busy,
    onCancel,
    onCropped,
    onUnreadable,
}: Props) {
    const helpId = useId();
    const zoomId = useId();
    const imageRef = useRef<HTMLImageElement>(null);
    const drag = useRef<{ pointer: number; x: number; y: number } | null>(null);
    const [source, setSource] = useState<string | null>(null);
    const [natural, setNatural] = useState<{ w: number; h: number } | null>(
        null,
    );
    const [zoom, setZoom] = useState(1);
    const [offset, setOffset] = useState<Offset>({ x: 0, y: 0 });

    useEffect(() => {
        const url = URL.createObjectURL(file);
        setSource(url);
        setNatural(null);
        setZoom(1);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const scale =
        natural === null
            ? 1
            : (VIEWPORT_PX / Math.min(natural.w, natural.h)) * zoom;
    const width = natural === null ? VIEWPORT_PX : natural.w * scale;
    const height = natural === null ? VIEWPORT_PX : natural.h * scale;

    const clamp = (next: Offset, w = width, h = height): Offset => ({
        x: Math.min(0, Math.max(VIEWPORT_PX - w, next.x)),
        y: Math.min(0, Math.max(VIEWPORT_PX - h, next.y)),
    });

    const changeZoom = (next: number): void => {
        if (natural === null) {
            return;
        }

        const nextScale = (VIEWPORT_PX / Math.min(natural.w, natural.h)) * next;
        const centerX = (VIEWPORT_PX / 2 - offset.x) / scale;
        const centerY = (VIEWPORT_PX / 2 - offset.y) / scale;

        setZoom(next);
        setOffset(
            clamp(
                {
                    x: VIEWPORT_PX / 2 - centerX * nextScale,
                    y: VIEWPORT_PX / 2 - centerY * nextScale,
                },
                natural.w * nextScale,
                natural.h * nextScale,
            ),
        );
    };

    const onPointerDown = (event: PointerEvent<HTMLDivElement>): void => {
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = {
            pointer: event.pointerId,
            x: event.clientX - offset.x,
            y: event.clientY - offset.y,
        };
    };

    const onPointerMove = (event: PointerEvent<HTMLDivElement>): void => {
        const current = drag.current;

        if (current === null || current.pointer !== event.pointerId) {
            return;
        }

        setOffset(
            clamp({
                x: event.clientX - current.x,
                y: event.clientY - current.y,
            }),
        );
    };

    const onPointerUp = (): void => {
        drag.current = null;
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        const moves: Record<string, Offset> = {
            ArrowLeft: { x: KEY_STEP_PX, y: 0 },
            ArrowRight: { x: -KEY_STEP_PX, y: 0 },
            ArrowUp: { x: 0, y: KEY_STEP_PX },
            ArrowDown: { x: 0, y: -KEY_STEP_PX },
        };
        const move = moves[event.key];

        if (move === undefined) {
            return;
        }

        event.preventDefault();
        setOffset(clamp({ x: offset.x + move.x, y: offset.y + move.y }));
    };

    const confirm = async (): Promise<void> => {
        const image = imageRef.current;

        if (image === null || natural === null) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = outputSize;
        canvas.height = outputSize;
        const context = canvas.getContext('2d');

        if (context === null) {
            onUnreadable();

            return;
        }

        const side = VIEWPORT_PX / scale;
        context.imageSmoothingQuality = 'high';
        context.drawImage(
            image,
            -offset.x / scale,
            -offset.y / scale,
            side,
            side,
            0,
            0,
            outputSize,
            outputSize,
        );

        const blob = await encode(canvas, maxKilobytes * 1024);

        if (blob === null) {
            onUnreadable();

            return;
        }

        const extension = blob.type === 'image/webp' ? 'webp' : 'jpg';
        onCropped(new File([blob], `avatar.${extension}`, { type: blob.type }));
    };

    return (
        <div className="flex flex-col gap-4">
            <p id={helpId} className="text-sm text-muted-foreground">
                {labels.help}
            </p>

            <div
                role="img"
                aria-label={labels.preview}
                aria-describedby={helpId}
                tabIndex={0}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                onPointerCancel={onPointerUp}
                onKeyDown={onKeyDown}
                className="relative size-64 cursor-grab touch-none overflow-hidden rounded-full border border-border bg-muted outline-none select-none focus-visible:ring-2 focus-visible:ring-ring active:cursor-grabbing"
            >
                {source !== null && (
                    <img
                        ref={imageRef}
                        src={source}
                        alt=""
                        draggable={false}
                        onLoad={(event) => {
                            const target = event.currentTarget;
                            const w = target.naturalWidth;
                            const h = target.naturalHeight;
                            const base = VIEWPORT_PX / Math.min(w, h);

                            setNatural({ w, h });
                            setOffset({
                                x: (VIEWPORT_PX - w * base) / 2,
                                y: (VIEWPORT_PX - h * base) / 2,
                            });
                        }}
                        onError={onUnreadable}
                        className="pointer-events-none absolute top-0 left-0 max-w-none origin-top-left"
                        style={{
                            width,
                            height,
                            transform: `translate(${offset.x}px, ${offset.y}px)`,
                        }}
                    />
                )}
            </div>

            <div className="grid max-w-64 gap-2">
                <Label htmlFor={zoomId}>{labels.zoom}</Label>
                <Slider
                    id={zoomId}
                    min={1}
                    max={MAX_ZOOM}
                    step={0.05}
                    value={[zoom]}
                    onValueChange={([next]) => {
                        if (next !== undefined) {
                            changeZoom(next);
                        }
                    }}
                    aria-label={labels.zoom}
                    disabled={natural === null}
                />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    onClick={() => void confirm()}
                    disabled={natural === null || busy}
                    aria-busy={busy}
                    className="min-h-11"
                >
                    {busy ? labels.busy : labels.confirm}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    onClick={onCancel}
                    disabled={busy}
                    className="min-h-11"
                >
                    {labels.cancel}
                </Button>
            </div>
        </div>
    );
}

/** `canvas.toBlob` en promesse. */
function toBlob(
    canvas: HTMLCanvasElement,
    type: string,
    quality: number,
): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * WebP, sinon JPEG, à qualité descendante jusqu'au plafond ; `null` si rien
 * ne tient dessous.
 */
async function encode(
    canvas: HTMLCanvasElement,
    maxBytes: number,
): Promise<Blob | null> {
    for (const quality of QUALITIES) {
        let blob = await toBlob(canvas, 'image/webp', quality);

        if (blob === null || blob.type !== 'image/webp') {
            blob = await toBlob(canvas, 'image/jpeg', quality);
        }

        if (blob !== null && blob.size <= maxBytes) {
            return blob;
        }
    }

    return null;
}
