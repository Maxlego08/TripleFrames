import { AdminCardTitle } from '@/components/admin/admin-card-title';
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
import { formatInteger } from '@/lib/admin-format';
import type { InspectionTraceLine } from '@/types/admin';

/** Une heure à la milliseconde, dans la locale : la chronologie se lit au ms. */
function preciseTime(iso: string | null, locale: string): string | null {
    if (iso === null) {
        return null;
    }

    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        fractionalSecondDigits: 3,
    }).format(date);
}

/**
 * La chronologie technique d'une partie (D47 du 01/10, `game_trace`) :
 * transitions, diffusions et leur retard sur l'instant prévu, jobs de
 * frontière, soumissions et resynchronisations, avec durée et requêtes SQL.
 * Un retard positif est mis en avant : c'est ce qui se ressent en jeu.
 */
export function InspectionTrace({ lines }: { lines: InspectionTraceLine[] }) {
    const { t, locale } = useTranslations();
    const none = t('admin.common.none');
    const ms = (value: number | null): string =>
        value === null
            ? none
            : t('admin.inspection.trace.ms', {
                  value: formatInteger(value, locale),
              });

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.inspection.trace.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.inspection.trace.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                {lines.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.inspection.trace.empty')}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {t('admin.inspection.trace.column.at')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.event')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.round')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.tier')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.player')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.delay')}
                                </TableHead>
                                <TableHead>
                                    {t(
                                        'admin.inspection.trace.column.duration',
                                    )}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.queries')}
                                </TableHead>
                                <TableHead>
                                    {t('admin.inspection.trace.column.details')}
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((line, index) => (
                                <TableRow key={index}>
                                    <TableCell className="font-mono text-xs whitespace-nowrap">
                                        {preciseTime(line.recorded_at, locale)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.event}
                                    </TableCell>
                                    <TableCell>
                                        {line.sequence_index ?? none}
                                    </TableCell>
                                    <TableCell>
                                        {line.tier_index ?? none}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {line.player_id ?? none}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            line.delay_ms !== null &&
                                            line.delay_ms > 0
                                                ? 'font-medium text-foreground'
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        {ms(line.delay_ms)}
                                    </TableCell>
                                    <TableCell>
                                        {ms(line.duration_ms)}
                                    </TableCell>
                                    <TableCell>
                                        {line.query_count ?? none}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs break-all">
                                        {Object.entries(line.details)
                                            .filter(
                                                ([, value]) => value !== null,
                                            )
                                            .map(
                                                ([key, value]) =>
                                                    `${key}=${String(value)}`,
                                            )
                                            .join(' ')}
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
