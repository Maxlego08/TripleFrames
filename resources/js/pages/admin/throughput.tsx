import { Head, Link, router } from '@inertiajs/react';
import { GaugeIcon, TriangleAlertIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
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
    formatHours,
    formatInteger,
    formatMoment,
    formatPreciseDuration,
    formatSeconds,
    formatWeeks,
} from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as catalogShow } from '@/routes/admin/catalog';
import { index as curationIndex } from '@/routes/admin/curation';
import { index as throughputIndex } from '@/routes/admin/throughput';
import type {
    AdminCurationEntry,
    AdminPilotVerdict,
    AdminThroughputEntry,
    AdminThroughputMeasures,
    AdminThroughputProjection,
    AdminThroughputReport,
    AdminThroughputSetAside,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    report: AdminThroughputReport;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.throughput', href: throughputIndex() },
];

/** Les deux voies du pilote, dans l'ordre de la composition. */
const ENTRIES: AdminCurationEntry[] = ['discover', 'exception'];

/** Les lignes des mesures : chaque voie, puis les deux réunies. */
const MEASURE_ROWS: AdminThroughputEntry[] = ['discover', 'exception', 'total'];

const ENTRY_KEYS: Record<AdminThroughputEntry, TranslationKey> = {
    discover: 'admin.throughput.entry.discover',
    exception: 'admin.throughput.entry.exception',
    total: 'admin.throughput.entry.total',
};

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-throughput-offline';

/**
 * Le tableau du débit de curation et le verdict du lot pilote (spec 20
 * § 10.2 à § 10.4, lot L20-17) — **lecture seule, agrégat seulement** :
 * aucun curateur n'y est nommé.
 *
 * - L'**avancement du pilote** par voie : la fenêtre stratifiée (D11 du
 *   23/09) n'est pleine, et le verdict rendu, que lorsque les deux voies ont
 *   leur quota de films terminés.
 * - Le **verdict** de D10 du 23/09, tel quel, avec l'instant où la fenêtre
 *   s'est remplie : disqualification, films du jalon 1, cible de volume,
 *   projection en heures et en semaines. Sans heures hebdomadaires
 *   déclarées, les semaines laissent place à `admin.throughput.hours_undeclared`,
 *   jamais à un quotient.
 * - Les **mesures** — médiane et p90 au rang le plus proche, calculés par le
 *   serveur — du pilote puis de tous les films terminés, par voie, et les
 *   films écartés avec leurs motifs.
 *
 * États (§ 13.5) : aucun film terminé (message et lien vers la file),
 * rafraîchissement en cours (`aria-busy` et annonce polie, sans démonter les
 * tableaux), échec du rafraîchissement (message et « Réessayer », les chiffres
 * du dernier chargement restent), déconnexion (toast `admin.common.offline`).
 */
export default function AdminThroughput({ report }: Props) {
    const { t } = useTranslations();
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    function refresh(): void {
        router.reload({
            only: ['report'],
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setFailed(false),
            onHttpException: () => {
                setFailed(true);

                return false;
            },
            onNetworkError: () => {
                setFailed(true);
                toast.error(t('admin.common.offline'), {
                    id: OFFLINE_TOAST_ID,
                });

                return false;
            },
        });
    }

    const empty = report.all.measures.total.films === 0;
    const pilotFilms = new Set(
        report.pilot.set_aside.map((row) => row.movie_id),
    );

    return (
        <>
            <Head title={t('admin.throughput.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.throughput.heading')}
                    description={t('admin.throughput.description')}
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="min-h-11"
                            disabled={busy}
                            onClick={refresh}
                        >
                            {t('admin.throughput.refresh')}
                        </Button>
                    }
                />

                <span role="status" aria-live="polite" className="sr-only">
                    {busy ? t('admin.common.loading') : ''}
                </span>

                {failed && (
                    <AdminErrorState
                        title={t('admin.throughput.error.title')}
                        description={t('admin.throughput.error.description')}
                        retryLabel={t('admin.throughput.error.retry')}
                        onRetry={refresh}
                    />
                )}

                <div
                    aria-busy={busy}
                    className={
                        busy
                            ? 'flex flex-col gap-6 opacity-60 transition-opacity'
                            : 'flex flex-col gap-6'
                    }
                >
                    {empty && (
                        <AdminEmptyState
                            icon={GaugeIcon}
                            title={t('admin.throughput.empty')}
                            action={
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={curationIndex()}>
                                        {t('admin.throughput.go_curation')}
                                    </Link>
                                </Button>
                            }
                        />
                    )}

                    <PilotCard report={report} />

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.throughput.measures.pilot_heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t(
                                    'admin.throughput.measures.pilot_description',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <MeasuresTable measures={report.pilot.measures} />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.throughput.measures.all_heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.throughput.measures.all_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            <MeasuresTable measures={report.all.measures} />

                            <section className="space-y-2">
                                <h3 className="text-sm font-medium text-foreground">
                                    {t('admin.throughput.projection.heading')}
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'admin.throughput.projection.indicative',
                                    )}
                                </p>
                                <ProjectionTable
                                    projection={report.all.projection}
                                />
                            </section>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.throughput.set_aside.heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.throughput.set_aside.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <SetAsideTable
                                rows={report.all.set_aside}
                                pilotFilms={pilotFilms}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

