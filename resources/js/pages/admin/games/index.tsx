import { Form, Head, Link } from '@inertiajs/react';
import { GamepadIcon } from 'lucide-react';
import GameInspectionController from '@/actions/App/Http/Controllers/Admin/GameInspectionController';
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
import {
    INSPECTION_DIFFICULTY_KEYS,
    INSPECTION_GAME_MODE_KEYS,
    INSPECTION_GAME_STATUS_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as gamesIndex, show as gamesShow } from '@/routes/admin/games';
import type {
    InspectionGameFilters,
    InspectionGameMode,
    InspectionGameRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    games: Paginated<InspectionGameRow>;
    filters: InspectionGameFilters;
    options: { state: string[]; mode: InspectionGameMode[] };
    counts: { running: number; ended: number };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.games', href: gamesIndex() },
];

/**
 * Les parties, en cours et terminées, salon et solo — spec 20 § 12.2
 * (D46 du 01/10), **administrateur seul**, en lecture seule et pilotée par
 * l'URL. Chaque visite est consignée au journal.
 */
export default function AdminGamesIndex({
    games,
    filters,
    options,
    counts,
}: Props) {
    const { t, locale } = useTranslations();
    const filtered = filters.mode !== null || filters.room !== null;

    return (
        <>
            <Head title={t('admin.inspection.games.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.inspection.games.heading')}
                    description={t('admin.inspection.games.description')}
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
                        label={t('admin.inspection.games.counts.running')}
                        value={counts.running}
                    />
                    <AdminStatTile
                        label={t('admin.inspection.games.counts.ended')}
                        value={counts.ended}
                    />
                </div>

                <nav
                    className="flex flex-wrap gap-2"
                    aria-label={t('admin.inspection.games.heading')}
                >
                    {(['running', 'ended'] as const).map((state) => (
                        <Button
                            key={state}
                            variant={
                                filters.state === state ? 'default' : 'outline'
                            }
                            className="min-h-11"
                            asChild
                        >
                            <Link
                                href={gamesIndex({ query: { state } })}
                                aria-current={
                                    filters.state === state ? 'page' : undefined
                                }
                            >
                                {t(
                                    state === 'running'
                                        ? 'admin.inspection.games.tabs.running'
                                        : 'admin.inspection.games.tabs.ended',
                                )}
                            </Link>
                        </Button>
                    ))}
                </nav>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.inspection.games.filters.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...GameInspectionController.index.form()}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            className="grid gap-4 md:grid-cols-3"
                        >
                            <input
                                type="hidden"
                                name="state"
                                value={filters.state}
                            />
                            <div className="space-y-1.5">
                                <Label htmlFor="games-mode">
                                    {t('admin.inspection.games.filters.mode')}
                                </Label>
                                <AdminSelect
                                    id="games-mode"
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
                                                INSPECTION_GAME_MODE_KEYS[
                                                    value
                                                ],
                                            ),
                                        })),
                                    ]}
                                />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="games-room">
                                    {t('admin.inspection.games.filters.room')}
                                </Label>
                                <Input
                                    id="games-room"
                                    name="room"
                                    maxLength={6}
                                    autoComplete="off"
                                    defaultValue={filters.room ?? ''}
                                    placeholder={t(
                                        'admin.inspection.games.filters.room_placeholder',
                                    )}
                                />
                            </div>
                            <div className="flex flex-wrap items-end gap-2">
                                <Button type="submit" className="min-h-11">
                                    {t('admin.inspection.games.filters.submit')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    className="min-h-11"
                                    asChild
                                >
                                    <Link
                                        href={gamesIndex({
                                            query: { state: filters.state },
                                        })}
                                    >
                                        {t(
                                            'admin.inspection.games.filters.reset',
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
                            {t('admin.inspection.games.list.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.inspection.games.list.results', {
                                total: formatInteger(games.meta.total, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {games.data.length === 0 && (
                            <AdminEmptyState
                                icon={GamepadIcon}
                                title={t(
                                    'admin.inspection.games.empty.heading',
                                )}
                                description={t(
                                    filtered
                                        ? 'admin.inspection.games.empty.filtered'
                                        : filters.state === 'running'
                                          ? 'admin.inspection.games.empty.running'
                                          : 'admin.inspection.games.empty.ended',
                                )}
                            />
                        )}

                        {games.data.length > 0 && (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.game',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.mode',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.status',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.difficulty',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.progress',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.participants',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.started_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.inspection.games.column.ended_at',
                                                )}
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {games.data.map((game) => (
                                            <TableRow key={game.id}>
                                                <TableCell className="align-top">
                                                    <Link
                                                        href={gamesShow(
                                                            game.id,
                                                        )}
                                                        className="font-medium text-foreground underline-offset-4 hover:underline"
                                                    >
                                                        {t(
                                                            'admin.inspection.games.number',
                                                            { id: game.id },
                                                        )}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge variant="outline">
                                                            {t(
                                                                INSPECTION_GAME_MODE_KEYS[
                                                                    game.mode
                                                                ],
                                                            )}
                                                        </Badge>
                                                        {game.room_code !==
                                                            null && (
                                                            <span className="font-mono text-sm">
                                                                {game.room_code}
                                                            </span>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <Badge
                                                        variant={
                                                            game.ended_at ===
                                                            null
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {t(
                                                            INSPECTION_GAME_STATUS_KEYS[
                                                                game.status
                                                            ],
                                                        )}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {t(
                                                        INSPECTION_DIFFICULTY_KEYS[
                                                            game
                                                                .input_difficulty
                                                        ],
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {t(
                                                        'admin.inspection.games.progress',
                                                        {
                                                            done: game.rounds_completed,
                                                            total: game.rounds_count,
                                                        },
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {formatInteger(
                                                        game.participants_count,
                                                        locale,
                                                    )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        game.started_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.common.unknown',
                                                        )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        game.ended_at,
                                                        locale,
                                                    ) ?? t('admin.common.none')}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                <AdminPagination
                                    meta={games.meta}
                                    href={(page) =>
                                        gamesIndex({
                                            query: gamesQuery(filters, page),
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

AdminGamesIndex.layout = { breadcrumbs };

/** La query string de la liste : paginer ne perd jamais un filtre. */
function gamesQuery(
    filters: InspectionGameFilters,
    page: number,
): Record<string, string | number> {
    const query: Record<string, string | number> = { state: filters.state };

    if (filters.mode !== null) {
        query.mode = filters.mode;
    }

    if (filters.room !== null) {
        query.room = filters.room;
    }

    if (page > 1) {
        query.page = page;
    }

    return query;
}
