import { Form, Head, Link, router } from '@inertiajs/react';
import { UsersIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import UserDirectoryController from '@/actions/App/Http/Controllers/Admin/UserDirectoryController';
import {
    AnonymizedBadge,
    RoleBadge,
    TwoFactorBadge,
    UnverifiedEmailBadge,
} from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import type { AdminSelectOption } from '@/components/admin/admin-select';
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
    ACCOUNT_STATE_KEYS,
    USER_DIRECTORY_SORT_KEYS,
    USER_ROLE_KEYS,
} from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { hasAtLeastRole } from '@/lib/roles';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as usersIndex, show as usersShow } from '@/routes/admin/users';
import type {
    AdminAccountRow,
    AdminUserDirectoryCounts,
    AdminUserDirectoryFilters,
    AdminUserDirectoryOptions,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    users: Paginated<AdminAccountRow>;
    filters: AdminUserDirectoryFilters;
    options: AdminUserDirectoryOptions;
    counts: AdminUserDirectoryCounts;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.users', href: usersIndex() },
];

/**
 * L'annuaire des comptes — spec 20 § 2.8, **administrateur seul**, en lecture
 * seule et entièrement piloté par l'URL.
 *
 * Tous les comptes y figurent, joueurs compris ; une pierre tombale
 * d'anonymisation est signalée, jamais masquée. Les gestes — changer un rôle,
 * corriger un nom réel — se font depuis la fiche d'un compte ou l'écran des
 * accès : aucun bouton d'écriture ici.
 *
 * Comme le catalogue, l'attente d'un filtre, d'un tri ou d'une page
 * s'exprime par un attribut sur le tableau maintenu monté, jamais par un
 * démontage : le focus survit à « Suivant » au clavier.
 */
