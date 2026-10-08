import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeftIcon,
    CircleSlashIcon,
    ClapperboardIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import ImportAbandonController from '@/actions/App/Http/Controllers/Admin/ImportAbandonController';
import {
    ImportRunKindBadge,
    ImportRunStatusBadge,
    WidenedBadge,
} from '@/components/admin/admin-badges';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { ResumeButton } from '@/components/admin/admin-import-run-table';
import { AdminFieldList } from '@/components/admin/admin-field-list';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
import { AdminMovieTable } from '@/components/admin/admin-movie-table';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import {
    ConfirmGestureDialog,
    useGestureFocus,
} from '@/components/admin/confirm-gesture-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
    formatDuration,
    formatInteger,
    formatMoment,
} from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as importIndex, show as runShow } from '@/routes/admin/import';
import type {
    AdminImportRunDetail,
    AdminMovieRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    run: AdminImportRunDetail;
    movies: Paginated<AdminMovieRow>;
    /**
     * Verdict serveur de reprise. Il n'est pas lu au rendu — `ResumeButton`
     * dérive son propre motif de refus, pour pouvoir le NOMMER plutôt que de
     * disparaître en silence — mais il fait partie du contrat de fraîcheur :
     * c'est l'une des trois props que le rafraîchissement partiel recharge,
     * de sorte qu'un balayage qui se termine pendant qu'on le regarde ne
     * laisse pas un bouton actif derrière lui.
     */
    can_resume: boolean;
    /** « Clore ce balayage » (spec 20 § 3.8) : en file ou suspendu, et l'auteur peut le geste. */
    can_abandon: boolean;
    tmdb_configured: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.import', href: importIndex() },
    { title: 'admin.import.run.title', href: importIndex() },
];

/** Même cadence que l'écran d'import : voir son commentaire. */
const REFRESH_INTERVAL_MS = 5000;

/**
 * Le détail d'un balayage.
 *
 * Cet écran est justifié par un besoin d'AUJOURD'HUI, pas par anticipation :
 * l'import étant différé, un curateur qui lance un balayage n'a aucun autre
 * endroit où lire ce qu'il a fait ni pourquoi il s'est arrêté. Et la décision
 * 11 exige qu'un film entré par exception soit auditable, ce qui suppose de
 * remonter au balayage **et à son filtre exact**.
 *
 * Les quatre compteurs ne sont jamais fondus en un seul : « ignoré » n'est pas
 * « refusé ». Un film ignoré l'a été par le filtre de goût ou parce qu'il était
 * déjà au catalogue ; un film refusé l'a été par le filtre de CONTENU, que
 * personne ne contourne.
 */
