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
import { useEffect, useEffectEvent, useId, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useCropperKeyboard } from '@/hooks/admin/use-cropper-keyboard';
import { useTranslations } from '@/hooks/use-translations';
import {
    canNarrow,
    canWiden,
    CROP_CORNERS,
    CROP_FAST_STEPS,
    cropCornerPoint,
    cropPinchCommand,
    cropStateViolation,
    cropSurfacePercent,
    cropWheelStep,
} from '@/lib/admin/crop-state';
import type {
    CropCommand,
    CropCorner,
    CropPoint,
    CropState,
} from '@/lib/admin/crop-state';
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

/**
 * Place et curseur de chaque poignée d'angle (L20-9b) : à l'intérieur du
 * coin, pour que ni le voile hors cadre ni le bord du visuel ne la
 * recouvrent ; le curseur dit la diagonale du redimensionnement.
 */
const CORNER_CLASSES: Record<CropCorner, string> = {
    nw: 'top-0 left-0 cursor-nwse-resize',
    ne: 'top-0 right-0 cursor-nesw-resize',
    sw: 'bottom-0 left-0 cursor-nesw-resize',
    se: 'right-0 bottom-0 cursor-nwse-resize',
};

/** Part d'une longueur du master, en pourcentage CSS de la zone affichée. */
function percentOf(value: number, total: number): string {
    return `${(value / total) * 100}%`;
}

/** Écartement de deux doigts, en pixels de l'écran. */
function spread(first: CropPoint, second: CropPoint): number {
    return Math.hypot(second.x - first.x, second.y - first.y);
}

/** La poignée d'angle sous le pointeur, ou `null` hors des poignées. */
function cornerAt(target: EventTarget): CropCorner | null {
    if (!(target instanceof Element)) {
        return null;
    }

    const value = target
        .closest('[data-crop-corner]')
        ?.getAttribute('data-crop-corner');

    return CROP_CORNERS.find((corner) => corner === value) ?? null;
}

type ImageStatus = 'loading' | 'ready' | 'failed';

/** Issue connue d'un chargement : pour quel visuel, à quelle tentative. */
type SettledImage = {
    src: string;
    attempt: number;
    status: Exclude<ImageStatus, 'loading'>;
};

/**
 * Le geste de pointeur en cours sur le cadre :
 *
 * - `drag` : glisser l'intérieur — d'où il part, et l'échelle écran → master ;
 * - `corner` : tirer une poignée d'angle — l'origine et l'échelle de la zone
 *   affichée, et l'écart, en pixels du master, entre le pointeur et le coin
 *   au moment de la saisie, pour que le coin ne saute pas sous le pointeur ;
 * - `pinch` : pincer à deux doigts — les deux doigts du pincement, la
 *   largeur du cadre et leur écartement au début du pincement.
 */
