import { Head, Link } from '@inertiajs/react';
import { ShieldAlertIcon } from 'lucide-react';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard as adminDashboard } from '@/routes/admin';
import { required as twoFactorRequired } from '@/routes/admin/two_factor';
import { edit as securityEdit } from '@/routes/security';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    /** Le second facteur du compte est-il confirmé ? Lu par le serveur, jamais déduit ici. */
    two_factor_confirmed: boolean;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.two_factor.title', href: twoFactorRequired() },
];

/** Les étapes de l'enrôlement, dans l'ordre où Fortify les demande. */
const STEPS: readonly TranslationKey[] = [
    'admin.two_factor.steps.open',
    'admin.two_factor.steps.enable',
    'admin.two_factor.steps.confirm',
    'admin.two_factor.steps.recovery',
    'admin.two_factor.steps.return',
];

/**
 * L'écran d'enrôlement du second facteur (spec 20 § 2.4).
 *
 * La garde `admin.2fa` y renvoie tout compte curateur ou administrateur dont la
 * double authentification n'est pas confirmée : **jamais un 403 muet**. L'écran
 * dit pourquoi la porte est fermée, comment l'ouvrir, et mène par un lien
 * Wayfinder à la sécurité du compte, où Fortify enrôle avec confirmation. Il
 * n'enrôle rien lui-même : dupliquer ici le QR code et la confirmation ferait
 * deux chemins d'enrôlement à tenir.
 *
 * Aucune donnée ne se charge : la page est rendue d'un bloc par le serveur, et
 * ses deux sorties sont des liens. « J'ai confirmé » mène au tableau de bord,
 * que la garde laisse passer ou renvoie ici — c'est le serveur, jamais cet
 * écran, qui juge si la porte est ouverte.
 *
 * Un compte déjà confirmé qui arrive ici voit l'état « porte ouverte » et
 * l'entrée du back-office.
 */
export default function AdminTwoFactorRequired({
    two_factor_confirmed,
}: Props) {
    const { t } = useTranslations();

    return (
        <>
            <Head title={t('admin.two_factor.title')} />

            <div className="flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                {two_factor_confirmed ? (
                    <>
                        <AdminPageHeading
                            title={t('admin.two_factor.confirmed.heading')}
                            description={t(
                                'admin.two_factor.confirmed.description',
                            )}
                        />

                        <div className="flex flex-wrap gap-2">
                            <Button asChild className="min-h-11">
                                <Link href={adminDashboard()}>
                                    {t('admin.two_factor.confirmed.enter')}
                                </Link>
                            </Button>
                        </div>
                    </>
                ) : (
                    <>
                        <AdminPageHeading
                            title={t('admin.two_factor.heading')}
                            description={t('admin.two_factor.description')}
                        />

                        <Alert>
                            <ShieldAlertIcon aria-hidden />
                            <AlertTitle>
                                {t('admin.two_factor.why.heading')}
                            </AlertTitle>
                            <AlertDescription>
                                <p>{t('admin.two_factor.why.body')}</p>
                            </AlertDescription>
                        </Alert>

                        <Card>
                            <CardHeader>
                                <AdminCardTitle>
                                    {t('admin.two_factor.steps.heading')}
                                </AdminCardTitle>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                <ol className="list-decimal space-y-2 pl-5 text-sm text-card-foreground">
                                    {STEPS.map((step) => (
                                        <li key={step}>{t(step)}</li>
                                    ))}
                                </ol>

                                <div className="flex flex-wrap gap-2">
                                    <Button asChild className="min-h-11">
                                        <Link href={securityEdit()}>
                                            {t(
                                                'admin.two_factor.action.open_security',
                                            )}
                                        </Link>
                                    </Button>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11"
                                    >
                                        <Link href={adminDashboard()}>
                                            {t('admin.two_factor.action.enter')}
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </>
    );
}

AdminTwoFactorRequired.layout = { breadcrumbs };
