import { Head, Link, router } from '@inertiajs/react';
import { CropIcon, EyeOffIcon, ListChecksIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { toast } from 'sonner';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import type { CoverageWarning } from '@/components/admin/frame-gesture-dialog';
import { FRAME_LEVEL_KEYS } from '@/components/admin/level-picker';
import { ReviewBatchButton } from '@/components/admin/review-batch-button';
import {
    failedItemLabels,
    REVIEW_WRITE_PROPS,
    ReviewPanel,
} from '@/components/admin/review-panel';
import type { ReviewOutcome } from '@/components/admin/review-panel';
import { ReviewUnpublishDialog } from '@/components/admin/review-unpublish-dialog';
import type { ReviewUnpublishTarget } from '@/components/admin/review-unpublish-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurationHeartbeat } from '@/hooks/admin/use-curation-heartbeat';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import { dashboard as adminDashboard } from '@/routes/admin';
import { bank } from '@/routes/admin/catalog';
import { index as reviewIndex } from '@/routes/admin/review';
import type {
    AdminQueueReviewBatch,
    AdminReviewFrame,
    AdminReviewGroup,
    AdminReviewList,
    AdminReviewMovie,
    AdminReviewQueue,
    AdminUnpublishPreview,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    queue: AdminReviewQueue;
    /**
     * Les vrais totaux de chaque liste : `queue` n'en porte que les premiers
     * films (`FrameReviewQueueController::MOVIES_PER_LIST`).
     */
    queue_totals: Record<AdminReviewList, { movies: number; frames: number }>;
    /**
     * Les lots à valider en une fois, un par film qui en a un (D42 du 30/09,
     * spec 20 § 7.9).
     */
    review_batches: AdminQueueReviewBatch[];
    /** Prop facultative : servie au seul rechargement qui la demande. */
    unpublish_preview?: AdminUnpublishPreview | null;
    /** Cadence du battement de débit (`catalog.curation.heartbeat_seconds`). */
    heartbeat_seconds: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.review', href: reviewIndex() },
];

/** Les trois listes, dans l'ordre des onglets (spec 20 § 7.3). */
const LISTS: AdminReviewList[] = ['to_review', 'to_rereview', 'rejected'];

/** Les clés de chaque liste : onglet, description, état vide. */
const LIST_KEYS: Record<
    AdminReviewList,
    { tab: TranslationKey; description: TranslationKey; empty: TranslationKey }
> = {
    to_review: {
        tab: 'admin.review.tabs.to_review',
        description: 'admin.review.lists.to_review.description',
        empty: 'admin.review.lists.to_review.empty',
    },
    to_rereview: {
        tab: 'admin.review.tabs.to_rereview',
        description: 'admin.review.lists.to_rereview.description',
        empty: 'admin.review.lists.to_rereview.empty',
    },
    rejected: {
        tab: 'admin.review.tabs.rejected',
        description: 'admin.review.lists.rejected.description',
        empty: 'admin.review.lists.rejected.empty',
    },
};

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-review-offline';

/** Identifiant du toast d'un refus dont le panneau a disparu. */
const REFUSAL_TOAST_ID = 'admin-review-refused';

/** Paramètre du rechargement qui demande l'avertissement de couverture. */
const PREVIEW_FRAME_PARAMETER = 'preview_frame';

/** Une image de la liste, avec son film. */
type Entry = {
    frame: AdminReviewFrame;
    movie: AdminReviewMovie;
};

/** L'avertissement de couverture demandé, pour quelle image. */
type WarningRequest = {
    frameId: number;
    status: 'loading' | 'ready' | 'failed';
};

/** Un refus serveur, pour quel panneau : ses messages, en attente d'affichage. */
type Refusal = {
    panelKey: string;
    messages: string[];
};

function isReviewList(value: string): value is AdminReviewList {
    return (LISTS as string[]).includes(value);
}

/**
 * L'identité d'un panneau de revue : l'image ET ses octets. Un rendu neuf
 * (re-recadrage terminé ailleurs) remonte le panneau — image rechargée par
 * sa nouvelle adresse, choix « Non conforme » remis à zéro — plutôt que de
 * laisser l'ancien rendu à l'écran sous la nouvelle empreinte.
 */
function panelKeyOf(frame: AdminReviewFrame): string {
    return `${frame.id}:${frame.published_hash}`;
}

function entriesOf(groups: AdminReviewGroup[]): Entry[] {
    return groups.flatMap((group) =>
        group.frames.map((frame) => ({ frame, movie: group.movie })),
    );
}

/**
 * La passe de revue (spec 20 § 7.3 à § 7.6, lot L20-12).
 *
 * Trois onglets — « À revoir », « À re-revoir », « Rejetées » —, chacun sa
 * liste regroupée par film, et l'écran de revue d'UNE image à côté : son
 * rendu final dans le cadre sombre du jeu, le film, le niveau, la source
 * déclarée et les items de la grille applicables à ce niveau.
 *
 * - Après un envoi réussi, **l'image suivante prend le focus** ; une liste
 *   vidée rend le focus à la liste elle-même.
 * - Une image **en jeu** rejetée enchaîne sur la confirmation de sa
 *   dépublication, motif pré-rempli par les points en défaut ; la refuser
 *   la laisse en jeu, et « Rejetées » l'affiche jusqu'à décision (§ 7.5).
 * - Dans « Rejetées », chaque image offre « Revoir » (la sélectionner), un
 *   lien vers sa banque pour la re-recadrer, et « Écarter » ou « Dépublier ».
 * - La revue demande un écran large : sous `lg`, la zone le dit et les
 *   listes restent utilisables (§ 6.1).
 * - Une déconnexion pendant un envoi laisse la revue telle quelle et se
 *   signale par un toast (§ 13.5).
 * - Le temps passé à revoir une image compte dans le temps actif de son film
 *   (§ 10.1) : un battement après chaque saisie, pour le film de l'image
 *   affichée.
 * - Le panneau a pour identité l'image ET son empreinte : un rendu neuf le
 *   remonte, image rechargée. Un refus s'affiche sous le formulaire ; si le
 *   rechargement de la file a remplacé le panneau, il passe par un toast.
 */
export default function AdminReviewIndex({
    queue,
    queue_totals,
    review_batches,
    unpublish_preview,
    heartbeat_seconds,
}: Props) {
    const { t } = useTranslations();
    const listRef = useRef<HTMLDivElement>(null);
    const dialogTriggerRef = useRef<HTMLElement | null>(null);

    const [tab, setTab] = useState<AdminReviewList>('to_review');
    const [selected, setSelected] = useState<
        Record<AdminReviewList, number | null>
    >({ to_review: null, to_rereview: null, rejected: null });
    const [focusPanel, setFocusPanel] = useState(false);
    const [dialog, setDialog] = useState<ReviewUnpublishTarget | null>(null);
    const [warningRequest, setWarningRequest] = useState<WarningRequest | null>(
        null,
    );
    const [refusal, setRefusal] = useState<Refusal | null>(null);

    const entries = entriesOf(queue[tab]);
    const current =
        entries.find((entry) => entry.frame.id === selected[tab]) ??
        entries[0] ??
        null;
    const currentKey = current === null ? null : panelKeyOf(current.frame);

    // Le temps actif de curation du film de l'image revue (spec 20 § 10.1,
    // lot L20-17) : la revue d'une image est une page de son film.
    useCurationHeartbeat(current?.movie.id ?? null, heartbeat_seconds);

    // Déconnexion ou erreur réseau d'une visite : rien n'est parti, la
    // revue reste telle quelle, et le curateur l'apprend (§ 13.5).
    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    // Une liste vidée par l'envoi : le focus revient à la liste, jamais au
    // document.
    useEffect(() => {
        if (focusPanel && current === null) {
            listRef.current?.focus();
            setFocusPanel(false);
        }
    }, [focusPanel, current]);

    // Un refus dont le panneau a été remplacé par le rechargement de la
    // file — image sortie de la liste (verrouillée, jugée dans un autre
    // onglet) ou rendue sous une autre empreinte — perdrait son message avec
    // lui : il passe par un toast, et le focus, perdu avec le bouton
    // d'envoi, revient à l'écran de revue ou à la liste (§ 13.1, § 13.5).
    // Un panneau resté en place l'affiche lui-même, sous son formulaire.
    useEffect(() => {
        if (refusal !== null && refusal.panelKey !== currentKey) {
            const [message, ...details] = refusal.messages;

            toast.error(message, {
                id: REFUSAL_TOAST_ID,
                description: details.length > 0 ? details.join(' ') : undefined,
            });
            setRefusal(null);
            setFocusPanel(true);
        }
    }, [refusal, currentKey]);

    function select(list: AdminReviewList, frameId: number): void {
        setRefusal(null);
        setSelected((state) => ({ ...state, [list]: frameId }));
    }

    function handleRefused(entry: Entry, messages: string[]): void {
        if (messages.length > 0) {
            setRefusal({ panelKey: panelKeyOf(entry.frame), messages });
        }
    }

    /*
     * Appelé avec la liste telle qu'elle était À L'ENVOI : l'image suivante
     * est celle qui suivait l'image revue, ou la précédente si elle était
     * la dernière.
     */
    function handleReviewed(entry: Entry, outcome: ReviewOutcome): void {
        const index = entries.findIndex(
            (item) => item.frame.id === entry.frame.id,
        );
        const next = entries[index + 1] ?? entries[index - 1] ?? null;

        setRefusal(null);
        setSelected((state) => ({ ...state, [tab]: next?.frame.id ?? null }));

        if (outcome.rejectedInPlay) {
            dialogTriggerRef.current = null;
            openDialog(entry.frame, outcome.failedSlugs, true);

            return;
        }

        setFocusPanel(true);
    }

    function requestWarning(frameId: number): void {
        setWarningRequest({ frameId, status: 'loading' });

        const settle = (status: WarningRequest['status']): void =>
            setWarningRequest((state) =>
                state?.frameId === frameId ? { frameId, status } : state,
            );

        router.reload({
            only: ['unpublish_preview'],
            data: { [PREVIEW_FRAME_PARAMETER]: frameId },
            preserveUrl: true,
            onSuccess: () => settle('ready'),
            onHttpException: () => settle('failed'),
            onNetworkError: () => settle('failed'),
        });
    }

    function openDialog(
        frame: AdminReviewFrame,
        failedSlugs: string[],
        afterRejection: boolean,
    ): void {
        // Un geste de la boîte recharge la file : un refus antérieur, resté
        // affiché sous son formulaire, ne doit pas ressortir en toast.
        setRefusal(null);
        setDialog({
            frame,
            failedSlugs,
            afterRejection,
            openedAtMs: performance.now(),
        });

        // Seule une image en jeu peut, en sortant du jeu, casser la
        // couverture d'un film publié : l'avertissement n'est demandé que
        // pour elle, avant tout envoi.
        if (frame.availability === 'published') {
            requestWarning(frame.id);
        } else {
            setWarningRequest(null);
        }
    }

    function openDialogFromList(frame: AdminReviewFrame): void {
        dialogTriggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        openDialog(frame, frame.failed_items, false);
    }

    /*
     * À la fermeture, le focus revient au bouton qui a ouvert la boîte. Une
     * dépublication réussie peut l'avoir fait disparaître ; une boîte
     * ouverte d'elle-même après un rejet n'en a pas : il revient alors à
     * l'écran de revue, ou à la liste.
     */
    function returnDialogFocus(): void {
        const trigger = dialogTriggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            setFocusPanel(true);
        }
    }

    const warning: CoverageWarning =
        dialog === null ||
        warningRequest === null ||
        warningRequest.frameId !== dialog.frame.id
            ? { status: 'ready', preview: null }
            : warningRequest.status === 'ready'
              ? {
                    status: 'ready',
                    preview:
                        unpublish_preview?.frame_id === dialog.frame.id
                            ? unpublish_preview
                            : null,
                }
              : { status: warningRequest.status };

    return (
        <>
            <Head title={t('admin.review.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.review.heading')}
                    description={t('admin.review.description')}
                />

                <Tabs
                    value={tab}
                    onValueChange={(value) => {
                        if (isReviewList(value)) {
                            setRefusal(null);
                            setTab(value);
                        }
                    }}
                    className="w-full"
                >
                    <div className="overflow-x-auto">
                        <TabsList aria-label={t('admin.review.tabs.label')}>
                            {LISTS.map((list) => (
                                <TabsTrigger
                                    key={list}
                                    value={list}
                                    className="min-h-11 px-3"
                                >
                                    {t(LIST_KEYS[list].tab, {
                                        count: queue_totals[list].frames,
                                    })}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                    </div>

                    {LISTS.map((list) => (
                        <TabsContent
                            key={list}
                            value={list}
                            className="flex flex-col gap-4 pt-2"
                        >
                            <p className="max-w-prose text-sm text-muted-foreground">
                                {t(LIST_KEYS[list].description)}
                            </p>

                            {queue_totals[list].movies > queue[list].length && (
                                <p className="max-w-prose text-sm text-muted-foreground">
                                    {t('admin.review.truncated', {
                                        shown: queue[list].length,
                                        total: queue_totals[list].movies,
                                    })}
                                </p>
                            )}

                            {list === tab && (
                                <div className="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                                    <div
                                        ref={listRef}
                                        tabIndex={-1}
                                        className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {queue[list].length === 0 ? (
                                            <AdminEmptyState
                                                icon={ListChecksIcon}
                                                title={t(LIST_KEYS[list].empty)}
                                            />
                                        ) : (
                                            <QueueList
                                                list={list}
                                                groups={queue[list]}
                                                batches={review_batches}
                                                listRef={listRef}
                                                currentId={
                                                    current?.frame.id ?? null
                                                }
                                                onSelect={(frameId) =>
                                                    select(list, frameId)
                                                }
                                                onUnpublish={openDialogFromList}
                                            />
                                        )}
                                    </div>

                                    {current !== null && (
                                        <div className="min-w-0">
                                            <p className="text-sm text-muted-foreground lg:hidden">
                                                {t(
                                                    'admin.review.desktop_required',
                                                )}
                                            </p>
                                            <div className="hidden lg:block">
                                                <ReviewPanel
                                                    key={panelKeyOf(
                                                        current.frame,
                                                    )}
                                                    frame={current.frame}
                                                    movie={current.movie}
                                                    autoFocus={focusPanel}
                                                    onFocused={() =>
                                                        setFocusPanel(false)
                                                    }
                                                    onReviewed={(outcome) =>
                                                        handleReviewed(
                                                            current,
                                                            outcome,
                                                        )
                                                    }
                                                    onRefused={(messages) =>
                                                        handleRefused(
                                                            current,
                                                            messages,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </TabsContent>
                    ))}
                </Tabs>
            </div>

            <ReviewUnpublishDialog
                target={dialog}
                warning={warning}
                onRetryWarning={() => {
                    if (dialog !== null) {
                        requestWarning(dialog.frame.id);
                    }
                }}
                onClose={() => setDialog(null)}
                onReturnFocus={returnDialogFocus}
            />
        </>
    );
}

AdminReviewIndex.layout = { breadcrumbs };

/**
 * Une liste de la file, regroupée par film (§ 7.3). Chaque image est un
 * bouton qui l'ouvre dans l'écran de revue ; dans « Rejetées », elle porte
 * ses points en défaut et ses gestes.
 */
function QueueList({
    list,
    groups,
    batches,
    listRef,
    currentId,
    onSelect,
    onUnpublish,
}: {
    list: AdminReviewList;
    groups: AdminReviewGroup[];
    batches: AdminQueueReviewBatch[];
    listRef: RefObject<HTMLDivElement | null>;
    currentId: number | null;
    onSelect: (frameId: number) => void;
    onUnpublish: (frame: AdminReviewFrame) => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            {groups.map((group) => (
                <MovieGroup
                    key={group.movie.id}
                    list={list}
                    group={group}
                    batch={
                        list === 'rejected'
                            ? null
                            : (batches.find(
                                  (batch) => batch.movie_id === group.movie.id,
                              ) ?? null)
                    }
                    listRef={listRef}
                    currentId={currentId}
                    onSelect={onSelect}
                    onUnpublish={onUnpublish}
                />
            ))}
        </div>
    );
}

function MovieGroup({
    list,
    group,
    batch,
    listRef,
    currentId,
    onSelect,
    onUnpublish,
}: {
    list: AdminReviewList;
    group: AdminReviewGroup;
    /** Le lot du film, hors de « Rejetées » ; `null` s'il n'y en a pas. */
    batch: AdminQueueReviewBatch | null;
    listRef: RefObject<HTMLDivElement | null>;
    currentId: number | null;
    onSelect: (frameId: number) => void;
    onUnpublish: (frame: AdminReviewFrame) => void;
}) {
    const { t } = useTranslations();
    const headingId = useId();
    const { movie } = group;

    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-2 rounded-lg border border-border bg-card p-3 text-card-foreground"
        >
            <h2
                id={headingId}
                className="flex flex-wrap items-baseline gap-x-2 text-sm font-semibold"
            >
                <span>{movie.title_original}</span>
                {movie.release_year !== null && (
                    <span className="font-normal text-muted-foreground">
                        {movie.release_year}
                    </span>
                )}
            </h2>

            {/*
             * Valider en lot les images du film en attente (D42 du 30/09,
             * § 7.9) : celles des deux premières listes, jamais un rejet.
             */}
            {batch !== null && (
                <div>
                    <ReviewBatchButton
                        movieId={movie.id}
                        movieTitle={movie.title_original}
                        batch={batch}
                        only={REVIEW_WRITE_PROPS}
                        fallbackFocusRef={listRef}
                        size="sm"
                    />
                </div>
            )}

            <ul
                aria-label={t('admin.review.list.label', {
                    title: movie.title_original,
                })}
                className="flex flex-col gap-2"
            >
                {group.frames.map((frame) => (
                    <QueueItem
                        key={frame.id}
                        list={list}
                        frame={frame}
                        movie={movie}
                        current={frame.id === currentId}
                        onSelect={onSelect}
                        onUnpublish={onUnpublish}
                    />
                ))}
            </ul>
        </section>
    );
}

function QueueItem({
    list,
    frame,
    movie,
    current,
    onSelect,
    onUnpublish,
}: {
    list: AdminReviewList;
    frame: AdminReviewFrame;
    movie: AdminReviewMovie;
    current: boolean;
    onSelect: (frameId: number) => void;
    onUnpublish: (frame: AdminReviewFrame) => void;
}) {
    const { t } = useTranslations();
    const failed = failedItemLabels(frame.items, frame.failed_items, t);
    const unpublishable =
        frame.availability === 'published' || frame.availability === 'draft';

    return (
        <li className="flex flex-col gap-2">
            <Button
                type="button"
                variant={current ? 'secondary' : 'ghost'}
                aria-current={current ? 'true' : undefined}
                onClick={() => onSelect(frame.id)}
                className={cn(
                    'h-auto min-h-11 w-full flex-col items-start gap-1 px-3 py-2 text-left whitespace-normal',
                    current && 'ring-1 ring-ring',
                )}
            >
                <span className="font-medium">
                    {t('admin.review.list.frame', {
                        level: frame.frame_level,
                        label: t(FRAME_LEVEL_KEYS[frame.frame_level].label),
                    })}
                </span>
                <span className="flex flex-wrap gap-1">
                    {current && (
                        <Badge variant="outline">
                            {t('admin.review.list.current')}
                        </Badge>
                    )}
                    {frame.availability === 'published' && (
                        <Badge variant="default">
                            {t('admin.review.list.in_play')}
                        </Badge>
                    )}
                </span>
                {failed.length > 0 && (
                    <span className="text-xs font-normal text-destructive">
                        {t('admin.review.list.failed', {
                            items: failed.join(
                                t('admin.common.list_separator'),
                            ),
                        })}
                    </span>
                )}
            </Button>

            {list === 'rejected' && (
                <div
                    role="group"
                    aria-label={t('admin.review.list.actions', {
                        level: frame.frame_level,
                        title: movie.title_original,
                    })}
                    className="flex flex-wrap gap-2 pl-3"
                >
                    <Button
                        variant="outline"
                        size="sm"
                        className="min-h-11"
                        asChild
                    >
                        <Link href={bank(movie.id)}>
                            <CropIcon aria-hidden />
                            {t('admin.review.list.recrop')}
                        </Link>
                    </Button>
                    {unpublishable && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11"
                            onClick={() => onUnpublish(frame)}
                        >
                            <EyeOffIcon aria-hidden />
                            {frame.availability === 'published'
                                ? t('admin.review.list.unpublish')
                                : t('admin.review.list.set_aside')}
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}