type Gesture =
    | {
          kind: 'drag';
          pointerId: number;
          startX: number;
          startY: number;
          originX: number;
          originY: number;
          scale: number;
      }
    | {
          kind: 'corner';
          pointerId: number;
          corner: CropCorner;
          left: number;
          top: number;
          scale: number;
          offsetX: number;
          offsetY: number;
      }
    | {
          kind: 'pinch';
          pointerIds: readonly [number, number];
          startWidth: number;
          startDistance: number;
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
 * lot L20-9a) ; poignées d'angle, molette et pincement (lot L20-9b).
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
 * Gestes de pointeur avancés (L20-9b), confort à la souris hors de la barre
 * « terminé » : ils n'ajoutent aucune capacité que les boutons et le clavier
 * n'offrent déjà.
 *
 * - **Poignées d'angle** : tirer un coin redimensionne le cadre, le coin
 *   opposé fixe, ratio verrouillé, largeur arrondie au multiple de 16
 *   (`resizeCorner`). Les poignées ne sont rendues que sur un cadre
 *   interactif, et restent hors de l'arbre d'accessibilité comme le cadre
 *   dessiné : « Plus large » et « Plus serré » en sont l'équivalent.
 * - **Ctrl + molette** : vers le haut, le cadre s'élargit ; vers le bas, il
 *   se resserre, d'environ 10 % par cran (`cropWheelStep`, multiplicatif).
 *   Elle n'agit que sur un cadre **sélectionné** (focalisé : glissé, cliqué
 *   ou atteint par Tab), comme le clavier. La molette seule fait toujours
 *   défiler la page, cadre sélectionné ou non : après un glisser, le
 *   curateur descend vers le niveau et l'envoi sans jamais retailler le
 *   cadre par mégarde. Un pavé tactile rapporte son pincement comme une
 *   molette avec Ctrl : il suit la même voie, et l'écartement des doigts.
 * - **Pincement** à deux doigts posés sur le cadre : l'écartement des doigts
 *   règle la largeur, autour du centre (`cropPinchCommand`). Le pincement
 *   suit ses deux doigts, et eux seuls : un troisième n'y entre pas. Dès que
 *   l'un des deux se lève, le pincement est clos, et aucun doigt restant ne
 *   reprend un glissement ni un pincement, pour que le cadre ne saute pas.
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
    const gestureRef = useRef<Gesture | null>(null);
    /** Pointeurs posés sur le cadre, en pixels de l'écran, par identifiant. */
    const pointersRef = useRef(new Map<number, CropPoint>());
    /** Fraction de pas de molette retenue pour l'événement suivant. */
    const wheelCarryRef = useRef(0);

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

    /*
     * Ctrl + molette n'agit que sur un cadre sélectionné, et doit alors
     * empêcher le zoom de la page : il lui faut un écouteur natif non passif,
     * React posant `onWheel` en passif. Sans Ctrl, la molette suit son cours :
     * la page défile. L'Effect Event lit l'état du dernier rendu sans
     * réabonner l'écouteur à chaque geste.
     */
    const handleWheel = useEffectEvent((event: WheelEvent): void => {
        const region = regionRef.current;

        if (
            !interactive ||
            region === null ||
            !event.ctrlKey ||
            event.deltaY === 0 ||
            document.activeElement !== region
        ) {
            return;
        }

        event.preventDefault();

        const { steps, carry } = cropWheelStep(
            crop.width,
            wheelCarryRef.current,
            event,
        );

        wheelCarryRef.current = carry;

        if (steps !== 0) {
            onCommand({ kind: 'resize', steps });
        }
    });

    useEffect(() => {
        const region = regionRef.current;

        if (region === null) {
            return undefined;
        }

        const listener = (event: WheelEvent): void => handleWheel(event);

        region.addEventListener('wheel', listener, { passive: false });

        return () => region.removeEventListener('wheel', listener);
    }, []);

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

        const pointers = pointersRef.current;

        // Un pointeur primaire ouvre une nouvelle séquence : une trace
        // laissée par un geste interrompu sans `pointerup` ne compte plus.
        if (event.isPrimary) {
            pointers.clear();
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (pointers.size > 1) {
            const [first, second] = Array.from(pointers.entries());

            // Un second doigt ouvre le pincement et remplace le glissement ;
            // un troisième n'y entre pas : le pincement retient ses deux
            // doigts, et ne lit qu'eux.
            if (
                pointers.size === 2 &&
                first !== undefined &&
                second !== undefined
            ) {
                const startDistance = spread(first[1], second[1]);

                gestureRef.current =
                    startDistance > 0
                        ? {
                              kind: 'pinch',
                              pointerIds: [first[0], second[0]],
                              startWidth: crop.width,
                              startDistance,
                          }
                        : null;
            }

            return;
        }

        const scale = FRAME_GEOMETRY.masterWidth / bounds.width;
        const corner = cornerAt(event.target);

        if (corner !== null) {
            const point = cropCornerPoint(crop, corner);

            gestureRef.current = {
                kind: 'corner',
                pointerId: event.pointerId,
                corner,
                left: bounds.left,
                top: bounds.top,
                scale,
                offsetX: point.x - (event.clientX - bounds.left) * scale,
                offsetY: point.y - (event.clientY - bounds.top) * scale,
            };

            return;
        }

        gestureRef.current = {
            kind: 'drag',
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: crop.x,
            originY: crop.y,
            scale,
        };
    }

    function handlePointerMove(event: ReactPointerEvent<HTMLDivElement>): void {
        const pointers = pointersRef.current;
        const gesture = gestureRef.current;

        if (!pointers.has(event.pointerId)) {
            return;
        }

        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (gesture === null) {
            return;
        }

        if (gesture.kind === 'pinch') {
            const [firstId, secondId] = gesture.pointerIds;
            const first = pointers.get(firstId);
            const second = pointers.get(secondId);

            if (
                !gesture.pointerIds.includes(event.pointerId) ||
                first === undefined ||
                second === undefined
            ) {
                return;
            }

            const next = cropPinchCommand(
                gesture.startWidth,
                gesture.startDistance,
                spread(first, second),
            );

            if (next !== null) {
                command(next);
            }

            return;
        }

        if (gesture.pointerId !== event.pointerId) {
            return;
        }

        if (gesture.kind === 'corner') {
            command({
                kind: 'resizeCorner',
                corner: gesture.corner,
                x:
                    (event.clientX - gesture.left) * gesture.scale +
                    gesture.offsetX,
                y:
                    (event.clientY - gesture.top) * gesture.scale +
                    gesture.offsetY,
            });

            return;
        }

        command({
            kind: 'moveTo',
            x:
                gesture.originX +
                (event.clientX - gesture.startX) * gesture.scale,
            y:
                gesture.originY +
                (event.clientY - gesture.startY) * gesture.scale,
        });
    }

    function endPointer(event: ReactPointerEvent<HTMLDivElement>): void {
        const pointers = pointersRef.current;
        const gesture = gestureRef.current;

        pointers.delete(event.pointerId);

        if (gesture === null) {
            return;
        }

        // L'un des deux doigts du pincement levé clôt le pincement : aucun
        // doigt restant ne reprend un glissement, ni un pincement avec un
        // troisième doigt, qui feraient sauter le cadre.
        const ended =
            gesture.kind === 'pinch'
                ? gesture.pointerIds.includes(event.pointerId)
                : gesture.pointerId === event.pointerId;

        if (ended) {
            gestureRef.current = null;
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
                        onPointerUp={endPointer}
                        onPointerCancel={endPointer}
                        onLostPointerCapture={endPointer}
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
                    >
                        {/*
                         * Poignées d'angle : un carré visible au token
                         * `primary`, et une zone de saisie élargie par un
                         * pseudo-élément à 2,75 rem de côté — l'équivalent
                         * de `min-h-11 min-w-11` exigé des gestes répétitifs
                         * (§ 13.4), à la mesure d'un doigt.
                         */}
                        {interactive &&
                            CROP_CORNERS.map((corner) => (
                                <div
                                    key={corner}
                                    data-crop-corner={corner}
                                    className={cn(
                                        'absolute size-3 bg-primary before:absolute before:-inset-4',
                                        CORNER_CLASSES[corner],
                                    )}
                                />
                            ))}
                    </div>
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
