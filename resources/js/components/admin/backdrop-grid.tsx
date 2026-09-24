import { useId, useImperativeHandle, useRef, useState } from 'react';
import type { KeyboardEvent, Ref } from 'react';
import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import { gridNavigationTarget } from '@/lib/admin/grid-navigation';
import { FRAME_GEOMETRY } from '@/lib/frame-geometry';
import { cn } from '@/lib/utils';
import type { AdminBackdrop } from '@/types/admin';

/** Ce que l'éditeur peut demander à la grille : lui rendre le focus. */
export type BackdropGridHandle = {
    focus: () => void;
};

type Props = {
    items: AdminBackdrop[];
    /** Le visuel ouvert dans le cadre, par sa référence TMDB. */
    openedPath: string | null;
    onOpen: (backdrop: AdminBackdrop) => void;
    handleRef?: Ref<BackdropGridHandle>;
};

/** Nombre de colonnes réellement rendues par la grille CSS. */
function renderedColumns(list: HTMLElement | null): number {
    if (list === null) {
        return 1;
    }

    const template = window.getComputedStyle(list).gridTemplateColumns;

    return Math.max(1, template.split(' ').filter(Boolean).length);
}

/**
 * La grille des visuels TMDB du film (spec 20 § 6.2 et § 6.4).
 *
 * - **Backdrops seuls**, sans texte d'abord, dans l'ordre que le serveur a
 *   fixé ; un visuel trop étroit ou en portrait reste proposé, **désactivé
 *   avec son motif** — le refus que l'ajout opposerait ;
 * - un visuel déjà utilisé porte le badge TEXTUEL des niveaux des images qui
 *   en proviennent (`admin.bank.backdrop_used`), jamais la seule couleur ;
 * - **un seul arrêt de tabulation** (tabindex itinérant) : les flèches
 *   passent d'une vignette à l'autre (`gridNavigationTarget`, fonction pure),
 *   Origine et Fin aux bords ; Entrée ou Espace ouvre le visuel dans le
 *   cadre — ce sont des boutons natifs. Un visuel désactivé reste
 *   focalisable (`aria-disabled`), pour que son motif se lise.
 *
 * Les vignettes (`w300`) se chargent directement depuis le serveur d'images
 * de TMDB, dans le navigateur du curateur seulement (§ 6.2, AN20-2), sans
 * référent. Aucune couleur ni taille en dur : tokens seulement (règle 5).
 */
export function BackdropGrid({ items, openedPath, onOpen, handleRef }: Props) {
    const { t } = useTranslations();
    const baseId = useId();
    const listRef = useRef<HTMLUListElement>(null);
    const buttonsRef = useRef<Array<HTMLButtonElement | null>>([]);
    const [active, setActive] = useState(0);

    const count = items.length;
    const current = Math.min(active, Math.max(count - 1, 0));

    useImperativeHandle(handleRef, () => ({
        focus: () => buttonsRef.current[current]?.focus(),
    }));

    function handleKeyDown(event: KeyboardEvent<HTMLUListElement>): void {
        const target = gridNavigationTarget(
            event.key,
            current,
            count,
            renderedColumns(listRef.current),
        );

        if (target === null) {
            return;
        }

        event.preventDefault();
        setActive(target);
        buttonsRef.current[target]?.focus();
    }

    return (
        <ul
            ref={listRef}
            aria-label={t('admin.bank.backdrops.list_label')}
            onKeyDown={handleKeyDown}
            className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6"
        >
            {items.map((backdrop, index) => {
                const opened = backdrop.file_path === openedPath;
                const refused = backdrop.refusal !== null;
                const labelId = `${baseId}-${index}-label`;
                const descriptionId = `${baseId}-${index}-description`;

                return (
                    <li key={backdrop.file_path} className="min-w-0">
                        <button
                            ref={(node) => {
                                buttonsRef.current[index] = node;
                            }}
                            type="button"
                            tabIndex={index === current ? 0 : -1}
                            aria-labelledby={labelId}
                            aria-describedby={descriptionId}
                            aria-disabled={refused ? true : undefined}
                            aria-current={opened ? 'true' : undefined}
                            onFocus={() => setActive(index)}
                            onClick={() => {
                                setActive(index);

                                if (!refused) {
                                    onOpen(backdrop);
                                }
                            }}
                            className={cn(
                                'flex min-h-11 w-full flex-col gap-2 rounded-md border bg-card p-1.5 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                                opened
                                    ? 'border-primary ring-1 ring-primary'
                                    : 'border-border',
                                refused
                                    ? 'cursor-not-allowed'
                                    : 'cursor-pointer hover:bg-accent',
                            )}
                        >
                            <span
                                className={cn(
                                    'relative block aspect-frame w-full overflow-hidden rounded-sm bg-muted',
                                    refused && 'opacity-50',
                                )}
                            >
                                <img
                                    id={labelId}
                                    src={backdrop.thumb_url}
                                    alt={t('admin.bank.backdrop_alt', {
                                        index: index + 1,
                                        count,
                                    })}
                                    width={backdrop.width}
                                    height={backdrop.height}
                                    loading="lazy"
                                    decoding="async"
                                    draggable={false}
                                    referrerPolicy="no-referrer"
                                    className="size-full object-contain"
                                />
                            </span>
                            <span
                                id={descriptionId}
                                className="flex flex-wrap items-center gap-1"
                            >
                                {opened && (
                                    <Badge>
                                        {t('admin.bank.backdrop_opened')}
                                    </Badge>
                                )}
                                {backdrop.used_levels.length > 0 && (
                                    <Badge variant="secondary">
                                        {t('admin.bank.backdrop_used', {
                                            levels: backdrop.used_levels.join(
                                                t(
                                                    'admin.common.list_separator',
                                                ),
                                            ),
                                        })}
                                    </Badge>
                                )}
                                {!backdrop.language_neutral && (
                                    <Badge variant="outline">
                                        {t('admin.bank.backdrop_with_language')}
                                    </Badge>
                                )}
                                {backdrop.refusal !== null && (
                                    <span className="text-xs text-destructive">
                                        {t(backdrop.refusal, {
                                            width: FRAME_GEOMETRY.gameWidth,
                                        })}
                                    </span>
                                )}
                            </span>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
