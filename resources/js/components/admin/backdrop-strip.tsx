import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import type {
    KeyboardEvent,
    MouseEvent as ReactMouseEvent,
    PointerEvent as ReactPointerEvent,
} from 'react';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useThroughputShortcuts } from '@/hooks/admin/use-throughput-shortcuts';
import { useTranslations } from '@/hooks/use-translations';
import {
    isInStrip,
    stripNeighbour,
    stripPosition,
    swipeDirection,
} from '@/lib/admin/backdrop-strip';
import type { StripDirection } from '@/lib/admin/backdrop-strip';
import { gridNavigationTarget } from '@/lib/admin/grid-navigation';
import type { ShortcutContext } from '@/lib/admin/shortcut-map';
import { cn } from '@/lib/utils';
import type { AdminBackdrop } from '@/types/admin';

type Props = {
    /** Tous les visuels de la grille, dans son ordre : la bande en extrait les siens. */
    items: AdminBackdrop[];
    /** Le visuel ouvert dans le cadre, par sa référence TMDB. */
    openedPath: string | null;
    /** Un envoi est en cours : la bande ne change pas le cadre jusqu'à la réponse. */
    disabled: boolean;
    /** Ouvrir une vignette de la bande dans le cadre. */
    onOpen: (backdrop: AdminBackdrop) => void;
    /**
     * Passer au visuel voisin : boutons, glissement, `[` et `]`. Rend le
     * visuel ouvert, ou `null` au bord de la bande.
     */
    onStep: (direction: StripDirection) => AdminBackdrop | null;
};

/** Le point où un glissement a commencé, pour quel pointeur. */
type SwipeStart = {
    pointerId: number;
    x: number;
    y: number;
};

/**
 * La bande balayable (spec 20 § 6.2 et § 6.3, lot L20-11) : sous le
 * recadreur, la rangée horizontale des visuels du film **non encore
 * utilisés**, dans l'ordre de la grille.
 *
 * - `[` et `]`, un **glissement** parti d'une vignette (au doigt comme à la
 *   souris ; jamais la barre de défilement de la rangée, qui la fait défiler)
 *   ou les boutons « précédent » / « suivant » passent au visuel voisin
 *   **sans quitter le cadre** : le visuel s'y ouvre, et le focus reste où il
 *   est — sur le bouton, ou sur la vignette du visuel ouvert.
 * - **Un seul arrêt de tabulation** dans la rangée (tabindex itinérant) : les
 *   flèches passent d'une vignette à l'autre, Entrée ou Espace ouvre la
 *   vignette dans le cadre — ce sont des boutons natifs.
 * - Le visuel ouvert reste visible dans la rangée, qui défile d'elle-même
 *   jusqu'à lui ; il porte le badge textuel « Ouvert dans le cadre ».
 *
 * Tous les choix — ce qui est dans la bande, le voisin, la valeur d'un
 * glissement — sont des fonctions pures (`lib/admin/backdrop-strip.ts`). La
 * grille reste l'autre voie d'accès aux mêmes visuels, au clavier comme à la
 * souris. Aucune couleur ni taille en dur : tokens seulement (règle 5).
 */
