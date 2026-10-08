import { Head, Link } from '@inertiajs/react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import {
    AdminDistributionChart,
    AdminSeriesChart,
} from '@/components/admin/admin-charts';
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
import { formatDay, formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as audienceIndex } from '@/routes/admin/audience';
import type {
    AudienceRanked,
    AudienceReport,
    AudienceWindow,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    report: AudienceReport;
    window: AudienceWindow;
    windows: AudienceWindow[];
    enabled: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.audience', href: audienceIndex() },
];

const WINDOW_KEYS: Record<AudienceWindow, TranslationKey> = {
    '7d': 'admin.audience.window.7d',
    '30d': 'admin.audience.window.30d',
    '90d': 'admin.audience.window.90d',
};

const DEVICE_KEYS: Partial<Record<string, TranslationKey>> = {
    mobile: 'admin.audience.device.mobile',
    tablet: 'admin.audience.device.tablet',
    desktop: 'admin.audience.device.desktop',
};

/**
 * L'audience — spec 20 § 12.4 (D48 du 01/10), **administrateur seul** :
 * des compteurs quotidiens sans cookie, le temps réel et l'entonnoir de jeu.
 * Aucune visite ni aucun visiteur n'est montré un à un : il n'en existe pas
 * en base.
 *
 * Graphiques (demande du porteur du 08/10) : fréquentation jour par jour,
 * humains face aux robots, appareils et langues ; chaque graphique garde son
 * tableau de données à côté.
 */
