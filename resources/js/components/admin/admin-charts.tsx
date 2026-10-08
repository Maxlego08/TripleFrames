import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    XAxis,
    YAxis,
} from 'recharts';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger } from '@/lib/admin-format';
import { cn } from '@/lib/utils';

/**
 * Les graphiques du back-office (écrans « Audience » et « Statistiques de
 * jeu », spec 20 § 12.4 et § 12.6, demande du porteur du 08/10), sur le
 * composant `chart` de shadcn (recharts).
 *
 * Couleurs aux seuls tokens `--chart-1` à `--chart-5` du thème (règle 5).
 * Un graphique est une image pour les lecteurs d'écran (`role="img"` et
 * son nom) : chaque écran garde à côté le tableau des mêmes données.
 */

/** Un des cinq tokens de couleur de graphique du thème. */
export type AdminChartColor = 1 | 2 | 3 | 4 | 5;

export type AdminChartSeries = {
    key: string;
    label: string;
    color: AdminChartColor;
};

type Row = Record<string, string | number>;

function chartConfig(series: AdminChartSeries[]): ChartConfig {
    return Object.fromEntries(
        series.map((serie) => [
            serie.key,
            { label: serie.label, color: `var(--chart-${serie.color})` },
        ]),
    );
}

/**
 * Une série temporelle ou catégorielle : aires superposées (`area`) ou
 * barres empilées (`stacked`) ou côte à côte (`bar`), sur l'axe `xKey`.
 */
export function AdminSeriesChart({
    label,
    data,
    xKey,
    series,
    kind,
    formatX,
    className,
}: {
    /** Nom accessible du graphique. */
    label: string;
    data: Row[];
    xKey: string;
    series: AdminChartSeries[];
    kind: 'area' | 'stacked' | 'bar';
    formatX?: (value: string) => string;
    className?: string;
}) {
    const { locale } = useTranslations();
    const config = chartConfig(series);
    const tickX = (value: string | number): string =>
        formatX === undefined ? String(value) : formatX(String(value));
    const tickY = (value: number): string => formatInteger(value, locale);

    const axes = (
        <>
            <CartesianGrid vertical={false} />
            <XAxis
                dataKey={xKey}
                tickLine={false}
                axisLine={false}
                tickMargin={8}
                minTickGap={16}
                tickFormatter={tickX}
            />
            <YAxis
                tickLine={false}
                axisLine={false}
                width={40}
                allowDecimals={false}
                tickFormatter={tickY}
            />
            <ChartTooltip
                content={
                    <ChartTooltipContent
                        labelFormatter={(value) => tickX(value as string)}
                    />
                }
            />
            {series.length > 1 && (
                <ChartLegend content={<ChartLegendContent />} />
            )}
        </>
    );

    return (
        <ChartContainer
            config={config}
            role="img"
            aria-label={label}
            className={cn('aspect-auto h-64 w-full', className)}
        >
            {kind === 'area' ? (
                <AreaChart data={data} accessibilityLayer>
                    {axes}
                    {series.map((serie) => (
                        <Area
                            key={serie.key}
                            dataKey={serie.key}
                            type="monotone"
                            stroke={`var(--color-${serie.key})`}
                            fill={`var(--color-${serie.key})`}
                            fillOpacity={0.15}
                            strokeWidth={2}
                        />
                    ))}
                </AreaChart>
            ) : (
                <BarChart data={data} accessibilityLayer>
                    {axes}
                    {series.map((serie, index) => (
                        <Bar
                            key={serie.key}
                            dataKey={serie.key}
                            stackId={kind === 'stacked' ? 'stack' : undefined}
                            fill={`var(--color-${serie.key})`}
                            radius={
                                kind === 'bar' || index === series.length - 1
                                    ? 4
                                    : 0
                            }
                        />
                    ))}
                </BarChart>
            )}
        </ChartContainer>
    );
}

/**
 * Une répartition en barres horizontales : un nom lisible et un total par
 * ligne, dans l'ordre reçu.
 */
export function AdminDistributionChart({
    label,
    rows,
    valueLabel,
    color = 1,
    className,
}: {
    label: string;
    rows: { name: string; total: number }[];
    valueLabel: string;
    color?: AdminChartColor;
    className?: string;
}) {
    const { t } = useTranslations();
    const config = chartConfig([{ key: 'total', label: valueLabel, color }]);

    if (rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                {t('admin.audience.empty')}
            </p>
        );
    }

    return (
        <ChartContainer
            config={config}
            role="img"
            aria-label={label}
            className={cn('aspect-auto w-full', className)}
            style={{ height: `${Math.max(rows.length, 2) * 2.25 + 1}rem` }}
        >
            <BarChart data={rows} layout="vertical" accessibilityLayer>
                <XAxis type="number" hide allowDecimals={false} />
                <YAxis
                    type="category"
                    dataKey="name"
                    tickLine={false}
                    axisLine={false}
                    width={128}
                />
                <ChartTooltip
                    content={<ChartTooltipContent hideLabel={false} />}
                />
                <Bar dataKey="total" fill="var(--color-total)" radius={4} />
            </BarChart>
        </ChartContainer>
    );
}