export function BackdropStrip({
    items,
    openedPath,
    disabled,
    onOpen,
    onStep,
}: Props) {
    const { t } = useTranslations();
    const baseId = useId();
    const headingId = `${baseId}-heading`;
    const descriptionId = `${baseId}-description`;

    const listRef = useRef<HTMLUListElement>(null);
    const itemsRef = useRef(new Map<string, HTMLLIElement>());
    const buttonsRef = useRef(new Map<string, HTMLButtonElement>());
    const swipeRef = useRef<SwipeStart | null>(null);
    /** Un glissement vient d'aboutir : le clic qui le suit n'ouvre rien. */
    const swipedRef = useRef(false);

    const [active, setActive] = useState<string | null>(null);

    const strip = items.flatMap((backdrop, gridIndex) =>
        isInStrip(backdrop) ? [{ backdrop, gridIndex }] : [],
    );
    const position = stripPosition(items, openedPath);
    const canPrevious =
        !disabled && stripNeighbour(items, openedPath, 'previous') !== null;
    const canNext =
        !disabled && stripNeighbour(items, openedPath, 'next') !== null;

    // L'arrêt de tabulation : la dernière vignette focalisée si elle est
    // encore dans la bande, sinon le visuel ouvert, sinon la première.
    const tabStop =
        strip.find(({ backdrop }) => backdrop.file_path === active)?.backdrop
            .file_path ??
        strip.find(({ backdrop }) => backdrop.file_path === openedPath)
            ?.backdrop.file_path ??
        strip[0]?.backdrop.file_path ??
        null;

    // La rangée défile jusqu'au visuel ouvert, sans animation et sans faire
    // défiler la page.
    useEffect(() => {
        const list = listRef.current;
        const item =
            openedPath === null ? undefined : itemsRef.current.get(openedPath);

        if (list === null || item === undefined) {
            return;
        }

        const left = item.offsetLeft;
        const right = left + item.offsetWidth;

        if (
            left < list.scrollLeft ||
            right > list.scrollLeft + list.clientWidth
        ) {
            list.scrollTo({
                left: left - (list.clientWidth - item.offsetWidth) / 2,
            });
        }
    }, [openedPath]);

    const context: ShortcutContext = {
        screen: 'cropper',
        canSend: false,
        busy: disabled,
    };

    /*
     * `[` et `]` depuis une vignette : le focus suit le visuel ouvert, pour
     * qu'un second pas parte de lui. Depuis un bouton, il reste sur le
     * bouton, qu'un second clic rejoue.
     */
    const listShortcuts = useThroughputShortcuts(context, {
        onNeighbour: (direction) => {
            const opened = onStep(direction);

            if (opened !== null) {
                buttonsRef.current.get(opened.file_path)?.focus();
            }
        },
    });
    const buttonShortcuts = useThroughputShortcuts(context, {
        onNeighbour: (direction) => {
            onStep(direction);
        },
    });

    function handleListKeyDown(event: KeyboardEvent<HTMLUListElement>): void {
        // La vignette focalisée ; à défaut, l'arrêt de tabulation.
        const focused = strip.findIndex(
            ({ backdrop }) =>
                buttonsRef.current.get(backdrop.file_path) === event.target,
        );
        const current =
            focused !== -1
                ? focused
                : strip.findIndex(
                      ({ backdrop }) => backdrop.file_path === tabStop,
                  );
        // Une seule rangée : autant de colonnes que de vignettes, les flèches
        // verticales n'y bougent rien.
        const target = gridNavigationTarget(
            event.key,
            Math.max(current, 0),
            strip.length,
            strip.length,
        );

        if (target === null) {
            listShortcuts(event);

            return;
        }

        event.preventDefault();

        const path = strip[target]?.backdrop.file_path;

        if (path !== undefined) {
            setActive(path);
            buttonsRef.current.get(path)?.focus();
        }
    }

    function handlePointerDown(
        event: ReactPointerEvent<HTMLUListElement>,
    ): void {
        swipedRef.current = false;

        if (!event.isPrimary || event.button !== 0) {
            swipeRef.current = null;

            return;
        }

        // Un appui sur la rangée elle-même — sa barre de défilement, ses
        // marges, ses espaces — est un défilement, jamais un glissement : tirer
        // la barre ne doit ni changer le visuel du cadre ni perdre son travail.
        if (event.target === event.currentTarget) {
            swipeRef.current = null;

            return;
        }

        swipeRef.current = {
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
        };
    }

    function handlePointerUp(event: ReactPointerEvent<HTMLUListElement>): void {
        const start = swipeRef.current;

        swipeRef.current = null;

        if (start === null || start.pointerId !== event.pointerId || disabled) {
            return;
        }

        const direction = swipeDirection(
            event.clientX - start.x,
            event.clientY - start.y,
        );

        if (direction !== null) {
            swipedRef.current = true;
            onStep(direction);
        }
    }

    /*
     * Le clic qui suit un glissement abouti n'ouvre pas la vignette sous le
     * doigt : le glissement a déjà changé le visuel. Un clic venu du clavier
     * (`detail` nul) n'est jamais retenu.
     */
    function handleClickCapture(
        event: ReactMouseEvent<HTMLUListElement>,
    ): void {
        if (swipedRef.current && event.detail > 0) {
            event.preventDefault();
            event.stopPropagation();
        }

        swipedRef.current = false;
    }

    return (
        <section
            aria-labelledby={headingId}
            aria-describedby={descriptionId}
            className="flex flex-col gap-2"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 id={headingId} className="text-sm font-semibold">
                    {t('admin.bank.strip.heading')}
                </h3>
                <p className="text-sm text-muted-foreground">
                    {position !== null
                        ? t('admin.bank.strip.position', {
                              index: position.index,
                              count: position.count,
                          })
                        : openedPath !== null && strip.length > 0
                          ? t('admin.bank.strip.outside')
                          : null}
                </p>
            </div>
            <p id={descriptionId} className="text-xs text-muted-foreground">
                {t('admin.bank.strip.description')}
            </p>

            {strip.length === 0 ? (
                <AdminEmptyState
                    title={t('admin.bank.strip.empty')}
                    className="py-6"
                />
            ) : (
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('admin.bank.strip.previous')}
                        aria-disabled={canPrevious ? undefined : true}
                        aria-keyshortcuts="["
                        onClick={() => {
                            if (canPrevious) {
                                onStep('previous');
                            }
                        }}
                        onKeyDown={buttonShortcuts}
                        className="min-h-11 min-w-11 shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                    >
                        <ChevronLeftIcon aria-hidden />
                    </Button>

                    <ul
                        ref={listRef}
                        aria-label={t('admin.bank.strip.list_label')}
                        onKeyDown={handleListKeyDown}
                        onPointerDown={handlePointerDown}
                        onPointerUp={handlePointerUp}
                        onPointerCancel={() => {
                            swipeRef.current = null;
                        }}
                        onClickCapture={handleClickCapture}
                        className="relative flex min-w-0 flex-1 touch-pan-y gap-2 overflow-x-auto py-1 select-none"
                    >
                        {strip.map(({ backdrop, gridIndex }) => {
                            const path = backdrop.file_path;
                            const opened = path === openedPath;

                            return (
                                <li
                                    key={path}
                                    ref={(node) => {
                                        if (node === null) {
                                            itemsRef.current.delete(path);
                                        } else {
                                            itemsRef.current.set(path, node);
                                        }
                                    }}
                                    className="w-36 shrink-0"
                                >
                                    <button
                                        ref={(node) => {
                                            if (node === null) {
                                                buttonsRef.current.delete(path);
                                            } else {
                                                buttonsRef.current.set(
                                                    path,
                                                    node,
                                                );
                                            }
                                        }}
                                        type="button"
                                        tabIndex={path === tabStop ? 0 : -1}
                                        aria-current={
                                            opened ? 'true' : undefined
                                        }
                                        aria-disabled={
                                            disabled ? true : undefined
                                        }
                                        onFocus={() => setActive(path)}
                                        onClick={() => {
                                            setActive(path);

                                            if (!disabled && !opened) {
                                                onOpen(backdrop);
                                            }
                                        }}
                                        className={cn(
                                            'flex min-h-11 w-full flex-col gap-1 rounded-md border bg-card p-1 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                                            opened
                                                ? 'border-primary ring-1 ring-primary'
                                                : 'border-border',
                                            disabled
                                                ? 'cursor-not-allowed'
                                                : 'cursor-pointer hover:bg-accent',
                                        )}
                                    >
                                        <span className="relative block aspect-frame w-full overflow-hidden rounded-sm bg-muted">
                                            <img
                                                src={backdrop.thumb_url}
                                                alt={t(
                                                    'admin.bank.backdrop_alt',
                                                    {
                                                        index: gridIndex + 1,
                                                        count: items.length,
                                                    },
                                                )}
                                                width={backdrop.width}
                                                height={backdrop.height}
                                                loading="lazy"
                                                decoding="async"
                                                draggable={false}
                                                referrerPolicy="no-referrer"
                                                className="size-full object-contain"
                                            />
                                        </span>
                                        {opened && (
                                            <Badge className="self-start">
                                                {t(
                                                    'admin.bank.backdrop_opened',
                                                )}
                                            </Badge>
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>

                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('admin.bank.strip.next')}
                        aria-disabled={canNext ? undefined : true}
                        aria-keyshortcuts="]"
                        onClick={() => {
                            if (canNext) {
                                onStep('next');
                            }
                        }}
                        onKeyDown={buttonShortcuts}
                        className="min-h-11 min-w-11 shrink-0 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                    >
                        <ChevronRightIcon aria-hidden />
                    </Button>
                </div>
            )}
        </section>
    );
}
