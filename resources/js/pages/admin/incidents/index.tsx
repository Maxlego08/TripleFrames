import { Head, Link } from '@inertiajs/react';
import { SirenIcon } from 'lucide-react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { INSPECTION_INCIDENT_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { show as catalogShow } from '@/routes/admin/catalog';
import { index as incidentsIndex } from '@/routes/admin/incidents';
import type {
    AdminIncidentRow,
    InspectionIncidentReason,
    Paginated,
} from '@/types/admin';

type Props = {
    window_days: number;
    reasons: InspectionIncidentReason[];
    incidents: Paginated<AdminIncidentRow>;
};

type ReasonCounts = Partial<Record<InspectionIncidentReason, number>>;

/**
 * Films jamais trouvés et incidents (spec 20 § 12.1, L20-29). Agrégat par
 * film sur la fenêtre glissante : aucun pseudo, siège ni partie ne traverse
 * cette page. Chaque ligne mène à la fiche du film.
 */
export default function AdminIncidentsIndex({
    window_days,
    reasons,
    incidents,
}: Props) {
    const { t, locale } = useTranslations();

    const reasonList = (counts: ReasonCounts) => {
        const present = reasons.filter((reason) => (counts[reason] ?? 0) > 0);

        if (present.length === 0) {
            return (
                <span className="text-muted-foreground">
                    {t('admin.incidents.none')}
                </span>
            );
        }

        return (
            <ul className="flex flex-col gap-1">
                {present.map((reason) => (
                    <li key={reason}>
                        {t('admin.incidents.reason_count', {
                            reason: t(INSPECTION_INCIDENT_KEYS[reason]),
                            count: formatInteger(counts[reason] ?? 0, locale),
                        })}
                    </li>
                ))}
            </ul>
        );
    };

    return (
        <>
            <Head title={t('admin.incidents.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.incidents.heading')}
                    description={t('admin.incidents.description', {
                        days: formatInteger(window_days, locale),
                    })}
                />

                {incidents.data.length === 0 ? (
                    <AdminEmptyState
                        icon={SirenIcon}
                        title={t('admin.incidents.empty')}
                    />
                ) : (
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.incidents.list')}
                            </AdminCardTitle>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.incidents.reaction')}
                            </p>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.incidents.column.movie',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.incidents.column.never_found',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.incidents.column.cancelled',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.incidents.column.substituted',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {incidents.data.map((row) => (
                                            <TableRow key={row.movie.id}>
                                                <TableCell>
                                                    <Button
                                                        variant="link"
                                                        className="h-auto min-h-11 justify-start p-0 text-left"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={catalogShow(
                                                                row.movie.id,
                                                            )}
                                                        >
                                                            {
                                                                row.movie
                                                                    .title_original
                                                            }
                                                            {row.movie
                                                                .release_year !==
                                                                null && (
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    (
                                                                    {
                                                                        row
                                                                            .movie
                                                                            .release_year
                                                                    }
                                                                    )
                                                                </span>
                                                            )}
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                                <TableCell>
                                                    {row.completed === 0 ? (
                                                        <span className="text-muted-foreground">
                                                            {t(
                                                                'admin.incidents.none',
                                                            )}
                                                        </span>
                                                    ) : (
                                                        t(
                                                            'admin.incidents.never_found',
                                                            {
                                                                never: formatInteger(
                                                                    row.never_found,
                                                                    locale,
                                                                ),
                                                                completed:
                                                                    formatInteger(
                                                                        row.completed,
                                                                        locale,
                                                                    ),
                                                            },
                                                        )
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {reasonList(row.cancelled)}
                                                </TableCell>
                                                <TableCell>
                                                    {reasonList(
                                                        row.substituted,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>

                            <AdminPagination
                                meta={incidents.meta}
                                href={(page) =>
                                    incidentsIndex({ query: { page } })
                                }
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
