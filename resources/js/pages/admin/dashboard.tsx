import { Head, Link } from '@inertiajs/react';
import {
    ArchiveIcon,
    ClapperboardIcon,
    DownloadCloudIcon,
    InfoIcon,
} from 'lucide-react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminImportRunTable } from '@/components/admin/admin-import-run-table';
import { AdminMovieTable } from '@/components/admin/admin-movie-table';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import {
    AVAILABILITY_KEYS,
    CONTENT_FLAG_KEYS,
    CURATION_STATUS_KEYS,
    EXCEPTION_MOTIVE_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    index as catalogIndex,
    show as catalogShow,
} from '@/routes/admin/catalog';
import {
    index as curationIndex,
    next as curationNext,
} from '@/routes/admin/curation';
import { index as importIndex } from '@/routes/admin/import';
import { index as reviewIndex } from '@/routes/admin/review';
import type {
    AdminCurationQueueRow,
    AdminCurationStatus,
    AdminDashboardStats,
    AdminImportRunRow,
    AdminReviewList,
    AdminSetAsideRow,
    ContentAvailability,
    ContentFlag,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    stats: AdminDashboardStats;
    queue: AdminCurationQueueRow[];
    set_aside: AdminSetAsideRow[];
    runs: AdminImportRunRow[];
    tmdb_configured: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
];

/** L'ordre de lecture des cinq états : du plus brut au plus retiré. */
const AVAILABILITY_ORDER: ContentAvailability[] = [
    'draft',
    'published',
    'unpublished',
    'suspended',
    'withdrawn',
];

const CONTENT_FLAG_ORDER: ContentFlag[] = [
    'clear',
    'unrated_pending',
    'blocked',
];

/**
 * Les trois états de curation, dans l'ordre du travail : ce qui attend un
 * clic, ce qui s'est dégradé, ce qui est sorti de la file.
 */
const CURATION_ORDER: {
    status: AdminCurationStatus;
    hint: TranslationKey;
}[] = [
    {
        status: 'ready_to_publish',
        hint: 'admin.dashboard.curation.ready_to_publish_hint',
    },
    { status: 'incomplete', hint: 'admin.dashboard.curation.incomplete_hint' },
    { status: 'set_aside', hint: 'admin.dashboard.curation.set_aside_hint' },
];

/** Les libellés des tuiles de curation (distincts de ceux du filtre). */
const CURATION_LABELS: Record<AdminCurationStatus, TranslationKey> = {
    ready_to_publish: 'admin.dashboard.curation.ready_to_publish',
    incomplete: 'admin.dashboard.curation.incomplete',
    set_aside: 'admin.dashboard.curation.set_aside',
};

/** Les quatre compteurs d'images, dans l'ordre de la file de revue. */
const FRAME_COUNTERS: {
    key: AdminReviewList | 'failed';
    label: TranslationKey;
}[] = [
    { key: 'to_review', label: 'admin.dashboard.frames.to_review' },
    { key: 'to_rereview', label: 'admin.dashboard.frames.to_rereview' },
    { key: 'rejected', label: 'admin.dashboard.frames.rejected' },
    { key: 'failed', label: 'admin.dashboard.frames.failed' },
];

/**
 * Le tableau de bord de curation — supervision pure, **aucune écriture**
 * (spec 20 § 8.6).
 *
 * Pas un chiffre inventé : une tuile à zéro s'affiche, parce qu'une tuile
 * escamotée tairait une information.
 *
 * Le vivier est donné **par N**, en œuvres, bornes des réglages de salon, et
 * c'est le même compte que celui d'un lobby, sans thème ni non-répétition
 * (contrat C2) : un plafond. Un chiffre unique masquerait qu'un catalogue
 * confortable à N = 3 peut être vide à N = 5 — exactement la panne qu'un hôte
 * découvrirait dans son lobby, sans cause visible.
 *
 * Chaque compteur de curation mène à la liste qu'il annonce : un filtre du
 * catalogue, la file de revue, la file de curation.
 */