AdminThroughput.layout = { breadcrumbs };

/**
 * Le lot pilote : l'avancement de chaque voie, puis le verdict une fois la
 * fenêtre pleine — jamais avant.
 */
function PilotCard({ report }: { report: AdminThroughputReport }) {
    const { t, locale } = useTranslations();
    const { thresholds, pilot, verdict } = report;

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.throughput.pilot.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.throughput.pilot.description', {
                        discover: formatInteger(
                            thresholds.composition.discover,
                            locale,
                        ),
                        exception: formatInteger(
                            thresholds.composition.exception,
                            locale,
                        ),
                        size: formatInteger(thresholds.size, locale),
                    })}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                <ul className="grid gap-3 sm:grid-cols-2">
                    {ENTRIES.map((entry) => {
                        const progress = pilot.progress[entry];
                        const label = t(ENTRY_KEYS[entry]);
                        const ratio = t('admin.throughput.pilot.progress', {
                            count: formatInteger(progress.terminated, locale),
                            quota: formatInteger(progress.quota, locale),
                        });

                        return (
                            <li
                                key={entry}
                                className="flex flex-col gap-2 rounded-lg border border-border bg-card p-4"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-card-foreground">
                                        {label}
                                    </span>
                                    <Badge
                                        variant={
                                            progress.full
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                    >
                                        {progress.full
                                            ? t('admin.throughput.pilot.full')
                                            : t('admin.throughput.pilot.open')}
                                    </Badge>
                                </div>
                                <span className="text-sm text-card-foreground tabular-nums">
                                    {ratio}
                                </span>
                                <Progress
                                    value={
                                        progress.quota === 0
                                            ? 100
                                            : Math.min(
                                                  100,
                                                  (progress.terminated * 100) /
                                                      progress.quota,
                                              )
                                    }
                                    aria-label={t('admin.common.label_value', {
                                        label,
                                        value: ratio,
                                    })}
                                />
                                <span className="text-xs text-muted-foreground">
                                    {t('admin.throughput.pilot.first_rank', {
                                        rank: formatInteger(
                                            progress.first_rank,
                                            locale,
                                        ),
                                    })}
                                </span>
                            </li>
                        );
                    })}
                </ul>

                {verdict === null ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.throughput.pilot.pending')}
                    </p>
                ) : (
                    <VerdictPanel verdict={verdict} report={report} />
                )}
            </CardContent>
        </Card>
    );
}

