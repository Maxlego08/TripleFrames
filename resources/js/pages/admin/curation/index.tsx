import { Form, Head, Link, router } from '@inertiajs/react';
import { ClapperboardIcon, ListOrderedIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useState } from 'react';
import { toast } from 'sonner';
import CurationQueueController from '@/actions/App/Http/Controllers/Admin/CurationQueueController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import {
    ContentFlagBadge,
    ExceptionBadge,
} from '@/components/admin/admin-badges';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import type { AdminSelectOption } from '@/components/admin/admin-select';
import { AdminSelect } from '@/components/admin/admin-select';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { curationQuery, hasCurationFilters } from '@/lib/admin/curation-query';
import {
    CONTENT_FLAG_KEYS,
    CURATION_ENTRY_KEYS,
    EXCEPTION_MOTIVE_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { bank, show as catalogShow } from '@/routes/admin/catalog';
import {
    index as curationIndex,
    next as curationNext,
} from '@/routes/admin/curation';
import { index as importIndex } from '@/routes/admin/import';
import type {
    AdminCurationEntry,
    AdminCurationFilters,
    AdminCurationOptions,
    AdminCurationQueueRow,
    AdminCurationTotals,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    movies: Paginated<AdminCurationQueueRow>;
    filters: AdminCurationFilters;
    totals: AdminCurationTotals;
    options: AdminCurationOptions;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.curation', href: curationIndex() },
];

/** Les deux strates du lot pilote, dans l'ordre de la composition. */
const ENTRIES: AdminCurationEntry[] = ['discover', 'exception'];

/** Les libellés des tuiles « reste à curer ». */
const TOTAL_KEYS: Record<AdminCurationEntry, TranslationKey> = {
    discover: 'admin.curation.totals.discover',
    exception: 'admin.curation.totals.exception',
};

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-curation-offline';

/** Le nombre de niveaux de l'échelle `frame_level` — affiché en `n / 5`. */
const LEVELS_SCALE = 5;

/**
 * La file de curation (spec 20 § 4.1, lot L20-15) — **lecture seule**,
 * entièrement pilotée par l'URL.
 *
 * L'ordre est celui du serveur ({@link CurationQueueController}) : d'abord les
 * films entamés, du plus récemment touché au plus ancien — la reprise après
 * une interruption est naturelle, l'état est en base —, puis les autres par
 * votes décroissants. Les filtres par voie d'entrée composent le lot pilote,
 * stratifié par voie (§ 10.3) ; ils ne changent jamais l'ordre.
 *
 * « Film suivant » ouvre l'éditeur du premier film de la file, filtres
 * conservés ; l'éditeur, à son tour, enchaîne sur le film d'après. File vide :
 * le serveur ramène ici, avec `admin.curation.empty` et un lien vers l'import.
 *
 * États (§ 13.5) : attente d'une visite (attribut `aria-busy` et annonce
 * polie, sans démonter le tableau, pour que le focus survive), file vide ou
 * filtrée vide (message et sortie utile), déconnexion (toast
 * `admin.common.offline`, la sélection reste dans l'URL).
 */
export default function AdminCurationIndex({
    movies,
    filters,
    totals,
    options,
}: Props) {
    const { t, locale } = useTranslations();
    const filtered = hasCurationFilters(filters);
    const [busy, setBusy] = useState(false);

    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => {
        // `router.on` est GLOBAL : sans ce filtre, ouvrir l'éditeur d'un film
        // ferait clignoter l'attente de cet écran avant même de le quitter.
        const here = curationIndex().url;
        const concerns = (visited: URL): boolean => visited.pathname === here;

        const stopStart = router.on('start', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(true);
            }
        });

        const stopFinish = router.on('finish', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(false);
            }
        });

        const stopNetworkError = router.on('networkError', () =>
            announceOffline(),
        );

        // Retirés au démontage : `strictMode` monte deux fois, et un
        // abonnement oublié se cumulerait à chaque visite.
        return () => {
            stopStart();
            stopFinish();
            stopNetworkError();
        };
    }, []);

    return (
        <>
            <Head title={t('admin.curation.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.curation.heading')}
                    description={t('admin.curation.description')}
                    actions={
                        movies.meta.total > 0 ? (
                            <Button size="sm" className="min-h-11" asChild>
                                <Link
                                    href={curationNext({
                                        query: curationQuery(filters),
                                    })}
                                    aria-describedby="curation-next-hint"
                                >
                                    {t('admin.curation.next')}
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                {movies.meta.total > 0 && (
                    <p
                        id="curation-next-hint"
                        className="-mt-4 text-xs text-muted-foreground"
                    >
                        {t('admin.curation.next_hint')}
                    </p>
                )}

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.curation.totals.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.curation.totals.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        {ENTRIES.map((entry) => (
                            <AdminStatTile
                                key={entry}
                                label={t(TOTAL_KEYS[entry])}
                                value={totals[entry]}
                            />
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.curation.filters.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.curation.filters.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <CurationFiltersForm
                            filters={filters}
                            options={options}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.curation.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.curation.results', {
                                total: formatInteger(movies.meta.total, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <span
                            role="status"
                            aria-live="polite"
                            className="sr-only"
                        >
                            {busy ? t('admin.common.loading') : ''}
                        </span>

                        {movies.data.length === 0 && (
                            <AdminEmptyState
                                icon={
                                    filtered
                                        ? ListOrderedIcon
                                        : ClapperboardIcon
                                }
                                title={
                                    filtered
                                        ? t('admin.curation.empty_filtered')
                                        : t('admin.curation.empty')
                                }
                                action={
                                    <Button variant="outline" size="sm" asChild>
                                        {filtered ? (
                                            <Link href={curationIndex()}>
                                                {t(
                                                    'admin.curation.filters.reset',
                                                )}
                                            </Link>
                                        ) : (
                                            <Link href={importIndex()}>
                                                {t('admin.curation.go_import')}
                                            </Link>
                                        )}
                                    </Button>
                                }
                            />
                        )}

                        {movies.data.length > 0 && (
                            <div
                                aria-busy={busy}
                                className={
                                    busy
                                        ? 'space-y-4 opacity-60 transition-opacity'
                                        : 'space-y-4'
                                }
                            >
                                <CurationQueueTable
                                    movies={movies.data}
                                    filters={filters}
                                />

                                <AdminPagination
                                    meta={movies.meta}
                                    href={(page) =>
                                        curationIndex({
                                            query: curationQuery(filters, {
                                                page,
                                            }),
                                        })
                                    }
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminCurationIndex.layout = { breadcrumbs };

/**
 * La barre de filtres — un `<Form>` en GET : toute la sélection vit dans la
 * query string, partageable et rechargeable. Les listes d'options sont celles
 * que le serveur accepte : un choix offert est un choix accepté.
 */
function CurationFiltersForm({
    filters,
    options,
}: {
    filters: AdminCurationFilters;
    options: AdminCurationOptions;
}) {
    const { t } = useTranslations();

    const choices = (
        values: string[],
        keys: Partial<Record<string, TranslationKey>>,
    ): AdminSelectOption[] => [
        { value: '', label: t('admin.common.all') },
        ...values.map((value) => {
            const key = keys[value];

            return { value, label: key === undefined ? value : t(key) };
        }),
    ];

    return (
        <Form
            {...CurationQueueController.index.form()}
            options={{ preserveState: true, preserveScroll: true }}
            aria-label={t('admin.a11y.curation_filters_form')}
            className="grid gap-4 md:grid-cols-3"
        >
            <div className="space-y-1.5">
                <Label htmlFor="curation-entry">
                    {t('admin.curation.filters.entry.label')}
                </Label>
                <AdminSelect
                    id="curation-entry"
                    name="entry"
                    defaultValue={filters.entry ?? ''}
                    options={choices(options.entry, CURATION_ENTRY_KEYS)}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="curation-motive">
                    {t('admin.curation.filters.motive')}
                </Label>
                <AdminSelect
                    id="curation-motive"
                    name="motive"
                    defaultValue={filters.motive ?? ''}
                    options={choices(options.motive, EXCEPTION_MOTIVE_KEYS)}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="curation-content-flag">
                    {t('admin.curation.filters.content_flag')}
                </Label>
                <AdminSelect
                    id="curation-content-flag"
                    name="content_flag"
                    defaultValue={filters.content_flag ?? ''}
                    options={choices(options.content_flag, CONTENT_FLAG_KEYS)}
                />
            </div>

            <div className="flex flex-wrap items-end gap-2 md:col-span-3">
                <Button type="submit">
                    {t('admin.curation.filters.submit')}
                </Button>
                <Button variant="ghost" asChild>
                    <Link href={curationIndex()}>
                        {t('admin.curation.filters.reset')}
                    </Link>
                </Button>
            </div>
        </Form>
    );
}

/**
 * La table de la file. Elle ne trie rien : l'ordre est celui de la file, et
 * le rang le dit. Le défilement horizontal est porté par le conteneur de
 * `<Table>` : c'est le tableau qui défile en portrait, jamais le `<body>`.
 *
 * « Curer » porte les filtres de la file sur l'URL de l'éditeur, que
 * `FrameBankController` ignore : le « Film suivant » de l'éditeur les relit,
 * et l'enchaînement reste dans la strate du pilote.
 */
function CurationQueueTable({
    movies,
    filters,
}: {
    movies: AdminCurationQueueRow[];
    filters: AdminCurationFilters;
}) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead className="text-right">
                        {t('admin.curation.column.rank')}
                    </TableHead>
                    <TableHead>{t('admin.curation.column.title')}</TableHead>
                    <TableHead className="text-right">
                        {t('admin.curation.column.year')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.curation.column.votes')}
                    </TableHead>
                    <TableHead>{t('admin.curation.column.entry')}</TableHead>
                    <TableHead>
                        {t('admin.curation.column.content_flag')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.curation.column.levels')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.curation.column.variants')}
                    </TableHead>
                    <TableHead>{t('admin.curation.column.progress')}</TableHead>
                    <TableHead>
                        <span className="sr-only">
                            {t('admin.curation.column.actions')}
                        </span>
                    </TableHead>
                </TableRow>
            </TableHeader>

            <TableBody>
                {movies.map((movie) => (
                    <TableRow key={movie.id}>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(movie.rank, locale)}
                        </TableCell>

                        <TableCell className="align-top">
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="font-medium text-foreground">
                                    {movie.title_original}
                                </span>
                                {movie.title_original_latin !== null && (
                                    <span className="text-xs text-muted-foreground">
                                        {movie.title_original_latin}
                                    </span>
                                )}
                            </div>
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {movie.release_year === null
                                ? t('admin.common.unknown')
                                : movie.release_year}
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(movie.vote_count, locale)}
                        </TableCell>

                        <TableCell className="align-top">
                            <EntryCell movie={movie} />
                        </TableCell>

                        <TableCell className="align-top">
                            <ContentFlagBadge value={movie.content_flag} />
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {t('admin.movie.projection.levels_ratio', {
                                count: movie.levels_count,
                                total: LEVELS_SCALE,
                            })}
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(movie.variants_total, locale)}
                        </TableCell>

                        <TableCell className="align-top">
                            {movie.claimed_by !== null && (
                                <Badge
                                    variant="outline"
                                    className="mb-1"
                                    title={t('admin.curation.claimed_hint')}
                                >
                                    {t('admin.curation.claimed_by', {
                                        name: movie.claimed_by,
                                    })}
                                </Badge>
                            )}
                            {movie.is_started ? (
                                <div className="flex flex-col gap-1">
                                    <Badge variant="secondary">
                                        {t('admin.curation.row.started')}
                                    </Badge>
                                    <span className="text-xs whitespace-nowrap text-muted-foreground">
                                        {t('admin.curation.row.touched_at', {
                                            moment:
                                                formatMoment(
                                                    movie.touched_at,
                                                    locale,
                                                ) ?? t('admin.common.unknown'),
                                        })}
                                    </span>
                                </div>
                            ) : (
                                <Badge variant="outline">
                                    {t('admin.curation.row.not_started')}
                                </Badge>
                            )}
                        </TableCell>

                        <TableCell className="align-top">
                            <div className="flex flex-wrap gap-2">
                                <Button size="sm" className="min-h-11" asChild>
                                    <Link
                                        href={bank(movie.id, {
                                            query: curationQuery(filters),
                                        })}
                                        aria-label={t(
                                            'admin.a11y.curate_movie',
                                            {
                                                title: movie.title_original,
                                            },
                                        )}
                                    >
                                        {t('admin.curation.row.curate')}
                                    </Link>
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={catalogShow(movie.id)}
                                        aria-label={t('admin.a11y.open_movie', {
                                            title: movie.title_original,
                                        })}
                                    >
                                        {t('admin.curation.row.open')}
                                    </Link>
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * La voie d'entrée d'un film : balayage, ou exception avec ses motifs — la
 * décision 11 interdit que le marquage soit silencieux.
 */
function EntryCell({ movie }: { movie: AdminCurationQueueRow }) {
    const { t } = useTranslations();

    if (!movie.is_import_exception) {
        return (
            <Badge variant="outline">{t(CURATION_ENTRY_KEYS.discover)}</Badge>
        );
    }

    const motives: TranslationKey[] = [];

    if (movie.exception_for_language) {
        motives.push(EXCEPTION_MOTIVE_KEYS.language);
    }

    if (movie.exception_for_vote_count) {
        motives.push(EXCEPTION_MOTIVE_KEYS.vote_count);
    }

    if (movie.exception_for_release_year) {
        motives.push(EXCEPTION_MOTIVE_KEYS.release_year);
    }

    return (
        <div className="flex flex-col gap-1">
            <ExceptionBadge />
            {motives.length > 0 && (
                <ul className="space-y-0.5 text-xs text-muted-foreground">
                    {motives.map((motive) => (
                        <li key={motive}>{t(motive)}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