export default function AdminDashboard({
    stats,
    queue,
    set_aside,
    runs,
    tmdb_configured,
}: Props) {
    const { t, locale } = useTranslations();
    const poolIsEmpty = stats.pool.every((entry) => entry.works === 0);

    return (
        <>
            <Head title={t('admin.dashboard.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.dashboard.heading')}
                    description={t('admin.dashboard.description')}
                    actions={
                        <Button size="sm" className="min-h-11" asChild>
                            <Link href={curationNext()}>
                                {t('admin.curation.next')}
                            </Link>
                        </Button>
                    }
                />

                {!tmdb_configured && (
                    <Alert>
                        <InfoIcon />
                        <AlertTitle>{t('admin.import.disabled')}</AlertTitle>
                        <AlertDescription>
                            {t('admin.dashboard.tmdb_disabled')}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Bloc 1 — disponibilité */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.availability.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.availability.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        {AVAILABILITY_ORDER.map((value) => (
                            <AdminStatTile
                                key={value}
                                label={t(AVAILABILITY_KEYS[value])}
                                value={stats.availability[value]}
                            />
                        ))}
                        <AdminStatTile
                            label={t('admin.dashboard.availability.total')}
                            value={stats.movies_total}
                        />
                    </CardContent>
                </Card>

                {/* Bloc 1 bis — curation des films (§ 8.6) */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.curation.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.curation.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-3">
                        {CURATION_ORDER.map(({ status, hint }) => (
                            <AdminStatTile
                                key={status}
                                label={t(CURATION_LABELS[status])}
                                value={stats.curation[status]}
                                hint={t(hint)}
                                trailing={
                                    <Link
                                        href={catalogIndex({
                                            query: { curation_status: status },
                                        })}
                                        className="text-xs font-medium text-primary underline-offset-4 hover:underline focus-visible:underline"
                                    >
                                        {t(
                                            'admin.dashboard.curation.see_list',
                                            {
                                                label: t(
                                                    CURATION_STATUS_KEYS[
                                                        status
                                                    ],
                                                ),
                                            },
                                        )}
                                    </Link>
                                }
                            />
                        ))}
                    </CardContent>
                </Card>

                {/* Bloc 1 ter — images à traiter (§ 8.6) */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.frames.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.frames.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {FRAME_COUNTERS.map((counter) => (
                                <AdminStatTile
                                    key={counter.key}
                                    label={t(counter.label)}
                                    value={stats.frames[counter.key]}
                                />
                            ))}
                        </div>

                        <div className="flex justify-end">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={reviewIndex()}>
                                    {t('admin.dashboard.frames.see_review')}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Bloc 1 quater — drapeau de contenu */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.content_flag.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.content_flag.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-3">
                        {CONTENT_FLAG_ORDER.map((value) => (
                            <AdminStatTile
                                key={value}
                                label={t(CONTENT_FLAG_KEYS[value])}
                                value={stats.content_flag[value]}
                            />
                        ))}
                    </CardContent>
                </Card>

                {/* Entrées par exception — le comptage par motif de la décision 11 */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.exceptions.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.exceptions.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <AdminStatTile
                            label={t('admin.dashboard.exceptions.total')}
                            value={stats.exceptions.total}
                        />
                        <AdminStatTile
                            label={t(EXCEPTION_MOTIVE_KEYS.language)}
                            value={stats.exceptions.language}
                        />
                        <AdminStatTile
                            label={t(EXCEPTION_MOTIVE_KEYS.vote_count)}
                            value={stats.exceptions.vote_count}
                        />
                        <AdminStatTile
                            label={t(EXCEPTION_MOTIVE_KEYS.release_year)}
                            value={stats.exceptions.release_year}
                        />
                    </CardContent>
                </Card>

                {/* Bloc 2 — vivier catalogue par N, en œuvres (C2) */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.pool.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.pool.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            {stats.pool.map((entry) => (
                                <AdminStatTile
                                    key={entry.frames_per_round}
                                    label={t(
                                        'admin.dashboard.pool.frames_per_round',
                                        { count: entry.frames_per_round },
                                    )}
                                    value={entry.works}
                                    hint={t('admin.dashboard.pool.works')}
                                />
                            ))}
                        </div>

                        {poolIsEmpty && (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.dashboard.pool.empty')}
                            </p>
                        )}

                        <p className="max-w-prose text-xs text-muted-foreground">
                            {t('admin.dashboard.pool.scope_notice')}
                        </p>
                    </CardContent>
                </Card>

                {/* Bloc 3 — couverture d'images */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.coverage.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.coverage.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                            {stats.coverage.levels.map((level) => (
                                <AdminStatTile
                                    key={level.level}
                                    label={t('admin.dashboard.coverage.level', {
                                        level: level.level,
                                    })}
                                    value={level.variants}
                                    hint={`${t('admin.dashboard.coverage.variants')} · ${formatInteger(level.movies, locale)} ${t('admin.dashboard.coverage.movies_at_level')}`}
                                />
                            ))}
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <AdminStatTile
                                label={t(
                                    'admin.dashboard.coverage.variants_total',
                                )}
                                value={stats.coverage.variants_total}
                            />
                            <AdminStatTile
                                label={t(
                                    'admin.dashboard.coverage.publishable',
                                )}
                                value={stats.coverage.covers_publishable}
                            />
                            <AdminStatTile
                                label={t(
                                    'admin.dashboard.coverage.without_frames',
                                )}
                                value={stats.coverage.without_frames}
                            />
                            <AdminStatTile
                                label={t(
                                    'admin.dashboard.coverage.single_variant',
                                )}
                                value={stats.coverage.single_variant_levels}
                                hint={t('admin.dashboard.coverage.passes_hint')}
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Bloc 4 — tête de la file de curation (§ 4.1) */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.queue.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.queue.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {queue.length === 0 ? (
                            <AdminEmptyState
                                icon={ClapperboardIcon}
                                title={t('admin.dashboard.queue.empty')}
                            />
                        ) : (
                            <AdminMovieTable movies={queue} />
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-xs text-muted-foreground">
                                {t('admin.dashboard.queue.unrated_pending', {
                                    count: formatInteger(
                                        stats.content_flag.unrated_pending,
                                        locale,
                                    ),
                                })}
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={curationIndex()}>
                                    {t('admin.dashboard.queue.see_all')}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Bloc 4 bis — films écartés (§ 4.2) */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.set_aside.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.set_aside.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {set_aside.length === 0 ? (
                            <AdminEmptyState
                                icon={ArchiveIcon}
                                title={t('admin.dashboard.set_aside.empty')}
                            />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('admin.catalog.column.title')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.dashboard.set_aside.reason',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.dashboard.set_aside.set_aside_at',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            <span className="sr-only">
                                                {t(
                                                    'admin.catalog.column.actions',
                                                )}
                                            </span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {set_aside.map((movie) => (
                                        <TableRow key={movie.id}>
                                            <TableCell className="align-top font-medium">
                                                {movie.title_original}
                                            </TableCell>
                                            <TableCell className="max-w-prose align-top whitespace-normal text-muted-foreground">
                                                {movie.availability_reason ??
                                                    t(
                                                        'admin.dashboard.set_aside.no_reason',
                                                    )}
                                            </TableCell>
                                            <TableCell className="align-top whitespace-nowrap">
                                                {formatMoment(
                                                    movie.availability_changed_at,
                                                    locale,
                                                ) ?? t('admin.common.unknown')}
                                            </TableCell>
                                            <TableCell className="align-top">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <Link
                                                        href={catalogShow(
                                                            movie.id,
                                                        )}
                                                        aria-label={t(
                                                            'admin.a11y.open_movie',
                                                            {
                                                                title: movie.title_original,
                                                            },
                                                        )}
                                                    >
                                                        {t(
                                                            'admin.catalog.row.open',
                                                        )}
                                                    </Link>
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}

                        <div className="flex justify-end">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={catalogIndex({
                                        query: { curation_status: 'set_aside' },
                                    })}
                                >
                                    {t('admin.dashboard.set_aside.see_all')}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Bloc 5 — derniers balayages */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.dashboard.runs.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.dashboard.runs.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {runs.length === 0 ? (
                            <AdminEmptyState
                                icon={DownloadCloudIcon}
                                title={t('admin.dashboard.runs.empty')}
                            />
                        ) : (
                            <AdminImportRunTable
                                runs={runs}
                                showFilter={false}
                                showResume={false}
                            />
                        )}

                        <div className="flex justify-end">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={importIndex()}>
                                    {t('admin.dashboard.runs.see_all')}
                                </Link>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminDashboard.layout = { breadcrumbs };