export default function AdminUsersIndex({
    users,
    filters,
    options,
    counts,
}: Props) {
    const { t, locale } = useTranslations();
    const [busy, setBusy] = useState(false);
    const filtered =
        filters.q !== null || filters.role !== null || filters.state !== null;

    useEffect(() => {
        // `router.on` est GLOBAL : seules les visites de cet écran allument
        // son attente.
        const here = usersIndex().url;
        const concerns = (visited: URL): boolean => visited.pathname === here;

        const stopStart = router.on('start', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(true);
            }
        });

        const stopFinish = router.on('finish', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(false);
            }
        });

        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    return (
        <>
            <Head title={t('admin.users.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.users.heading')}
                    description={t('admin.users.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <AdminStatTile
                        label={t('admin.users.counts.total')}
                        value={counts.total}
                    />
                    <AdminStatTile
                        label={t('admin.users.counts.players')}
                        value={counts.players}
                    />
                    <AdminStatTile
                        label={t('admin.users.counts.curators')}
                        value={counts.curators}
                    />
                    <AdminStatTile
                        label={t('admin.users.counts.admins')}
                        value={counts.admins}
                    />
                </div>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.users.filters.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <UserDirectoryFiltersForm
                            filters={filters}
                            options={options}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.users.list.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.users.results', {
                                total: formatInteger(users.meta.total, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <span
                            role="status"
                            aria-live="polite"
                            className="sr-only"
                        >
                            {busy ? t('admin.common.loading') : ''}
                        </span>

                        {users.data.length === 0 && (
                            <AdminEmptyState
                                icon={UsersIcon}
                                title={t('admin.users.empty.heading')}
                                description={
                                    filtered
                                        ? t('admin.users.empty.filtered')
                                        : t('admin.users.empty.no_accounts')
                                }
                                action={
                                    filtered ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={usersIndex()}>
                                                {t('admin.users.filters.reset')}
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}

                        {users.data.length > 0 && (
                            <div
                                aria-busy={busy}
                                className={
                                    busy
                                        ? 'space-y-4 opacity-60 transition-opacity'
                                        : 'space-y-4'
                                }
                            >
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {t('admin.users.column.name')}
                                            </TableHead>
                                            <TableHead>
                                                {t('admin.users.column.email')}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.users.column.real_name',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t('admin.users.column.role')}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.users.column.two_factor',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.users.column.last_login_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                {t(
                                                    'admin.users.column.created_at',
                                                )}
                                            </TableHead>
                                            <TableHead>
                                                <span className="sr-only">
                                                    {t(
                                                        'admin.users.column.actions',
                                                    )}
                                                </span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {users.data.map((user) => (
                                            <TableRow key={user.id}>
                                                <TableCell className="align-top">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium text-foreground">
                                                            {user.name}
                                                        </span>
                                                        {user.anonymized && (
                                                            <AnonymizedBadge />
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="break-all">
                                                            {user.email ??
                                                                t(
                                                                    'admin.common.none',
                                                                )}
                                                        </span>
                                                        {user.email !== null &&
                                                            !user.email_verified && (
                                                                <UnverifiedEmailBadge />
                                                            )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    {user.real_name ??
                                                        t('admin.common.none')}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <RoleBadge
                                                        value={user.role}
                                                    />
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <TwoFactorBadge
                                                        confirmed={
                                                            user.two_factor_confirmed
                                                        }
                                                        privileged={hasAtLeastRole(
                                                            user.role,
                                                            'curator',
                                                        )}
                                                    />
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        user.last_login_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.account.never',
                                                        )}
                                                </TableCell>
                                                <TableCell className="align-top whitespace-nowrap">
                                                    {formatMoment(
                                                        user.created_at,
                                                        locale,
                                                    ) ??
                                                        t(
                                                            'admin.common.unknown',
                                                        )}
                                                </TableCell>
                                                <TableCell className="align-top">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        className="min-h-11"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={usersShow(
                                                                user.id,
                                                            )}
                                                            aria-label={t(
                                                                'admin.a11y.open_account',
                                                                {
                                                                    name: user.name,
                                                                },
                                                            )}
                                                        >
                                                            {t(
                                                                'admin.users.row.open',
                                                            )}
                                                        </Link>
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>

                                <AdminPagination
                                    meta={users.meta}
                                    href={(page) =>
                                        usersIndex({
                                            query: directoryQuery(filters, {
                                                page,
                                            }),
                                        })
                                    }
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminUsersIndex.layout = { breadcrumbs };

/**
 * La query string de l'annuaire, filtres courants compris : paginer ne doit
 * jamais perdre une recherche. Une valeur nulle n'est pas envoyée.
 */
function directoryQuery(
    filters: AdminUserDirectoryFilters,
    overrides: { page?: number },
): Record<string, string | number> {
    const query: Record<string, string | number> = {
        sort: filters.sort,
        direction: filters.direction,
    };

    if (filters.q !== null) {
        query.q = filters.q;
    }

    if (filters.role !== null) {
        query.role = filters.role;
    }

    if (filters.state !== null) {
        query.state = filters.state;
    }

    if (overrides.page !== undefined && overrides.page > 1) {
        query.page = overrides.page;
    }

    return query;
}

/**
 * La barre de filtres — un `<Form>` en GET : toute la sélection vit dans la
 * query string, partageable et rechargeable. Les listes d'options sont celles
 * que `UserDirectoryRequest` accepte, relues par le contrôleur.
 */
function UserDirectoryFiltersForm({
    filters,
    options,
}: {
    filters: AdminUserDirectoryFilters;
    options: AdminUserDirectoryOptions;
}) {
    const { t } = useTranslations();

    const all: AdminSelectOption = { value: '', label: t('admin.common.all') };

    return (
        <Form
            {...UserDirectoryController.index.form()}
            options={{ preserveState: true, preserveScroll: true }}
            aria-label={t('admin.a11y.users_filters_form')}
            className="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
        >
            <div className="space-y-1.5 md:col-span-2 xl:col-span-3">
                <Label htmlFor="users-q">
                    {t('admin.users.filters.search.label')}
                </Label>
                <Input
                    id="users-q"
                    name="q"
                    type="search"
                    defaultValue={filters.q ?? ''}
                    placeholder={t('admin.users.filters.search.placeholder')}
                    aria-describedby="users-q-hint"
                />
                <p id="users-q-hint" className="text-xs text-muted-foreground">
                    {t('admin.users.filters.search.hint')}
                </p>
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="users-role">
                    {t('admin.users.filters.role')}
                </Label>
                <AdminSelect
                    id="users-role"
                    name="role"
                    defaultValue={filters.role ?? ''}
                    options={[
                        all,
                        ...options.role.map((value) => ({
                            value,
                            label: t(USER_ROLE_KEYS[value]),
                        })),
                    ]}
                />
            </div>

            <div className="space-y-1.5">
                <Label htmlFor="users-state">
                    {t('admin.users.filters.state.label')}
                </Label>
                <AdminSelect
                    id="users-state"
                    name="state"
                    defaultValue={filters.state ?? ''}
                    options={[
                        all,
                        ...options.state.map((value) => ({
                            value,
                            label: t(ACCOUNT_STATE_KEYS[value]),
                        })),
                    ]}
                />
            </div>

            <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1.5">
                    <Label htmlFor="users-sort">
                        {t('admin.users.sort.label')}
                    </Label>
                    <AdminSelect
                        id="users-sort"
                        name="sort"
                        defaultValue={filters.sort}
                        options={options.sort.map((value) => ({
                            value,
                            label: t(USER_DIRECTORY_SORT_KEYS[value]),
                        }))}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="users-direction">
                        {t('admin.users.sort.direction')}
                    </Label>
                    <AdminSelect
                        id="users-direction"
                        name="direction"
                        defaultValue={filters.direction}
                        options={options.direction.map((value) => ({
                            value,
                            label: t(
                                value === 'asc'
                                    ? 'admin.catalog.sort.direction.asc'
                                    : 'admin.catalog.sort.direction.desc',
                            ),
                        }))}
                    />
                </div>
            </div>

            <div className="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-3">
                <Button type="submit" className="min-h-11">
                    {t('admin.users.filters.submit')}
                </Button>
                <Button variant="ghost" className="min-h-11" asChild>
                    <Link href={usersIndex()}>
                        {t('admin.users.filters.reset')}
                    </Link>
                </Button>
            </div>
        </Form>
    );
}
