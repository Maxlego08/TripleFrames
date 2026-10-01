import { Head, Link } from '@inertiajs/react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Badge } from '@/components/ui/badge';
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
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as performanceIndex } from '@/routes/admin/performance';
import type { PerfGroupRow, PerfReport, PerfWindow } from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    report: PerfReport;
    window: PerfWindow;
    windows: PerfWindow[];
    enabled: boolean;
    slow_query_ms: number;
    sample_rate: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.performance', href: performanceIndex() },
];

const WINDOW_KEYS: Record<PerfWindow, TranslationKey> = {
    '1h': 'admin.performance.window.1h',
    '24h': 'admin.performance.window.24h',
    '7d': 'admin.performance.window.7d',
};

/**
 * Les performances mesurées — spec 20 § 12.3 (D47 du 01/10),
 * **administrateur seul**, en lecture seule : requêtes par route, jobs par
 * classe, moteur de partie, requêtes SQL lentes, sur la fenêtre choisie.
 */
export default function AdminPerformanceIndex({
    report,
    window,
    windows,
    enabled,
    slow_query_ms,
    sample_rate,
}: Props) {
    const { t, locale } = useTranslations();
    const ms = (value: number | null): string =>
        value === null
            ? t('admin.common.none')
            : t('admin.performance.ms', {
                  value: formatInteger(value, locale),
              });

    return (
        <>
            <Head title={t('admin.performance.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.performance.heading')}
                    description={t('admin.performance.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                {!enabled && (
                    <p className="text-sm text-destructive">
                        {t('admin.performance.disabled')}
                    </p>
                )}

                <p className="text-sm text-muted-foreground">
                    {t('admin.performance.settings', {
                        ms: slow_query_ms,
                        rate: sample_rate,
                    })}
                </p>

                <nav
                    className="flex flex-wrap gap-2"
                    aria-label={t('admin.performance.window.label')}
                >
                    {windows.map((value) => (
                        <Button
                            key={value}
                            variant={value === window ? 'default' : 'outline'}
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={performanceIndex({
                                    query: { window: value },
                                })}
                                aria-current={
                                    value === window ? 'page' : undefined
                                }
                                preserveScroll
                            >
                                {t(WINDOW_KEYS[value])}
                            </Link>
                        </Button>
                    ))}
                </nav>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <AdminStatTile
                        label={t('admin.performance.totals.requests')}
                        value={report.totals.requests}
                    />
                    <AdminStatTile
                        label={t('admin.performance.totals.request_errors')}
                        value={report.totals.request_errors}
                    />
                    <AdminStatTile
                        label={t('admin.performance.totals.jobs')}
                        value={report.totals.jobs}
                    />
                    <AdminStatTile
                        label={t('admin.performance.totals.job_failures')}
                        value={report.totals.job_failures}
                    />
                    <AdminStatTile
                        label={t('admin.performance.totals.traced_games')}
                        value={report.totals.traced_games}
                    />
                </div>

                <GroupCard
                    title={t('admin.performance.requests.heading')}
                    description={t('admin.performance.requests.description')}
                    rows={report.requests}
                    ms={ms}
                    showWait={false}
                />

                <GroupCard
                    title={t('admin.performance.jobs.heading')}
                    description={t('admin.performance.jobs.description')}
                    rows={report.jobs}
                    ms={ms}
                    showWait
                />

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.performance.engine.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.performance.engine.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {report.engine.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.performance.empty')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.event',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.count',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.delay_p50',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.delay_p95',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.delay_max',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.duration_p95',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.performance.column.queries',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {report.engine.map((row) => (
                                        <TableRow key={row.event}>
                                            <TableCell className="font-mono text-xs">
                                                {row.event}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    row.count,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {ms(row.p50_delay_ms)}
                                            </TableCell>
                                            <TableCell>
                                                {ms(row.p95_delay_ms)}
                                            </TableCell>
                                            <TableCell>
                                                {ms(row.max_delay_ms)}
                                            </TableCell>
                                            <TableCell>
                                                {ms(row.p95_duration_ms)}
                                            </TableCell>
                                            <TableCell>
                                                {row.avg_queries}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.performance.slow_queries.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.performance.slow_queries.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {report.slow_queries.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.performance.empty')}
                            </p>
                        )}
                        {report.slow_queries.map((row) => (
                            <div
                                key={row.sql}
                                className="space-y-1 rounded-md border p-3"
                            >
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <Badge variant="secondary">
                                        {t('admin.performance.column.max')}{' '}
                                        {ms(row.max_ms)}
                                    </Badge>
                                    <Badge variant="outline">
                                        {t('admin.performance.column.avg')}{' '}
                                        {ms(row.avg_ms)}
                                    </Badge>
                                    <Badge variant="outline">
                                        {t('admin.performance.column.count')}{' '}
                                        {formatInteger(row.count, locale)}
                                    </Badge>
                                    {row.context !== null && (
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {row.context}
                                        </span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {t('admin.performance.column.last')}{' '}
                                        {formatMoment(row.last_at, locale)}
                                    </span>
                                </div>
                                <pre className="overflow-x-auto font-mono text-xs break-words whitespace-pre-wrap text-foreground">
                                    {row.sql}
                                </pre>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminPerformanceIndex.layout = { breadcrumbs };

/** Un tableau d'agrégats par nom (route ou classe de job). */
function GroupCard({
    title,
    description,
    rows,
    ms,
    showWait,
}: {
    title: string;
    description: string;
    rows: PerfGroupRow[];
    ms: (value: number | null) => string;
    showWait: boolean;
}) {
    const { t, locale } = useTranslations();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>{title}</AdminCardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                {rows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.performance.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('admin.performance.column.name')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.count')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.p50')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.p95')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.max')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.queries')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.query_ms')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.memory')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.performance.column.errors')}
                                </TableHead>
                                {showWait && (
                                    <TableHead>
                                        {t('admin.performance.column.wait')}
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.name}>
                                    <TableCell className="font-mono text-xs break-all">
                                        {row.name}
                                    </TableCell>
                                    <TableCell>
                                        {formatInteger(row.count, locale)}
                                    </TableCell>
                                    <TableCell>{ms(row.p50_ms)}</TableCell>
                                    <TableCell>{ms(row.p95_ms)}</TableCell>
                                    <TableCell>{ms(row.max_ms)}</TableCell>
                                    <TableCell>{row.avg_queries}</TableCell>
                                    <TableCell>{row.avg_query_ms}</TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {t('admin.performance.kb', {
                                            value: formatInteger(
                                                row.max_memory_kb,
                                                locale,
                                            ),
                                        })}
                                    </TableCell>
                                    <TableCell>
                                        {row.errors > 0 ? (
                                            <Badge variant="destructive">
                                                {formatInteger(
                                                    row.errors,
                                                    locale,
                                                )}
                                            </Badge>
                                        ) : (
                                            formatInteger(row.errors, locale)
                                        )}
                                    </TableCell>
                                    {showWait && (
                                        <TableCell>
                                            {ms(row.p95_wait_ms)}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
