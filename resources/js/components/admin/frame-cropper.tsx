import {
    ArrowDownIcon,
    ArrowLeftIcon,
    ArrowRightIcon,
    ArrowUpIcon,
    CrosshairIcon,
    MaximizeIcon,
    MinimizeIcon,
    RotateCcwIcon,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCropperKeyboard } from '@/hooks/admin/use-cropper-keyboard';
import { useTranslations } from '@/hooks/use-translations';
import {
    canNarrow,
    canWiden,
    CROP_FAST_STEPS,
    cropStateViolation,
    cropSurfacePercent,
} from '@/lib/admin/crop-state';
import type { CropCommand, CropState } from '@/lib/admin/crop-state';
import { formatInteger } from '@/lib/admin-format';
import { FRAME_GEOMETRY } from '@/lib/frame-geometry';
import type { CropViolation } from '@/lib/frame-geometry';
import { cn } from '@/lib/utils';
import type { TranslationKey } from '@/types/translations';

/**
 * Chaque cause de refus du plancher, et la clé qui la nomme — les mêmes que
 * celles du serveur (`CropViolation::translationKey()`, spec 20 § 5.2).
 */
const CROP_VIOLATION_KEYS: Record<CropViolation, TranslationKey> = {
    aspect: 'admin.validation.crop.aspect',
    too_wide: 'admin.validation.crop.too_wide',
    too_narrow: 'admin.validation.crop.too_narrow',
    out_of_bounds: 'admin.validation.crop.out_of_bounds',
};

type MoveDirection = 'left' | 'up' | 'down' | 'right';

/**
 * Les boutons de déplacement, équivalents du glisser (§ 6.4 : « chaque action
 * de la souris a son équivalent bouton »). Un clic décale le cadre d'un pas,
 * comme une flèche du clavier.
 */
const MOVE_BUTTONS: ReadonlyArray<{
    direction: MoveDirection;
    dx: number;
    dy: number;
    label: TranslationKey;
    Icon: LucideIcon;
}> = [
    {
        direction: 'left',
        dx: -1,
        dy: 0,
        label: 'admin.cropper.move_left',
        Icon: ArrowLeftIcon,
    },
    {
        direction: 'up',
        dx: 0,
        dy: -1,
        label: 'admin.cropper.move_up',
        Icon: ArrowUpIcon,
    },
    {
        direction: 'down',
        dx: 0,
        dy: 1,
        label: 'admin.cropper.move_down',
        Icon: ArrowDownIcon,
    },
    {
        direction: 'right',
        dx: 1,
        dy: 0,
        label: 'admin.cropper.move_right',
        Icon: ArrowRightIcon,
    },
];

/** Part d'une longueur du master, en pourcentage CSS de la zone affichée. */
function percentOf(value: number, total: number): string {
    return `${(value / total) * 100}%`;
}

type ImageStatus = 'loading' | 'ready' | 'failed';

/** Issue connue d'un chargement : pour quel visuel, à quelle tentative. */
type SettledImage = {
    src: string;
    attempt: number;
    status: Exclude<ImageStatus, 'loading'>;
};

/** Un glissement en cours : d'où il part, et l'échelle écran → master. */
type Drag = {
    pointerId: number;
    startX: number;
    startY: number;
    originX: number;
    originY: number;
    scale: number;
};

type Props = {
    /**
     * Le visuel affiché dans le cadre, à sa taille `w1280` : une URL du
     * serveur d'images de TMDB en voie TMDB (§ 6.2), celle de l'aperçu
     * `master` au re-recadrage.
     */
    imageUrl: string;
    /**
     * Le cadre et de quoi le juger, tenu par l'éditeur : ouvert par
     * `openCrop()` au moment où le visuel s'ouvre dans le cadre, remplacé à
     * chaque geste par `applyCropCommand()`.
     */
    state: CropState;
    /** Chaque geste — souris, bouton ou clavier — passe par ici. */
    onCommand: (command: CropCommand) => void;
    /** Envoi en cours : le cadre ne bouge plus jusqu'à la réponse. */
    disabled?: boolean;
    className?: string;
};

