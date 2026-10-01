import { Form, Head, Link } from '@inertiajs/react';
import { UsersIcon } from 'lucide-react';
import PlayerInspectionController from '@/actions/App/Http/Controllers/Admin/PlayerInspectionController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { AdminSelect } from '@/components/admin/admin-select';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import {
    index as playersIndex,
    show as playersShow,
} from '@/routes/admin/players';
import { show as usersShow } from '@/routes/admin/users';
import type {
    InspectionPlayerFilters,
    InspectionPlayerRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    players: Paginated<InspectionPlayerRow>;
    filters: InspectionPlayerFilters;
    options: { mode: Array<'room' | 'solo'> };
    counts: { total: number; solo: number };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.players', href: playersIndex() },
];

/**
 * L'annuaire des sièges, invités compris — spec 20 § 12.2 (D46 du 01/10),
 * **administrateur seul**, en lecture seule. Chaque visite est consignée.
 */
export default function AdminPlayersIndex({
    players,
    filters,
    options,
    counts,
}: Props) {
    const { t, locale } = useTranslations();
    const filtered = filters.q !== null || filters.mode !== null;

    return (
        <>
            <Head title={t('admin.inspection.players.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.inspection.players.heading')}
                    description={t('admin.inspection.players.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                <p className="text-sm text-muted-foreground">
                    {t('admin.inspection.read_notice')}
                </p>

                <div className="grid gap-3 sm:grid-cols-2">
                    <AdminStatTile
                        label={t('admin.inspection.players.counts.total')}
                        value={counts.total}
                    />
                    <AdminStatTile
                        label={t('admin.inspection.players.counts.solo')}
                        value={counts.solo}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.inspection.players.filters.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...PlayerInspectionController.index.form()}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            className="grid gap-4 md:grid-cols-3"
                        >
                            <div className="space-y-1.5">
                                <Label htmlFor="players-q">
                                    {t(
                                        'admin.inspection.players.filters.search',
                                    )}
                                </Label>
                                <Input
                                    id="players-q"
                                    name="q"
                                    type="search"
                                    defaultValue={filters.q ?? ''}
                                    placeholder={t(
                                        'admin.inspection.players.filters.search_placeholder',
                                    )}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="players-mode">
                                    {t('admin.inspection.players.filters.mode')}
                                </Label>
                                <AdminSelect
                                    id="players-mode"
                                    name="mode"
                                    defaultValue={filters.mode ?? ''}
                                    options={[
                                        {
                                            value: '',
                                            label: t('admin.common.all'),
                                        },
                                        ...options.mode.map((value) => ({
                                            value,
                                            label: t(
                                                value === 'solo'
                                                    ? 'admin.inspection.players.filters.solo'
                                                    : 'admin.inspection.players.filters.room',
                                            ),
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="flex flex-wrap items-end gap-2">
                                <Button type="submit" className="min-h-11">
                                    {t(
                                        'admin.inspection.players.filters.submit',
                                    )}
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="min-h-11"
                                    asChild
                                >
                                    <Link href={playersIndex()}>
                                        {t(
                                            'admin.inspection.players.filters.reset',
                                        )}
                                    </Link>
                                </Button>
                            </div>
                        </Form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.inspection.players.list.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.inspection.players.list.results', {
                                total: formatInteger(
                                    players.meta.total,
                                    locale,
                                ),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {players.data.length === 0 && (
                            <AdminEmptyState
                                icon={UsersIcon}
                                title={t(
                                    'admin.inspection.players.empty.heading',
                                )}
                                description={t(
                                    filtered
                                        ? 'admin.inspection.players.empty.filtered'
                                        : 'admin.inspection.players.empty.none',
                                )}
                            />
                        )}

                        {players.data.length > 0 && (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.nickname',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.origin',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.account',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.games',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.joined_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.players.column.last_seen_at',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {players.data.map((player) => (
                                            <TableRow key={player.public_id}>
                                                <TableCell className="align-top">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Link
                                                            href={playersShow(
                                                                player.public_id,
                                                            )}
                                                            className="font-medium text-foreground underline-offset-4 hover:underline"
                                                        >
                                                            {player.nickname ??
                                                                t(
                                                                    'admin.inspection.erased',
                                                                )}
                                                        </Link>
                                                        {player.masked && (
                                                            <Badge variant="secondary">
                                                                {t(
                                                                    'admin.inspection.masked',
                                                                )}
                                                            </Badge>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {player.solo ? (
                                                        <Badge variant="outline">
                                                            {t(
                                                                'admin.inspection.solo',
                                                            )}
                                                        </Badge>
                                                    ) : (
                                                        <span className="font-mono text-sm">
                                                            {player.room_code ??
                                                                t(
                                                                    'admin.common.none',
                                                                )}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {player.user === null ? (
                                                        t('admin.common.none')
                                                    ) : (
                                                        <Link
                                                            href={usersShow(
                                                                player.user.id,
                                                            )}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {player.user.name}
                                                        </Link>
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {formatInteger(
                                                        player.games_count,
                                                        locale,
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        player.joined_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.common.unknown',
                                                        )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        player.last_seen_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.common.unknown',
                                                        )}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                <AdminPagination
                                    meta={players.meta}
                                    href={(page) =>
                                        playersIndex({
                                            query: playersQuery(filters, page),
                                        })
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

AdminPlayersIndex.layout = { breadcrumbs };

/** La query string de l'annuaire : paginer ne perd jamais une recherche. */
function playersQuery(
    filters: InspectionPlayerFilters,
    page: number,
): Record<string, string | number> {
    const query: Record<string, string | number> = {};

    if (filters.q !== null) {
        query.q = filters.q;
    }

    if (filters.mode !== null) {
        query.mode = filters.mode;
    }

    if (page > 1) {
        query.page = page;
    }

    return query;
}
