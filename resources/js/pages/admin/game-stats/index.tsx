import { Head, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
import {
    formatDay,
    formatInteger,
    formatPercent,
    formatPreciseDuration,
} from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { show as catalogShow } from '@/routes/admin/catalog';
import { index as gameStatsIndex } from '@/routes/admin/game-stats';
import type {
    AudienceRanked,
    AudienceWindow,
    GameStatsMovie,
    GameStatsReport,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    report: GameStatsReport;
    window: AudienceWindow;
    windows: AudienceWindow[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.game_stats', href: gameStatsIndex() },
];

const WINDOW_KEYS: Record<AudienceWindow, TranslationKey> = {
    '7d': 'admin.audience.window.7d',
    '30d': 'admin.audience.window.30d',
    '90d': 'admin.audience.window.90d',
};

const DIFFICULTY_KEYS: Partial<Record<string, TranslationKey>> = {
    easy: 'admin.game_stats.difficulty.easy',
    normal: 'admin.game_stats.difficulty.normal',
    expert: 'admin.game_stats.difficulty.expert',
};

const SOURCE_KEYS: Partial<Record<string, TranslationKey>> = {
    text: 'admin.game_stats.sources.text',
    choice: 'admin.game_stats.sources.choice',
};

/** Une seconde en millisecondes : une unité, pas une valeur de jeu. */
const MS_PER_SECOND = 1000;

/** Minutes d'une heure : décalage du fuseau du navigateur. */
const MINUTES_PER_HOUR = 60;

/** Heures d'une journée. */
const HOURS_PER_DAY = 24;

/**
 * Les statistiques de jeu — spec 20 § 12.6 (demande du porteur du 08/10),
 * **administrateur seul** : parties lancées sur la fenêtre, leur rythme et
 * leur issue, les réglages choisis, les réponses trouvées et les films.
 * Des agrégats seulement ; chaque graphique garde ses chiffres à côté.
 */
export default function AdminGameStatsIndex({
    report,
    window,
    windows,
}: Props) {
    const { t, locale } = useTranslations();
    const totals = report.totals;
    const decimal = new Intl.NumberFormat(locale, { maximumFractionDigits: 1 });
    const formatShortDay = (day: string): string =>
        formatDay(day, locale) ?? day;
    const none = t('admin.common.none');

    // Heures UTC décalées dans le fuseau du navigateur quand il est à l'heure
    // ronde ; sinon elles restent en UTC, et l'écran le dit.
    const offsetMinutes = -new Date().getTimezoneOffset();
    const shiftHours =
        offsetMinutes % MINUTES_PER_HOUR === 0
            ? offsetMinutes / MINUTES_PER_HOUR
            : 0;
    const hours = report.hours
        .map((row) => ({
            hour: (row.hour + shiftHours + HOURS_PER_DAY) % HOURS_PER_DAY,
            games: row.games,
        }))
        .sort((a, b) => a.hour - b.hour)
        .map((row) => ({
            ...row,
            label: t('admin.game_stats.hours.hour', {
                hour: String(row.hour),
            }),
        }));

    const label = (
        rows: AudienceRanked[],
        keys: Partial<Record<string, TranslationKey>>,
    ): AudienceRanked[] =>
        rows.map((row) => {
            const key = keys[row.name];

            return {
                name: key === undefined ? row.name : t(key),
                total: row.total,
            };
        });

    return (
        <>
            <Head title={t('admin.game_stats.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.game_stats.heading')}
                    description={t('admin.game_stats.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

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
                                href={gameStatsIndex({
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

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <AdminStatTile
                        label={t('admin.game_stats.totals.games')}
                        value={totals.games}
                        hint={
                            totals.running > 0
                                ? t('admin.game_stats.totals.running', {
                                      count: formatInteger(
                                          totals.running,
                                          locale,
                                      ),
                                  })
                                : undefined
                        }
                    />
                    <AdminStatTile
                        label={t('admin.game_stats.totals.multiplayer')}
                        value={totals.multiplayer}
                    />
                    <AdminStatTile
                        label={t('admin.game_stats.totals.solo')}
                        value={totals.solo}
                    />
                    <TextTile
                        label={t('admin.game_stats.totals.completion_rate')}
                        value={
                            totals.games === 0
                                ? none
                                : formatPercent(
                                      (totals.completed / totals.games) * 100,
                                      locale,
                                  )
                        }
                    />
                    <TextTile
                        label={t('admin.game_stats.totals.players_per_game')}
                        value={
                            totals.players_per_game === null
                                ? none
                                : decimal.format(totals.players_per_game)
                        }
                    />
                    <TextTile
                        label={t('admin.game_stats.totals.average_duration')}
                        value={
                            totals.average_duration_ms === null
                                ? none
                                : formatPreciseDuration(
                                      Math.round(
                                          totals.average_duration_ms /
                                              MS_PER_SECOND,
                                      ),
                                      locale,
                                  )
                        }
                    />
                    <TextTile
                        label={t('admin.game_stats.totals.find_rate')}
                        value={
                            totals.find_rate === null
                                ? none
                                : formatPercent(totals.find_rate * 100, locale)
                        }
                        hint={t('admin.game_stats.totals.find_rate_hint', {
                            finds: formatInteger(totals.finds, locale),
                            participations: formatInteger(
                                totals.participations,
                                locale,
                            ),
                        })}
                    />
                    <TextTile
                        label={t('admin.game_stats.totals.average_find')}
                        value={
                            totals.average_find_ms === null
                                ? none
                                : formatPreciseDuration(
                                      Math.round(
                                          totals.average_find_ms /
                                              MS_PER_SECOND,
                                      ),
                                      locale,
                                  )
                        }
                        hint={
                            totals.wrong_per_participation === null
                                ? undefined
                                : t('admin.game_stats.totals.wrong_hint', {
                                      count: decimal.format(
                                          totals.wrong_per_participation,
                                      ),
                                  })
                        }
                    />
                </div>

                {totals.games === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.game_stats.empty')}
                    </p>
                )}

                <div className="grid gap-6 xl:grid-cols-2">
                    <ChartCard
                        title={t('admin.game_stats.daily.heading')}
                        description={t('admin.game_stats.daily.description')}
                    >
                        <AdminSeriesChart
                            label={t('admin.game_stats.daily.heading')}
                            data={report.daily}
                            xKey="day"
                            kind="stacked"
                            formatX={formatShortDay}
                            series={[
                                {
                                    key: 'multiplayer',
                                    label: t(
                                        'admin.game_stats.series.multiplayer',
                                    ),
                                    color: 1,
                                },
                                {
                                    key: 'solo',
                                    label: t('admin.game_stats.series.solo'),
                                    color: 2,
                                },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title={t('admin.game_stats.outcome.heading')}
                        description={t('admin.game_stats.outcome.description')}
                    >
                        <AdminSeriesChart
                            label={t('admin.game_stats.outcome.heading')}
                            data={report.daily}
                            xKey="day"
                            kind="stacked"
                            formatX={formatShortDay}
                            series={[
                                {
                                    key: 'completed',
                                    label: t(
                                        'admin.game_stats.series.completed',
                                    ),
                                    color: 2,
                                },
                                {
                                    key: 'interrupted',
                                    label: t(
                                        'admin.game_stats.series.interrupted',
                                    ),
                                    color: 5,
                                },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title={t('admin.game_stats.hours.heading')}
                        description={t(
                            shiftHours === 0 && offsetMinutes !== 0
                                ? 'admin.game_stats.hours.utc'
                                : 'admin.game_stats.hours.local',
                        )}
                    >
                        <AdminSeriesChart
                            label={t('admin.game_stats.hours.heading')}
                            data={hours}
                            xKey="label"
                            kind="bar"
                            series={[
                                {
                                    key: 'games',
                                    label: t('admin.game_stats.series.games'),
                                    color: 1,
                                },
                            ]}
                        />
                    </ChartCard>

                    <ChartCard
                        title={t('admin.game_stats.tiers.heading')}
                        description={t('admin.game_stats.tiers.description')}
                    >
                        <AdminSeriesChart
                            label={t('admin.game_stats.tiers.heading')}
                            data={report.tiers.map((row) => ({
                                tier: t('admin.game_stats.tiers.tier', {
                                    index: String(row.tier),
                                }),
                                finds: row.finds,
                            }))}
                            xKey="tier"
                            kind="bar"
                            series={[
                                {
                                    key: 'finds',
                                    label: t('admin.game_stats.series.finds'),
                                    color: 4,
                                },
                            ]}
                        />
                    </ChartCard>
                </div>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.game_stats.settings.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-6 lg:grid-cols-2 xl:grid-cols-4">
                        <Distribution
                            title={t('admin.game_stats.settings.difficulty')}
                            rows={label(report.difficulty, DIFFICULTY_KEYS)}
                            valueLabel={t('admin.game_stats.series.games')}
                        />
                        <Distribution
                            title={t('admin.game_stats.settings.frames')}
                            rows={report.frames}
                            valueLabel={t('admin.game_stats.series.games')}
                        />
                        <Distribution
                            title={t('admin.game_stats.settings.rounds')}
                            rows={report.rounds_count}
                            valueLabel={t('admin.game_stats.series.games')}
                        />
                        <Distribution
                            title={t('admin.game_stats.sources.heading')}
                            rows={label(report.sources, SOURCE_KEYS)}
                            valueLabel={t('admin.game_stats.series.finds')}
                        />
                    </CardContent>
                </Card>

                <p className="text-xs text-muted-foreground">
                    {t('admin.game_stats.movies.note', {
                        count: formatInteger(
                            report.rated_min_participations,
                            locale,
                        ),
                    })}
                </p>

                <div className="grid gap-6 xl:grid-cols-3">
                    <MoviesCard
                        title={t('admin.game_stats.movies.played')}
                        movies={report.movies.played}
                    />
                    <MoviesCard
                        title={t('admin.game_stats.movies.hardest')}
                        movies={report.movies.hardest}
                    />
                    <MoviesCard
                        title={t('admin.game_stats.movies.easiest')}
                        movies={report.movies.easiest}
                    />
                </div>
            </div>
        </>
    );
}

AdminGameStatsIndex.layout = { breadcrumbs };

/** Une tuile de chiffre déjà mis en forme (pourcentage, durée, moyenne). */
function TextTile({
    label,
    value,
    hint,
}: {
    label: string;
    value: string;
    hint?: string;
}) {
    return (
        <div className="flex flex-col gap-1 rounded-lg border border-border bg-card p-4">
            <span className="text-xs font-medium text-muted-foreground">
                {label}
            </span>
            <span className="text-2xl font-semibold text-card-foreground tabular-nums">
                {value}
            </span>
            {hint !== undefined && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
        </div>
    );
}

function ChartCard({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>{title}</AdminCardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/** Une répartition : son titre, ses barres, puis ses chiffres. */
function Distribution({
    title,
    rows,
    valueLabel,
}: {
    title: string;
    rows: AudienceRanked[];
    valueLabel: string;
}) {
    const { locale } = useTranslations();

    return (
        <section className="space-y-3">
            <h3 className="text-sm font-medium text-foreground">{title}</h3>
            <AdminDistributionChart
                label={title}
                rows={rows}
                valueLabel={valueLabel}
                color={3}
            />
            {rows.length > 0 && (
                <ul className="space-y-1 text-sm text-muted-foreground">
                    {rows.map((row) => (
                        <li
                            key={row.name}
                            className="flex justify-between gap-4"
                        >
                            <span>{row.name}</span>
                            <span className="tabular-nums">
                                {formatInteger(row.total, locale)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** Un classement de films, chacun vers sa fiche du catalogue. */
function MoviesCard({
    title,
    movies,
}: {
    title: string;
    movies: GameStatsMovie[];
}) {
    const { t, locale } = useTranslations();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>{title}</AdminCardTitle>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                {movies.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.audience.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('admin.game_stats.movies.movie')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('admin.game_stats.movies.rounds')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('admin.game_stats.movies.find_rate')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movies.map((movie) => (
                                <TableRow key={movie.id}>
                                    <TableCell>
                                        <Link
                                            href={catalogShow(movie.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {movie.title}
                                        </Link>
                                        {movie.year !== null && (
                                            <span className="ms-2 text-muted-foreground tabular-nums">
                                                {movie.year}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatInteger(movie.rounds, locale)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {movie.find_rate === null
                                            ? t('admin.common.none')
                                            : formatPercent(
                                                  movie.find_rate * 100,
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
    );
}
