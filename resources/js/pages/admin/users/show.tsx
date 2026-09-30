import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeftIcon, HistoryIcon } from 'lucide-react';
import { useEffect, useEffectEvent, useRef, useState } from 'react';
import { toast } from 'sonner';
import { AccountHistoryTable } from '@/components/admin/account-history-table';
import {
    AnonymizedBadge,
    RoleBadge,
    TwoFactorBadge,
    UnverifiedEmailBadge,
} from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminFieldList } from '@/components/admin/admin-field-list';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { RealNameDialog } from '@/components/admin/real-name-dialog';
import { RoleChangeDialog } from '@/components/admin/role-change-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import { localeLabel, OAUTH_PROVIDER_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { hasAtLeastRole } from '@/lib/roles';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as journalIndex } from '@/routes/admin/journal';
import { index as usersIndex } from '@/routes/admin/users';
import type {
    AdminAccountAbilities,
    AdminAccountDetail,
    AdminAccountHistoryLine,
    AdminAccountTraces,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    account: AdminAccountDetail;
    /** Les fournisseurs liés, par leur nom seulement. */
    providers: string[];
    passkeys_count: number;
    traces: AdminAccountTraces;
    history: AdminAccountHistoryLine[];
    abilities: AdminAccountAbilities;
    is_self: boolean;
};

type AccountGesture = 'role' | 'real_name';

/** Identifiant du toast de déconnexion : un seul à l'écran, jamais une pile. */
const OFFLINE_TOAST_ID = 'admin-account-offline';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.users', href: usersIndex() },
    { title: 'admin.account.title', href: usersIndex() },
];

/**
 * La fiche d'un compte — spec 20 § 2.8, **administrateur seul**.
 *
 * En lecture : identité, sécurité, consentements, traces que l'anonymisation
 * conserve, et l'historique du journal qui vise le compte. Deux gestes, chacun
 * derrière sa confirmation et sa policy : changer le rôle, corriger le nom
 * réel d'un compte privilégié. Les booléens `abilities` ne font que montrer un
 * bouton ; son propre rôle ne se change pas ici — un autre administrateur le
 * fait.
 *
 * **Ce que la fiche ne montre jamais** : les configurations sauvegardées,
 * privées y compris d'un administrateur, et aucun secret. Elle le dit.
 */