/** Le verdict de D10 du 23/09, tel quel, daté. */
function VerdictPanel({
    verdict,
    report,
}: {
    verdict: AdminPilotVerdict;
    report: AdminThroughputReport;
}) {
    const { t, locale } = useTranslations();
    const { thresholds } = report;
    // Durée exacte à la seconde face à un seuil rond : un arrondi afficherait
    // « 10 h » au-dessus comme au-dessous d'un seuil de 10 h, dans un texte
    // recopié tel quel au compte rendu du pilote.
    const total = formatPreciseDuration(verdict.total_active_seconds, locale);
    const limit = formatHours(verdict.disqualify_seconds, locale);

    return (
        <section
            aria-labelledby="throughput-verdict-heading"
            className="space-y-4"
        >
            <h3
                id="throughput-verdict-heading"
                className="text-base font-semibold text-foreground"
            >
                {t('admin.throughput.verdict.heading')}
            </h3>

            <p className="text-sm text-muted-foreground">
                {t('admin.throughput.verdict.filled_at', {
                    moment:
                        formatMoment(verdict.filled_at, locale) ??
                        t('admin.common.unknown'),
                })}
            </p>

            {verdict.disqualified ? (
                <Alert variant="destructive">
                    <TriangleAlertIcon aria-hidden />
                    <AlertTitle>
                        {t('admin.throughput.verdict.disqualified_title')}
                    </AlertTitle>
                    <AlertDescription>
                        <p>
                            {t('admin.throughput.verdict.disqualified', {
                                duration: total,
                                hours: limit,
                            })}
                        </p>
                    </AlertDescription>
                </Alert>
            ) : (
                <div className="rounded-lg border border-border bg-card p-4">
                    <p className="text-sm font-medium text-card-foreground">
                        {t('admin.throughput.verdict.qualified_title')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t('admin.throughput.verdict.qualified', {
                            duration: total,
                            hours: limit,
                        })}
                    </p>
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                {t('admin.throughput.verdict.outside_back_office')}
            </p>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <AdminStatTile
                    label={t('admin.throughput.verdict.films')}
                    value={verdict.films}
                />
                <AdminStatTile
                    label={t('admin.throughput.verdict.failures')}
                    value={verdict.failures}
                />
                <DurationTile
                    label={t('admin.throughput.verdict.total_active')}
                    seconds={verdict.total_active_seconds}
                />
                <DurationTile
                    label={t('admin.throughput.verdict.p90')}
                    seconds={verdict.p90_seconds}
                />
            </div>

            <div className="space-y-1">
                <h4 className="text-sm font-medium text-foreground">
                    {t('admin.throughput.verdict.j1_heading')}
                </h4>
                <p className="text-sm text-muted-foreground">
                    {verdict.j1.films === null
                        ? t('admin.throughput.verdict.no_published')
                        : t(
                              verdict.j1.target_kept === true
                                  ? 'admin.throughput.verdict.j1_kept'
                                  : 'admin.throughput.verdict.j1_reduced',
                              {
                                  count: formatInteger(
                                      verdict.j1.films,
                                      locale,
                                  ),
                                  remaining: formatInteger(
                                      thresholds.remaining_after_pilot,
                                      locale,
                                  ),
                                  hours: formatHours(
                                      thresholds.reserve_seconds,
                                      locale,
                                  ),
                              },
                          )}
                </p>
            </div>

            <div className="space-y-1">
                <h4 className="text-sm font-medium text-foreground">
                    {t('admin.throughput.verdict.volume_heading')}
                </h4>
                <p className="text-sm text-muted-foreground">
                    <VolumeLine
                        projection={verdict}
                        cap={thresholds.volume_cap}
                    />
                </p>
            </div>

            <div className="space-y-2">
                <h4 className="text-sm font-medium text-foreground">
                    {t('admin.throughput.projection.heading')}
                </h4>
                <p className="text-xs text-muted-foreground">
                    {t('admin.throughput.projection.description')}
                </p>
                <ProjectionTable projection={verdict} />
            </div>
        </section>
    );
}

/** La cible de volume, ou ce qui manque pour la calculer. */
function VolumeLine({
    projection,
    cap,
}: {
    projection: AdminThroughputProjection;
    cap: number;
}) {
    const { t, locale } = useTranslations();

    if (projection.declared_hours === 0) {
        return <>{t('admin.throughput.verdict.volume_undeclared')}</>;
    }

    if (projection.volume.films === null) {
        return <>{t('admin.throughput.verdict.no_published')}</>;
    }

    return (
        <>
            {t('admin.throughput.verdict.volume_target', {
                count: formatInteger(projection.volume.films, locale),
                cap: formatInteger(cap, locale),
            })}
        </>
    );
}

/**
 * Une tuile de durée du verdict : durée exacte à la seconde, ou « Sans
 * mesure ». Exacte, parce que le temps actif total et le p90 se comparent à
 * des seuils ronds (disqualification, réserve divisée par les films restants).
 */
function DurationTile({
    label,
    seconds,
}: {
    label: string;
    seconds: number | null;
}) {
    const { t, locale } = useTranslations();

    return (
        <div className="flex flex-col gap-1 rounded-lg border border-border bg-card p-4">
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <span className="text-2xl font-semibold text-card-foreground tabular-nums">
                {seconds === null
                    ? t('admin.throughput.no_value')
                    : formatPreciseDuration(seconds, locale)}
            </span>
        </div>
    );
}

/**
 * La projection des deux cibles : films, heures (`cible × p90`), semaines
 * (`heures ÷ heures hebdomadaires déclarées`) — ou le message qui dit ce qui
 * manque, jamais un quotient inventé.
 */
function ProjectionTable({
    projection,
}: {
    projection: AdminThroughputProjection;
}) {
    const { t, locale } = useTranslations();

    if (projection.p90_seconds === null) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('admin.throughput.projection.unavailable')}
            </p>
        );
    }

    const rows: {
        key: string;
        label: TranslationKey;
        films: number | null;
        seconds: number | null;
        weeks: number | null;
    }[] = [
        {
            key: 'j1',
            label: 'admin.throughput.projection.j1',
            ...projection.j1,
        },
        {
            key: 'volume',
            label: 'admin.throughput.projection.volume',
            ...projection.volume,
        },
    ];

    return (
        <div className="space-y-2">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>
                            {t('admin.throughput.projection.target')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.throughput.projection.films')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.throughput.projection.hours')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.throughput.projection.weeks')}
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.key}>
                            <TableCell>{t(row.label)}</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.films === null
                                    ? t('admin.throughput.no_value')
                                    : formatInteger(row.films, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.seconds === null
                                    ? t('admin.throughput.no_value')
                                    : formatHours(row.seconds, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {row.weeks === null
                                    ? t('admin.throughput.no_value')
                                    : formatWeeks(row.weeks, locale)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>

            {!projection.weekly_hours_declared && (
                <p className="text-xs text-muted-foreground">
                    {t('admin.throughput.hours_undeclared')}
                </p>
            )}
        </div>
    );
}

/** Les mesures d'une population, une ligne par voie puis les deux réunies. */
function MeasuresTable({
    measures,
}: {
    measures: Record<AdminThroughputEntry, AdminThroughputMeasures>;
}) {
    const { t, locale } = useTranslations();

    const duration = (seconds: number | null): string =>
        seconds === null
            ? t('admin.throughput.no_value')
            : formatSeconds(seconds, locale);

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {t('admin.throughput.measures.column.entry')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.films')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.published')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.set_aside')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.active_total')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.active_median')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.active_p90')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.crop_frames')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.crop_median')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.throughput.measures.column.crop_p90')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {MEASURE_ROWS.map((entry) => {
                    const row = measures[entry];

                    return (
                        <TableRow key={entry}>
                            <TableCell
                                className={
                                    entry === 'total'
                                        ? 'font-medium'
                                        : undefined
                                }
                            >
                                {t(ENTRY_KEYS[entry])}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatInteger(row.films, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatInteger(row.published, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatInteger(row.set_aside, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatSeconds(
                                    row.active_seconds_total,
                                    locale,
                                )}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {duration(row.active_seconds_median)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {duration(row.active_seconds_p90)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatInteger(row.crop_frames, locale)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {duration(row.crop_seconds_median)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {duration(row.crop_seconds_p90)}
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}

/** Les films écartés, leur voie et leur motif ; ceux du pilote sont marqués. */
function SetAsideTable({
    rows,
    pilotFilms,
}: {
    rows: AdminThroughputSetAside[];
    pilotFilms: Set<number>;
}) {
    const { t, locale } = useTranslations();

    if (rows.length === 0) {
        return (
            <p role="status" className="text-sm text-muted-foreground">
                {t('admin.throughput.set_aside.empty')}
            </p>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>
                        {t('admin.throughput.set_aside.column.title')}
                    </TableHead>
                    <TableHead>
                        {t('admin.throughput.set_aside.column.entry')}
                    </TableHead>
                    <TableHead>
                        {t('admin.throughput.set_aside.column.reason')}
                    </TableHead>
                    <TableHead>
                        {t('admin.throughput.set_aside.column.terminated_at')}
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.movie_id}>
                        <TableCell className="align-top">
                            <div className="flex flex-wrap items-center gap-2">
                                <Link
                                    href={catalogShow(row.movie_id)}
                                    className="font-medium text-foreground underline-offset-4 hover:underline"
                                    aria-label={t('admin.a11y.open_movie', {
                                        title: row.title_original,
                                    })}
                                >
                                    {row.title_original}
                                </Link>
                                {pilotFilms.has(row.movie_id) && (
                                    <Badge variant="secondary">
                                        {t(
                                            'admin.throughput.set_aside.in_pilot',
                                        )}
                                    </Badge>
                                )}
                            </div>
                        </TableCell>
                        <TableCell className="align-top">
                            {t(ENTRY_KEYS[row.entry])}
                        </TableCell>
                        <TableCell className="align-top whitespace-normal">
                            {row.reason ??
                                t('admin.throughput.set_aside.no_reason')}
                        </TableCell>
                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(row.terminated_at, locale) ??
                                t('admin.common.unknown')}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
