import { Form, Head, Link, router } from '@inertiajs/react';
import {
    HistoryIcon,
    SearchIcon,
    ShieldAlertIcon,
    UsersIcon,
} from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { toast } from 'sonner';
import AccessController from '@/actions/App/Http/Controllers/Admin/AccessController';
import { AccountHistoryTable } from '@/components/admin/account-history-table';
import {
    RoleBadge,
    TwoFactorBadge,
    UnverifiedEmailBadge,
} from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { RealNameDialog } from '@/components/admin/real-name-dialog';
import { RoleChangeDialog } from '@/components/admin/role-change-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { hasAtLeastRole } from '@/lib/roles';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as accessIndex } from '@/routes/admin/access';
import { show as usersShow } from '@/routes/admin/users';
import type {
    AdminAccessCandidate,
    AdminAccountHistoryLine,
    AdminAccountRow,
    AdminActionableAccount,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    privileged: AdminActionableAccount[];
    without_two_factor: AdminAccountRow[];
    admins_count: number;
    second_admin_missing: boolean;
    candidate: AdminAccessCandidate | null;
    history: AdminAccountHistoryLine[];
    history_limit: number;
};

type AccessGesture = { kind: 'role' | 'real_name'; account: AdminAccountRow };

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-access-offline';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.access', href: accessIndex() },
];

/**
 * L'écran de gestion des accès — spec 20 § 2.8, **administrateur seul**.
 *
 * - les comptes privilégiés non anonymisés, et leurs deux gestes : changer le
 *   rôle, corriger le nom réel ;
 * - la file « rôle privilégié sans double authentification » (§ 2.4) ;
 * - promouvoir un compte existant, retrouvé par son adresse EXACTE ;
 * - l'historique daté des rôles et des noms réels, borné et dit borné ;
 * - tant que moins de deux administrateurs nominatifs existent, un
 *   avertissement : c'est une condition de l'ouverture publique, jamais une
 *   garde de code.
 *
 * Les booléens `abilities` ne font que montrer un bouton ; chaque geste garde
 * sa policy, et ses refus d'état reviennent sous leur champ.
 */