/**
 * Le recadreur : cadre, boutons, opérabilité clavier (spec 20 § 6.3 et § 6.4,
 * lot L20-9a).
 *
 * Le composant est **contrôlé** et sans horloge : l'éditeur tient l'état
 * (`lib/admin/crop-state.ts`), le recadreur l'affiche et lui envoie des
 * gestes. L'éditeur ouvre donc l'état au moment où le curateur ouvre un
 * visuel — l'instant d'où court `crop_seconds` —, lit la violation pour
 * désactiver l'envoi, et calcule `cropSecondsAt()` à l'envoi. Aucun canevas :
 * la voie TMDB n'envoie que le rectangle, le serveur télécharge l'original et
 * produit le rendu (§ 5.3, § 6.2) ; aucune image de jeu n'est fabriquée ici.
 *
 * Socle, jamais coupé :
 *
 * - **déplacer** en glissant l'intérieur du cadre (pointeur capturé, souris,
 *   stylet ou doigt), l'écart converti en pixels du master ;
 * - **agrandir ou resserrer**, **déplacer d'un pas** dans chaque direction,
 *   **centrer**, **rétablir le cadre par défaut** par huit boutons : chaque
 *   geste de la souris, glisser compris, a son bouton, et ces boutons forment
 *   à eux seuls l'alternative non gestuelle du principe 8 (§ 6.4), sans
 *   clavier ni glissement ;
 * - **au clavier**, le cadre focalisé (`role="group"`, un arrêt de
 *   tabulation) : flèches, `+` / `-`, Maj, Origine (`use-cropper-keyboard`).
 *
 * Jamais au-delà du plancher de D6 du 23/09 : chaque geste est borné par
 * `crop-state.ts`, et les boutons à la borne restent focalisables, marqués
 * `aria-disabled` — un bouton qui devient `disabled` sous le focus renverrait
 * le focus au document.
 *
 * Retour immédiat : dimensions dans le master et part de la surface, dans une
 * description vivante rattachée au cadre ; une violation éventuelle dans une
 * région `aria-live="polite"` propre au recadreur. Le serveur revalide tout.
 *
 * Aucune couleur ni taille en dur : le voile hors cadre est le token
 * `background` à opacité réduite, le cadre le token `primary` (règle 5).
 */