export default function AdminUsersShow({
    account,
    providers,
    passkeys_count,
    traces,
    history,
    abilities,
    is_self,
}: Props) {
    const { t, locale } = useTranslations();
    const [gesture, setGesture] = useState<AccountGesture | null>(null);
    const triggerRef = useRef<HTMLElement | null>(null);
    const gesturesRef = useRef<HTMLDivElement>(null);

    // Déconnexion pendant un geste : rien n'est parti, la saisie reste telle
    // quelle, et l'administrateur l'apprend (spec 20 § 13.5).
    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    function openGesture(next: AccountGesture): void {
        triggerRef.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setGesture(next);
    }

    // Le focus revient au bouton qui a ouvert la confirmation ; un geste
    // réussi peut l'avoir fait disparaître — il revient alors à la zone des
    // gestes, jamais au document.
    function returnFocus(): void {
        const trigger = triggerRef.current;

        if (trigger !== null && trigger.isConnected) {
            trigger.focus();
        } else {
            gesturesRef.current?.focus();
        }
    }

    const canChangeRole = abilities.updateRole && !is_self;
    const none = t('admin.common.none');

    return (
        <>
            <Head title={account.name} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={account.name}
                    description={t('admin.account.description')}
                    actions={
                        <>
                            {/*
                             * Le journal filtré sur ce compte (ligne 41, D41
                             * du 30/09) : la fiche est déjà un écran de
                             * l'administrateur seul, comme le journal.
                             */}
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={journalIndex({
                                        query: {
                                            subject_type: 'user',
                                            subject_id: account.id,
                                        },
                                    })}
                                >
                                    <HistoryIcon aria-hidden />
                                    {t('admin.journal.history')}
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={usersIndex()}>
                                    <ArrowLeftIcon aria-hidden />
                                    {t('admin.account.back')}
                                </Link>
                            </Button>
                        </>
                    }
                />

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.account.access.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <RoleBadge value={account.role} />
                            <TwoFactorBadge
                                confirmed={account.two_factor_confirmed}
                                privileged={hasAtLeastRole(
                                    account.role,
                                    'curator',
                                )}
                            />
                            {account.anonymized && <AnonymizedBadge />}
                        </div>

                        {is_self && (
                            <Alert>
                                <AlertDescription>
                                    {t('admin.account.access.self')}
                                </AlertDescription>
                            </Alert>
                        )}

                        {account.anonymized && (
                            <Alert>
                                <AlertDescription>
                                    {t('admin.account.access.anonymized')}
                                </AlertDescription>
                            </Alert>
                        )}

                        <div
                            ref={gesturesRef}
                            tabIndex={-1}
                            className="flex flex-wrap gap-2 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {canChangeRole && (
                                <Button
                                    className="min-h-11"
                                    onClick={() => openGesture('role')}
                                >
                                    {t('admin.access.actions.change_role')}
                                </Button>
                            )}
                            {abilities.updateRealName && (
                                <Button
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => openGesture('real_name')}
                                >
                                    {t(
                                        'admin.access.actions.correct_real_name',
                                    )}
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.account.identity.heading')}
                            </AdminCardTitle>
                        </CardHeader>
                        <CardContent>
                            <AdminFieldList
                                fields={[
                                    {
                                        label: t('admin.account.identity.id'),
                                        // Un identifiant, pas une quantité :
                                        // jamais de séparateur de milliers,
                                        // pour qu'il se recolle tel quel dans
                                        // la recherche de l'annuaire.
                                        value: String(account.id),
                                    },
                                    {
                                        label: t('admin.account.identity.name'),
                                        value: account.name,
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.real_name',
                                        ),
                                        value: account.real_name ?? none,
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.email',
                                        ),
                                        value: (
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="break-all">
                                                    {account.email ?? none}
                                                </span>
                                                {account.email !== null &&
                                                    !account.email_verified && (
                                                        <UnverifiedEmailBadge />
                                                    )}
                                            </span>
                                        ),
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.email_verified_at',
                                        ),
                                        value:
                                            formatMoment(
                                                account.email_verified_at,
                                                locale,
                                            ) ?? none,
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.locale',
                                        ),
                                        value: localeLabel(account.locale, t),
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.created_at',
                                        ),
                                        value:
                                            formatMoment(
                                                account.created_at,
                                                locale,
                                            ) ?? t('admin.common.unknown'),
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.last_login_at',
                                        ),
                                        value:
                                            formatMoment(
                                                account.last_login_at,
                                                locale,
                                            ) ?? t('admin.account.never'),
                                    },
                                    {
                                        label: t(
                                            'admin.account.identity.anonymized_at',
                                        ),
                                        value: formatMoment(
                                            account.anonymized_at,
                                            locale,
                                        ),
                                    },
                                ]}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.account.security.heading')}
                            </AdminCardTitle>
                        </CardHeader>
                        <CardContent>
                            <AdminFieldList
                                fields={[
                                    {
                                        label: t(
                                            'admin.account.security.two_factor',
                                        ),
                                        value:
                                            formatMoment(
                                                account.two_factor_confirmed_at,
                                                locale,
                                            ) ??
                                            t('admin.account.two_factor.off'),
                                    },
                                    {
                                        label: t(
                                            'admin.account.security.passkeys',
                                        ),
                                        value: formatInteger(
                                            passkeys_count,
                                            locale,
                                        ),
                                    },
                                    {
                                        label: t(
                                            'admin.account.security.providers',
                                        ),
                                        value:
                                            providers.length === 0
                                                ? none
                                                : providers
                                                      .map((provider) => {
                                                          const key =
                                                              OAUTH_PROVIDER_KEYS[
                                                                  provider
                                                              ];

                                                          return key ===
                                                              undefined
                                                              ? provider
                                                              : t(key);
                                                      })
                                                      .join(
                                                          t(
                                                              'admin.common.list_separator',
                                                          ),
                                                      ),
                                    },
                                ]}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.account.consents.heading')}
                            </AdminCardTitle>
                        </CardHeader>
                        <CardContent>
                            <AdminFieldList
                                fields={[
                                    {
                                        label: t(
                                            'admin.account.consents.terms_version',
                                        ),
                                        value: account.terms_version ?? none,
                                    },
                                    {
                                        label: t(
                                            'admin.account.consents.terms_accepted_at',
                                        ),
                                        value:
                                            formatMoment(
                                                account.terms_accepted_at,
                                                locale,
                                            ) ?? none,
                                    },
                                    {
                                        label: t(
                                            'admin.account.consents.age_confirmed_at',
                                        ),
                                        value:
                                            formatMoment(
                                                account.age_confirmed_at,
                                                locale,
                                            ) ?? none,
                                    },
                                ]}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.account.traces.heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.account.traces.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <AdminFieldList
                                fields={[
                                    {
                                        label: t(
                                            'admin.account.traces.frame_reviews',
                                        ),
                                        value: formatInteger(
                                            traces.frame_reviews,
                                            locale,
                                        ),
                                    },
                                    {
                                        label: t(
                                            'admin.account.traces.admin_actions',
                                        ),
                                        value: formatInteger(
                                            traces.admin_actions,
                                            locale,
                                        ),
                                    },
                                    {
                                        label: t(
                                            'admin.account.traces.import_runs',
                                        ),
                                        value: formatInteger(
                                            traces.import_runs,
                                            locale,
                                        ),
                                    },
                                ]}
                            />
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.account.history.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.account.history.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {history.length === 0 ? (
                            <AdminEmptyState
                                icon={HistoryIcon}
                                title={t('admin.account.history.empty')}
                            />
                        ) : (
                            <AccountHistoryTable lines={history} />
                        )}
                    </CardContent>
                </Card>

                <p className="max-w-prose text-sm text-muted-foreground">
                    {t('admin.account.private_notice')}
                </p>
            </div>

            <RoleChangeDialog
                account={gesture === 'role' ? account : null}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />

            <RealNameDialog
                account={gesture === 'real_name' ? account : null}
                onClose={() => setGesture(null)}
                onReturnFocus={returnFocus}
            />
        </>
    );
}

AdminUsersShow.layout = { breadcrumbs };
