import { Head, Link } from '@inertiajs/react';
import { ClapperboardIcon, DownloadCloudIcon, InfoIcon } from 'lucide-react';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminImportRunTable } from '@/components/admin/admin-import-run-table';
import { AdminMovieTable } from '@/components/admin/admin-movie-table';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import {
    AVAILABILITY_KEYS,
    CONTENT_FLAG_KEYS,
    EXCEPTION_MOTIVE_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as catalogIndex } from '@/routes/admin/catalog';
import { index as importIndex } from '@/routes/admin/import';
import type {
    AdminDashboardStats,
    AdminImportRunRow,
    AdminMovieRow,
    ContentAvailability,
    ContentFlag,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    stats: AdminDashboardStats;
    queue: AdminMovieRow[];
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
 * Le tableau de bord de curation — supervision pure, **aucune écriture**.
 *
 * Cinq blocs, et pas un chiffre inventé. Tout ce qui touche aux images vaut
 * zéro aujourd'hui parce qu'aucune ligne `frame` n'existe encore : c'est la
 * vérité à afficher, et une tuile à zéro dit quelque chose qu'une tuile
 * escamotée tairait.
 *
 * Le vivier est donné **par N**, de 2 à 5. Un chiffre unique masquerait qu'un
 * catalogue confortable à N = 3 peut être vide à N = 5 — c'est-à-dire
 * exactement la panne qu'un hôte découvrirait dans son lobby, sans cause
 * visible.
 */
export default function AdminDashboard({
    stats,
    queue,
    runs,
    tmdb_configured,
}: Props) {
    const { t, locale } = useTranslations();
    const poolIsEmpty = stats.pool.every((entry) => entry.movies === 0);

    return (
        <>
            <Head title={t('admin.dashboard.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.dashboard.heading')}
                    description={t('admin.dashboard.description')}
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

                {/* Bloc 1 bis — drapeau de contenu */}
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

                {/* Bloc 2 — vivier par N */}
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
                                    value={entry.movies}
                                    hint={t('admin.dashboard.pool.movies')}
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

                {/* Bloc 4 — file des films non curés */}
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
                                <Link
                                    href={catalogIndex({
                                        query: {
                                            availability: 'draft',
                                            sort: 'created_at',
                                            direction: 'asc',
                                        },
                                    })}
                                >
                                    {t('admin.dashboard.queue.see_all')}
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