export function FrameCropper({
    imageUrl,
    state,
    onCommand,
    disabled = false,
    className,
}: Props) {
    const { t, locale } = useTranslations();
    const baseId = useId();
    const descriptionId = `${baseId}-description`;
    const instructionsId = `${baseId}-instructions`;
    const violationId = `${baseId}-violation`;

    const regionRef = useRef<HTMLDivElement>(null);
    const dragRef = useRef<Drag | null>(null);

    const [attempt, setAttempt] = useState(0);
    const [settled, setSettled] = useState<SettledImage | null>(null);

    const status: ImageStatus =
        settled !== null &&
        settled.src === imageUrl &&
        settled.attempt === attempt
            ? settled.status
            : 'loading';

    const interactive = !disabled && status === 'ready';
    const handleKeyDown = useCropperKeyboard(onCommand, interactive);

    const { crop, masterHeight } = state;
    const violation = cropStateViolation(state);
    const widenable = canWiden(state);
    const narrowable = canNarrow(state);
    const movable = {
        left: crop.x > 0,
        right: crop.x + crop.width < FRAME_GEOMETRY.masterWidth,
        up: crop.y > 0,
        down: crop.y + crop.height < masterHeight,
    };

    const dimensions = t('admin.cropper.dimensions', {
        width: formatInteger(crop.width, locale),
        height: formatInteger(crop.height, locale),
        percent: formatInteger(Math.round(cropSurfacePercent(state)), locale),
    });

    function settle(next: SettledImage['status']): void {
        setSettled({ src: imageUrl, attempt, status: next });
    }

    function command(next: CropCommand, allowed = true): void {
        if (interactive && allowed) {
            onCommand(next);
        }
    }

    function handlePointerDown(event: ReactPointerEvent<HTMLDivElement>): void {
        const region = regionRef.current;

        if (!interactive || event.button !== 0 || region === null) {
            return;
        }

        const bounds = region.getBoundingClientRect();

        if (bounds.width <= 0) {
            return;
        }

        event.preventDefault();
        region.focus({ preventScroll: true });
        event.currentTarget.setPointerCapture(event.pointerId);

        dragRef.current = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: crop.x,
            originY: crop.y,
            scale: FRAME_GEOMETRY.masterWidth / bounds.width,
        };
    }

    function handlePointerMove(event: ReactPointerEvent<HTMLDivElement>): void {
        const drag = dragRef.current;

        if (drag === null || drag.pointerId !== event.pointerId) {
            return;
        }

        command({
            kind: 'moveTo',
            x: drag.originX + (event.clientX - drag.startX) * drag.scale,
            y: drag.originY + (event.clientY - drag.startY) * drag.scale,
        });
    }

    function endDrag(event: ReactPointerEvent<HTMLDivElement>): void {
        if (dragRef.current?.pointerId === event.pointerId) {
            dragRef.current = null;
        }
    }

    /*
     * Le cadre n'est dit désactivé que prêt et figé par un envoi. Un
     * `aria-disabled` s'étend aux descendants focalisables (ARIA 1.2) : posé
     * sur un visuel en échec, il annoncerait « indisponible » le seul chemin
     * de reprise, « Réessayer », niché dans la région. En chargement comme en
     * échec, le cadre est de toute façon hors de la tabulation.
     */
    const regionDisabled = status === 'ready' && disabled;

    return (
        <div className={cn('flex w-full flex-col gap-3', className)}>
            <div
                ref={regionRef}
                role="group"
                aria-label={t('admin.cropper.region_label')}
                aria-describedby={`${descriptionId} ${violationId} ${instructionsId}`}
                aria-disabled={regionDisabled ? true : undefined}
                aria-busy={status === 'loading' ? true : undefined}
                tabIndex={status === 'ready' ? 0 : -1}
                onKeyDown={handleKeyDown}
                className="relative w-full max-w-7xl overflow-hidden rounded-md bg-muted outline-none select-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
            >
                <img
                    key={`${imageUrl}#${attempt}`}
                    src={imageUrl}
                    alt={t('admin.cropper.image_alt')}
                    width={FRAME_GEOMETRY.masterWidth}
                    height={masterHeight}
                    draggable={false}
                    decoding="async"
                    referrerPolicy="no-referrer"
                    onLoad={() => settle('ready')}
                    onError={() => settle('failed')}
                    className={cn(
                        'block h-auto w-full',
                        status !== 'ready' && 'invisible',
                    )}
                />

                {status === 'ready' && (
                    <div
                        aria-hidden
                        onPointerDown={handlePointerDown}
                        onPointerMove={handlePointerMove}
                        onPointerUp={endDrag}
                        onPointerCancel={endDrag}
                        onLostPointerCapture={endDrag}
                        className={cn(
                            'absolute touch-none border-2 border-primary outline-[100vmax] outline-background/70',
                            interactive ? 'cursor-move' : 'cursor-not-allowed',
                        )}
                        style={{
                            left: percentOf(crop.x, FRAME_GEOMETRY.masterWidth),
                            top: percentOf(crop.y, masterHeight),
                            width: percentOf(
                                crop.width,
                                FRAME_GEOMETRY.masterWidth,
                            ),
                            height: percentOf(crop.height, masterHeight),
                        }}
                    />
                )}

                {status === 'loading' && (
                    <div role="status" className="absolute inset-0">
                        <Skeleton className="size-full rounded-none" />
                        <span className="sr-only">
                            {t('admin.cropper.image_loading')}
                        </span>
                    </div>
                )}

                {status === 'failed' && (
                    <div className="absolute inset-0 flex items-center justify-center p-4">
                        <AdminErrorState
                            title={t('admin.cropper.image_failed')}
                            description={t(
                                'admin.cropper.image_failed_description',
                            )}
                            retryLabel={t('admin.cropper.retry')}
                            onRetry={() => setAttempt((current) => current + 1)}
                            className="max-w-xl bg-background"
                        />
                    </div>
                )}
            </div>

            <div className="flex flex-col gap-1">
                <p
                    id={descriptionId}
                    aria-live="polite"
                    aria-atomic="true"
                    className="text-sm text-foreground"
                >
                    <span>{dimensions}</span>
                    {/*
                     * Les mentions de borne ne valent que pour un cadre admis :
                     * un cadre initial hors plancher (re-recadrage après un
                     * durcissement) n'est ni « le plus large » ni « le plus
                     * serré » admis, et sa violation le dit déjà.
                     */}
                    {violation === null && !widenable && (
                        <>
                            {' '}
                            <span>{t('admin.cropper.at_widest')}</span>
                        </>
                    )}
                    {violation === null && !narrowable && (
                        <>
                            {' '}
                            <span>{t('admin.cropper.at_narrowest')}</span>
                        </>
                    )}
                </p>
                <p
                    id={violationId}
                    aria-live="polite"
                    aria-atomic="true"
                    className="text-sm text-destructive"
                >
                    {violation === null
                        ? null
                        : t(CROP_VIOLATION_KEYS[violation])}
                </p>
            </div>

            <div
                role="group"
                aria-label={t('admin.cropper.controls_label')}
                className="flex flex-wrap gap-2"
            >
                <Button
                    type="button"
                    variant="outline"
                    aria-disabled={interactive && widenable ? undefined : true}
                    onClick={() =>
                        command({ kind: 'resize', steps: 1 }, widenable)
                    }
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    <MaximizeIcon aria-hidden />
                    {t('admin.cropper.widen')}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    aria-disabled={interactive && narrowable ? undefined : true}
                    onClick={() =>
                        command({ kind: 'resize', steps: -1 }, narrowable)
                    }
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    <MinimizeIcon aria-hidden />
                    {t('admin.cropper.narrow')}
                </Button>
                {MOVE_BUTTONS.map(({ direction, dx, dy, label, Icon }) => {
                    const allowed = movable[direction];

                    return (
                        <Button
                            key={direction}
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={t(label)}
                            aria-disabled={
                                interactive && allowed ? undefined : true
                            }
                            onClick={() =>
                                command({ kind: 'move', dx, dy }, allowed)
                            }
                            className="min-h-11 min-w-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                        >
                            <Icon aria-hidden />
                        </Button>
                    );
                })}
                <Button
                    type="button"
                    variant="outline"
                    aria-disabled={interactive ? undefined : true}
                    onClick={() => command({ kind: 'center' })}
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    <CrosshairIcon aria-hidden />
                    {t('admin.cropper.center')}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    aria-disabled={interactive ? undefined : true}
                    onClick={() => command({ kind: 'reset' })}
                    className="min-h-11 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                >
                    <RotateCcwIcon aria-hidden />
                    {t('admin.cropper.reset')}
                </Button>
            </div>

            <p id={instructionsId} className="text-xs text-muted-foreground">
                {t('admin.cropper.instructions', { steps: CROP_FAST_STEPS })}
            </p>
        </div>
    );
}