export default function AdminAccessIndex({
    privileged,
    without_two_factor,
    admins_count,
    second_admin_missing,
    candidate,
    history,
    history_limit,
}: Props) {
    const { t, locale } = useTranslations();
    const [gesture, setGesture] = useState<AccessGesture | null>(null);
    const triggerRef = useRef<HTMLElement | null>(null);
    const listRef = useRef<HTMLDivElement>(null);

    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    function openGesture(next: AccessGesture): void {
        triggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setGesture(next);
    }

    // Le focus revient au bouton qui a ouvert la confirmation ; une ligne
    // disparue — compte rétrogradé en joueur — le rend à la liste.
    function returnFocus(): void {
        const trigger = triggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            listRef.current?.focus();
        }
    }

    const closeGesture = (): void => setGesture(null);

    return (
        <>
            <Head title={t('admin.access.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.access.heading')}
                    description={t('admin.access.description')}
                />

                {second_admin_missing && (
                    <Alert variant="destructive">
                        <ShieldAlertIcon aria-hidden />
                        <AlertTitle>
                            {t('admin.access.second_admin_missing.title')}
                        </AlertTitle>
                        <AlertDescription>
                            {t(
                                'admin.access.second_admin_missing.description',
                                {
                                    count: formatInteger(admins_count, locale),
                                },
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.access.privileged.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.access.privileged.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div
                            ref={listRef}
                            tabIndex={-1}
                            className="rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {privileged.length === 0 ? (
                                <AdminEmptyState
                                    icon={UsersIcon}
                                    title={t('admin.access.privileged.empty')}
                                />
                            ) : (
                                <AccountTable
                                    accounts={privileged}
                                    onGesture={openGesture}
                                />
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.access.without_two_factor.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.access.without_two_factor.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {without_two_factor.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                {t('admin.access.without_two_factor.empty')}
                            </p>
                        ) : (
                            <ul className="space-y-2">
                                {without_two_factor.map((account) => (
                                    <li
                                        key={account.id}
                                        className="flex flex-wrap items-center gap-2 text-sm"
                                    >
                                        <Link
                                            href={usersShow(account.id)}
                                            className="font-medium text-foreground underline-offset-4 hover:underline"
                                        >
                                            {account.real_name ?? account.name}
                                        </Link>
                                        <RoleBadge value={account.role} />
                                        <span className="text-muted-foreground">
                                            {t(
                                                'admin.access.without_two_factor.since',
                                                {
                                                    date:
                                                        formatMoment(
                                                            account.last_login_at,
                                                            locale,
                                                        ) ??
                                                        t(
                                                            'admin.account.never',
                                                        ),
                                                },
                                            )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.access.promote.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.access.promote.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/*
                         * `preserveState` : la page reste montée, le focus
                         * reste sur « Chercher » et la région d'état annonce
                         * le résultat ; un rechargement complet perdrait les
                         * deux.
                         */}
                        <Form
                            {...AccessController.index.form()}
                            options={{
                                preserveState: true,
                                preserveScroll: true,
                            }}
                            aria-label={t('admin.a11y.access_search_form')}
                            className="flex flex-col gap-2 sm:flex-row sm:items-end"
                        >
                            <div className="flex-1 space-y-1.5">
                                <Label htmlFor="access-email">
                                    {t('admin.access.promote.field')}
                                </Label>
                                <Input
                                    id="access-email"
                                    name="email"
                                    type="email"
                                    autoComplete="off"
                                    defaultValue={candidate?.email ?? ''}
                                    className="min-h-11"
                                />
                            </div>
                            <Button type="submit" className="min-h-11">
                                <SearchIcon aria-hidden />
                                {t('admin.access.promote.submit')}
                            </Button>
                        </Form>

                        <div role="status" aria-live="polite">
                            {candidate !== null &&
                                (candidate.account === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('admin.access.promote.not_found', {
                                            email: candidate.email,
                                        })}
                                    </p>
                                ) : (
                                    <AccountTable
                                        accounts={[candidate.account]}
                                        onGesture={openGesture}
                                    />
                                ))}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.access.history.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.access.history.description', {
                                count: formatInteger(history_limit, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {history.length === 0 ? (
                            <AdminEmptyState
                                icon={HistoryIcon}
                                title={t('admin.access.history.empty')}
                            />
                        ) : (
                            <AccountHistoryTable lines={history} showSubject />
                        )}
                    </CardContent>
                </Card>
            </div>

            <RoleChangeDialog
                account={gesture?.kind === 'role' ? gesture.account : null}
                onClose={closeGesture}
                onReturnFocus={returnFocus}
            />

            <RealNameDialog
                account={gesture?.kind === 'real_name' ? gesture.account : null}
                onClose={closeGesture}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

AdminAccessIndex.layout = { breadcrumbs };

/**
 * Les comptes et leurs gestes : les comptes privilégiés, ou le compte trouvé
 * par la recherche d'adresse. Son propre rôle ne se change pas ici.
 */
function AccountTable({
    accounts,
    onGesture,
}: {
    accounts: AdminActionableAccount[];
    onGesture: (gesture: AccessGesture) => void;
}) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('admin.access.column.real_name')}</TableHead>
                    <TableHead>{t('admin.access.column.name')}</TableHead>
                    <TableHead>{t('admin.access.column.email')}</TableHead>
                    <TableHead>{t('admin.access.column.role')}</TableHead>
                    <TableHead>{t('admin.access.column.two_factor')}</TableHead>
                    <TableHead>
                        {t('admin.access.column.last_login_at')}
                    </TableHead>
                    <TableHead>{t('admin.access.column.actions')}</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {accounts.map((account) => (
                    <TableRow key={account.id}>
                        <TableCell className="align-top">
                            {account.real_name ?? t('admin.common.none')}
                        </TableCell>
                        <TableCell className="align-top">
                            <Link
                                href={usersShow(account.id)}
                                className="font-medium text-foreground underline-offset-4 hover:underline"
                                aria-label={t('admin.a11y.open_account', {
                                    name: account.name,
                                })}
                            >
                                {account.name}
                            </Link>
                        </TableCell>
                        <TableCell className="align-top">
                            <span className="flex flex-wrap items-center gap-2">
                                <span className="break-all">
                                    {account.email ?? t('admin.common.none')}
                                </span>
                                {account.email !== null &&
                                    !account.email_verified && (
                                        <UnverifiedEmailBadge />
                                    )}
                            </span>
                        </TableCell>
                        <TableCell className="align-top">
                            <RoleBadge value={account.role} />
                        </TableCell>
                        <TableCell className="align-top">
                            <TwoFactorBadge
                                confirmed={account.two_factor_confirmed}
                                privileged={hasAtLeastRole(
                                    account.role,
                                    'curator',
                                )}
                            />
                        </TableCell>
                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(account.last_login_at, locale) ??
                                t('admin.account.never')}
                        </TableCell>
                        <TableCell className="align-top">
                            <div className="flex flex-wrap gap-2">
                                {account.is_self ? (
                                    <span className="text-sm text-muted-foreground">
                                        {t('admin.access.self')}
                                    </span>
                                ) : (
                                    account.abilities.updateRole && (
                                        <Button
                                            size="sm"
                                            className="min-h-11"
                                            aria-label={t(
                                                'admin.a11y.change_role_of',
                                                { name: account.name },
                                            )}
                                            onClick={() =>
                                                onGesture({
                                                    kind: 'role',
                                                    account,
                                                })
                                            }
                                        >
                                            {t(
                                                'admin.access.actions.change_role',
                                            )}
                                        </Button>
                                    )
                                )}
                                {account.abilities.updateRealName && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="min-h-11"
                                        aria-label={t(
                                            'admin.a11y.correct_real_name_of',
                                            { name: account.name },
                                        )}
                                        onClick={() =>
                                            onGesture({
                                                kind: 'real_name',
                                                account,
                                            })
                                        }
                                    >
                                        {t(
                                            'admin.access.actions.correct_real_name',
                                        )}
                                    </Button>
                                )}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