export default function AdminImportShow({
    run,
    movies,
    can_abandon,
    tmdb_configured,
}: Props) {
    const { t, locale } = useTranslations();
    const isLive = run.status === 'running';

    const [refreshing, setRefreshing] = useState(false);
    const [refreshFailed, setRefreshFailed] = useState(false);

    const refresh = useCallback(() => {
        router.reload({
            only: ['run', 'movies', 'can_resume', 'can_abandon'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
            onSuccess: () => setRefreshFailed(false),
            // Décision 9 : chaque état d'échec est traduit et rejouable d'un
            // bouton. Sans ce signal, quatre compteurs figés ne se distinguent
            // pas d'un balayage qui n'avance plus.
            onError: () => setRefreshFailed(true),
        });
    }, []);

    useEffect(() => {
        if (!isLive) {
            return;
        }

        const timer = window.setInterval(refresh, REFRESH_INTERVAL_MS);

        // `strictMode` monte deux fois : sans ce nettoyage, deux intervalles
        // survivraient au démontage et doubleraient la charge à chaque visite.
        return () => window.clearInterval(timer);
    }, [isLive, refresh]);

    const duration = formatDuration(
        run.started_at,
        run.finished_at ?? new Date().toISOString(),
        locale,
    );

    return (
        <>
            <Head title={t('admin.import.run.heading', { id: run.id })} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.import.run.heading', { id: run.id })}
                    description={t('admin.import.run.summary.heading')}
                    // Un SEUL bouton « reprendre » sur la page : celui de la
                    // carte « compteurs et reprise », là où le curseur et les
                    // quatre compteurs disent ce que la reprise va poursuivre.
                    // Deux boutons de même libellé postant la même route ne se
                    // distinguent d'aucune façon au lecteur d'écran.
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={importIndex()}>
                                <ArrowLeftIcon aria-hidden />
                                {t('admin.import.run.back')}
                            </Link>
                        </Button>
                    }
                />

                {isLive && (
                    <p role="status" className="text-xs text-muted-foreground">
                        {t('admin.import.run.live')}
                    </p>
                )}

                {refreshFailed && (
                    <AdminErrorState
                        title={t('admin.common.error')}
                        retryLabel={t('admin.common.refresh')}
                        onRetry={refresh}
                    />
                )}

                {refreshing && (
                    <AdminLoadingState
                        label={t('admin.common.loading')}
                        rows={1}
                    />
                )}

                {run.is_queued && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertDescription>
                            {t('admin.import.runs.worker_missing')}
                        </AlertDescription>
                    </Alert>
                )}

                {/* Résumé */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.run.summary.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <ImportRunKindBadge value={run.run_kind} />
                            <ImportRunStatusBadge run={run} />
                            {run.is_widened && <WidenedBadge />}
                        </div>

                        <AdminFieldList
                            fields={[
                                {
                                    label: t('admin.import.run.summary.actor'),
                                    value:
                                        run.actor_name ??
                                        t('admin.common.deleted_account'),
                                },
                                {
                                    label: t(
                                        'admin.import.run.summary.started_at',
                                    ),
                                    value:
                                        formatMoment(run.started_at, locale) ??
                                        t('admin.common.none'),
                                },
                                {
                                    label: t(
                                        'admin.import.run.summary.finished_at',
                                    ),
                                    value:
                                        formatMoment(run.finished_at, locale) ??
                                        t('admin.common.none'),
                                },
                                {
                                    label: t(
                                        'admin.import.run.summary.duration',
                                    ),
                                    value: duration ?? t('admin.common.none'),
                                },
                            ]}
                        />
                    </CardContent>
                </Card>

                {/* Filtre figé */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.run.filter.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {run.run_kind === 'paste' && (
                            <Alert>
                                <AlertDescription>
                                    {t('admin.import.run.filter.ignored')}
                                </AlertDescription>
                            </Alert>
                        )}

                        {run.is_widened && (
                            <Alert>
                                <AlertDescription>
                                    {t('admin.import.run.filter.widened')}
                                </AlertDescription>
                            </Alert>
                        )}

                        <AdminFieldList
                            fields={[
                                {
                                    label: t(
                                        'admin.import.run.filter.min_votes',
                                    ),
                                    value:
                                        run.filter_min_vote_count === null
                                            ? t('admin.common.none')
                                            : formatInteger(
                                                  run.filter_min_vote_count,
                                                  locale,
                                              ),
                                },
                                {
                                    label: t(
                                        'admin.import.run.filter.languages',
                                    ),
                                    value:
                                        run.filter_languages ??
                                        t('admin.common.none'),
                                },
                                {
                                    label: t(
                                        'admin.import.run.filter.min_year',
                                    ),
                                    value:
                                        run.filter_min_release_year ??
                                        t('admin.common.none'),
                                },
                            ]}
                        />
                    </CardContent>
                </Card>

                {/* Compteurs et reprise */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.run.counters.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.import.run.counters.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <AdminStatTile
                                label={t('admin.import.run.counters.seen')}
                                value={run.total_seen}
                            />
                            <AdminStatTile
                                label={t('admin.import.run.counters.imported')}
                                value={run.total_imported}
                            />
                            <AdminStatTile
                                label={t('admin.import.run.counters.skipped')}
                                value={run.total_skipped}
                            />
                            <AdminStatTile
                                label={t('admin.import.run.counters.refused')}
                                value={run.total_refused_content}
                            />
                        </div>

                        <h3 className="text-sm font-medium text-foreground">
                            {t('admin.import.run.cursor.heading')}
                        </h3>

                        <AdminFieldList
                            fields={[
                                {
                                    label: t(
                                        'admin.import.run.cursor.position',
                                    ),
                                    value:
                                        run.tmdb_page_cursor === null
                                            ? t('admin.import.run.cursor.none')
                                            : formatInteger(
                                                  run.tmdb_page_cursor,
                                                  locale,
                                              ),
                                },
                                {
                                    label: t(
                                        'admin.import.run.cursor.last_request_at',
                                    ),
                                    value:
                                        formatMoment(
                                            run.last_request_at,
                                            locale,
                                        ) ?? t('admin.common.none'),
                                },
                            ]}
                        />

                        <div className="flex flex-wrap justify-start gap-3">
                            <ResumeButton
                                run={run}
                                tmdbConfigured={tmdb_configured}
                            />
                            {can_abandon && <AbandonButton runId={run.id} />}
                        </div>
                    </CardContent>
                </Card>

                {/* Thèmes du collage (D43 du 01/10, spec 20 § 3.3) */}
                {run.run_kind === 'paste' && (
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.import.run.themes.heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.import.run.themes.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {run.added_themes.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('admin.import.run.themes.none')}
                                </p>
                            ) : (
                                <>
                                    <ul className="flex flex-wrap gap-2">
                                        {run.added_themes.map((theme) => (
                                            <li
                                                key={theme.id}
                                                className="flex items-center gap-1.5 text-sm"
                                            >
                                                <Badge variant="secondary">
                                                    {theme.label}
                                                </Badge>
                                                {!theme.is_published && (
                                                    <Badge variant="outline">
                                                        {t(
                                                            'admin.import.run.themes.unpublished',
                                                        )}
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <AdminStatTile
                                            label={t(
                                                'admin.import.run.themes.applied',
                                            )}
                                            value={run.total_themes_applied}
                                        />
                                        <AdminStatTile
                                            label={t(
                                                'admin.import.run.themes.kept_removed',
                                            )}
                                            value={
                                                run.total_themes_kept_removed
                                            }
                                        />
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Ce que CE balayage a fait entrer */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.run.movies.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.import.run.movies.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {movies.data.length === 0 ? (
                            <AdminEmptyState
                                icon={ClapperboardIcon}
                                title={t('admin.import.run.movies.empty')}
                            />
                        ) : (
                            <>
                                <AdminMovieTable movies={movies.data} />
                                <AdminPagination
                                    meta={movies.meta}
                                    href={(page) =>
                                        runShow(run.id, { query: { page } })
                                    }
                                />
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminImportShow.layout = { breadcrumbs };

/**
 * « Clore ce balayage » (spec 20 § 3.8, L20-24) : le balayage passe
 * « échoué », journalisé ; une confirmation d'abord, jamais un geste muet.
 */
function AbandonButton({ runId }: { runId: number }) {
    const { t } = useTranslations();
    const focus = useGestureFocus();
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => {
                    focus.remember();
                    setOpen(true);
                }}
                className="min-h-11"
            >
                <CircleSlashIcon aria-hidden />
                {t('admin.import.abandon.action')}
            </Button>

            {open && (
                <ConfirmGestureDialog
                    open
                    form={ImportAbandonController.store.form(runId)}
                    title={t('admin.import.abandon.title')}
                    description={t('admin.import.abandon.description')}
                    submitLabel={t('admin.import.abandon.submit')}
                    errorFields={[]}
                    onClose={() => setOpen(false)}
                    onReturnFocus={focus.restore}
                />
            )}
        </>
    );
}