export default function AdminAudienceIndex({
    report,
    window,
    windows,
    enabled,
}: Props) {
    const { t, locale } = useTranslations();
    const formatShortDay = (day: string): string =>
        formatDay(day, locale) ?? day;
    const perVisit =
        report.totals.visits === 0
            ? null
            : report.totals.pageviews / report.totals.visits;

    return (
        <>
            <Head title={t('admin.audience.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.audience.heading')}
                    description={t('admin.audience.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                {!enabled && (
                    <p className="text-sm text-destructive">
                        {t('admin.audience.disabled')}
                    </p>
                )}

                <nav
                    className="flex flex-wrap gap-2"
                    aria-label={t('admin.audience.window.label')}
                >
                    {windows.map((value) => (
                        <Button
                            key={value}
                            variant={value === window ? 'default' : 'outline'}
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={audienceIndex({
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

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.audience.live.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.audience.live.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <AdminStatTile
                                label={t('admin.audience.live.visitors')}
                                value={report.live.visitors}
                            />
                            <AdminStatTile
                                label={t('admin.audience.live.open_rooms')}
                                value={report.live.open_rooms}
                            />
                            <AdminStatTile
                                label={t('admin.audience.live.running_games')}
                                value={report.live.running_games}
                            />
                            <AdminStatTile
                                label={t('admin.audience.live.running_solo')}
                                value={report.live.running_solo}
                            />
                        </div>
                        <RankedTable
                            rows={report.live.pages}
                            nameLabel={t('admin.audience.column.page')}
                            totalLabel={t('admin.audience.column.visitors')}
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <AdminStatTile
                        label={t('admin.audience.totals.visitors')}
                        value={report.totals.visitors}
                    />
                    <AdminStatTile
                        label={t('admin.audience.totals.visits')}
                        value={report.totals.visits}
                    />
                    <AdminStatTile
                        label={t('admin.audience.totals.pageviews')}
                        value={report.totals.pageviews}
                    />
                    <Card>
                        <CardContent className="space-y-1 pt-6">
                            <p className="text-sm text-muted-foreground">
                                {t('admin.audience.totals.per_visit')}
                            </p>
                            <p className="text-2xl font-semibold text-foreground">
                                {perVisit === null
                                    ? t('admin.common.none')
                                    : new Intl.NumberFormat(locale, {
                                          maximumFractionDigits: 1,
                                      }).format(perVisit)}
                            </p>
                        </CardContent>
                    </Card>
                </div>
                <p className="text-xs text-muted-foreground">
                    {t('admin.audience.totals.note')}
                </p>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.audience.charts.traffic.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.audience.charts.traffic.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <AdminSeriesChart
                            label={t('admin.audience.charts.traffic.heading')}
                            data={report.daily}
                            xKey="day"
                            kind="area"
                            formatX={formatShortDay}
                            series={[
                                {
                                    key: 'pageviews',
                                    label: t('admin.audience.totals.pageviews'),
                                    color: 3,
                                },
                                {
                                    key: 'visits',
                                    label: t('admin.audience.totals.visits'),
                                    color: 2,
                                },
                                {
                                    key: 'visitors',
                                    label: t('admin.audience.totals.visitors'),
                                    color: 1,
                                },
                            ]}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.audience.charts.quality.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.audience.totals.filtered_note')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <AdminStatTile
                                label={t('admin.audience.charts.humans')}
                                value={report.totals.pageviews}
                            />
                            <AdminStatTile
                                label={t('admin.audience.totals.bots')}
                                value={report.totals.bots}
                            />
                            <AdminStatTile
                                label={t('admin.audience.totals.unconfirmed')}
                                value={report.totals.unconfirmed}
                            />
                        </div>
                        <AdminSeriesChart
                            label={t('admin.audience.charts.quality.heading')}
                            data={report.daily}
                            xKey="day"
                            kind="stacked"
                            formatX={formatShortDay}
                            series={[
                                {
                                    key: 'pageviews',
                                    label: t('admin.audience.charts.humans'),
                                    color: 2,
                                },
                                {
                                    key: 'unconfirmed',
                                    label: t(
                                        'admin.audience.charts.unconfirmed',
                                    ),
                                    color: 3,
                                },
                                {
                                    key: 'bots',
                                    label: t('admin.audience.charts.bots'),
                                    color: 5,
                                },
                            ]}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.audience.funnel.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.audience.funnel.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                            {(
                                [
                                    [
                                        'admin.audience.funnel.visitors',
                                        report.totals.visitors,
                                    ],
                                    [
                                        'admin.audience.funnel.rooms_created',
                                        report.funnel.rooms_created,
                                    ],
                                    [
                                        'admin.audience.funnel.games_multiplayer',
                                        report.funnel.games_multiplayer,
                                    ],
                                    [
                                        'admin.audience.funnel.games_solo',
                                        report.funnel.games_solo,
                                    ],
                                    [
                                        'admin.audience.funnel.games_completed',
                                        report.funnel.games_completed,
                                    ],
                                ] as Array<[TranslationKey, number]>
                            ).map(([key, value]) => (
                                <div key={key}>
                                    <dt className="text-muted-foreground">
                                        {t(key)}
                                    </dt>
                                    <dd className="text-lg font-semibold text-foreground">
                                        {formatInteger(value, locale)}
                                    </dd>
                                </div>
                            ))}
                            <div>
                                <dt className="text-muted-foreground">
                                    {t(
                                        'admin.audience.funnel.players_per_game',
                                    )}
                                </dt>
                                <dd className="text-lg font-semibold text-foreground">
                                    {report.funnel.players_per_game === null
                                        ? t('admin.common.none')
                                        : new Intl.NumberFormat(locale, {
                                              maximumFractionDigits: 1,
                                          }).format(
                                              report.funnel.players_per_game,
                                          )}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.audience.daily.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {report.daily.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.audience.empty')}
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>
                                            {t('admin.audience.column.day')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.audience.column.visitors',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t('admin.audience.column.visits')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.audience.column.pageviews',
                                            )}
                                        </TableHead>
                                        <TableHead>
                                            {t('admin.audience.column.bots')}
                                        </TableHead>
                                        <TableHead>
                                            {t(
                                                'admin.audience.column.unconfirmed',
                                            )}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {[...report.daily].reverse().map((day) => (
                                        <TableRow key={day.day}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDay(day.day, locale)}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    day.visitors,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    day.visits,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    day.pageviews,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    day.bots,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {formatInteger(
                                                    day.unconfirmed,
                                                    locale,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <RankedCard
                        title={t('admin.audience.pages.heading')}
                        rows={report.pages}
                        nameLabel={t('admin.audience.column.page')}
                        totalLabel={t('admin.audience.column.pageviews')}
                    />
                    <RankedCard
                        title={t('admin.audience.referrers.heading')}
                        rows={report.referrers}
                        nameLabel={t('admin.audience.column.referrer')}
                        totalLabel={t('admin.audience.column.visits')}
                    />
                    <RankedCard
                        title={t('admin.audience.entries.heading')}
                        rows={report.entries}
                        nameLabel={t('admin.audience.column.page')}
                        totalLabel={t('admin.audience.column.visits')}
                    />
                    <RankedCard
                        title={t('admin.audience.exits.heading')}
                        rows={report.exits}
                        nameLabel={t('admin.audience.column.page')}
                        totalLabel={t('admin.audience.column.visits')}
                    />
                    <RankedCard
                        title={t('admin.audience.locales.heading')}
                        rows={report.locales}
                        nameLabel={t('admin.audience.column.locale')}
                        totalLabel={t('admin.audience.column.visitors')}
                        chart
                    />
                    <RankedCard
                        title={t('admin.audience.devices.heading')}
                        rows={report.devices.map((row) => {
                            const key = DEVICE_KEYS[row.name];

                            return {
                                name: key === undefined ? row.name : t(key),
                                total: row.total,
                            };
                        })}
                        nameLabel={t('admin.audience.column.device')}
                        totalLabel={t('admin.audience.column.visitors')}
                        chart
                    />
                    <RankedCard
                        title={t('admin.audience.bots.heading')}
                        description={t('admin.audience.bots.description')}
                        rows={report.bots}
                        nameLabel={t('admin.audience.column.bot')}
                        totalLabel={t('admin.audience.column.pageviews')}
                    />
                </div>
            </div>
        </>
    );
}

AdminAudienceIndex.layout = { breadcrumbs };

/**
 * Une carte de classement : un nom et un total par ligne, précédés de leur
 * graphique en barres quand `chart` est posé.
 */
function RankedCard({
    title,
    description,
    rows,
    nameLabel,
    totalLabel,
    chart = false,
}: {
    title: string;
    description?: string;
    rows: AudienceRanked[];
    nameLabel: string;
    totalLabel: string;
    chart?: boolean;
}) {
    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>{title}</AdminCardTitle>
                {description !== undefined && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="space-y-4 overflow-x-auto">
                {chart && rows.length > 0 && (
                    <AdminDistributionChart
                        label={title}
                        rows={rows}
                        valueLabel={totalLabel}
                    />
                )}
                <RankedTable
                    rows={rows}
                    nameLabel={nameLabel}
                    totalLabel={totalLabel}
                />
            </CardContent>
        </Card>
    );
}

function RankedTable({
    rows,
    nameLabel,
    totalLabel,
}: {
    rows: AudienceRanked[];
    nameLabel: string;
    totalLabel: string;
}) {
    const { t, locale } = useTranslations();

    if (rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('admin.audience.empty')}
            </p>
        );
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{nameLabel}</TableHead>
                    <TableHead className="text-right">{totalLabel}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((row) => (
                    <TableRow key={row.name}>
                        <TableCell className="font-mono text-xs break-all">
                            {row.name === ''
                                ? t('admin.common.none')
                                : row.name}
                        </TableCell>
                        <TableCell className="text-right">
                            {formatInteger(row.total, locale)}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
