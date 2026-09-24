import { Head, Link, router } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { dashboard as adminDashboard } from '@/routes/admin';
import type { TranslationKey } from '@/types/translations';

/** Les statuts que le gestionnaire d'exceptions rend en page (spec 90 § 4.8). */
type AdminErrorStatus = 403 | 404 | 419 | 429 | 500 | 503;

type Props = {
    status: AdminErrorStatus;
};

type ErrorCopy = {
    title: TranslationKey;
    description: TranslationKey;
};

/**
 * Chaque statut, et les deux clés qui le disent. Une table et non une clé
 * composée à l'exécution : `t()` est typé, et un statut ajouté au type sans sa
 * ligne ici casse `tsc` au lieu d'afficher une clé brute (C15 § 2.7).
 */
const ERROR_COPY: Record<AdminErrorStatus, ErrorCopy> = {
    403: {
        title: 'admin.error.http.403.title',
        description: 'admin.error.http.403.description',
    },
    404: {
        title: 'admin.error.http.404.title',
        description: 'admin.error.http.404.description',
    },
    419: {
        title: 'admin.error.http.419.title',
        description: 'admin.error.http.419.description',
    },
    429: {
        title: 'admin.error.http.429.title',
        description: 'admin.error.http.429.description',
    },
    500: {
        title: 'admin.error.http.500.title',
        description: 'admin.error.http.500.description',
    },
    503: {
        title: 'admin.error.http.503.title',
        description: 'admin.error.http.503.description',
    },
};

/**
 * La page d'erreur du back-office (spec 20 § 13.2, contrat C15 § 2.3).
 *
 * Rendue à la place de la page `error` joueur quand le domaine `admin` était
 * sélectionné avant l'exception : la locale reste `fr` et seul le domaine
 * `admin` est joint (spec 90 § 4.8), d'où des clés `admin.error.http.*` et
 * jamais `common.error.*`, que le back-office ne reçoit pas. Elle vit dans la
 * coquille du back-office, sa navigation et son pied compris.
 *
 * Aucun message d'erreur brut (décision 9) : le statut choisit un titre et une
 * explication traduits, et deux sorties — la page précédente, le tableau de
 * bord. Un statut hors table, qui ne devrait jamais arriver, retombe sur
 * l'erreur de serveur plutôt que sur une clé brute.
 */
export default function AdminError({ status }: Props) {
    const { t } = useTranslations();
    const copy = ERROR_COPY[status] ?? ERROR_COPY[500];

    return (
        <>
            <Head title={t(copy.title)} />

            <div className="flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start gap-3">
                    <TriangleAlertIcon
                        aria-hidden
                        className="mt-1 size-5 shrink-0 text-muted-foreground"
                    />
                    <AdminPageHeading
                        title={t(copy.title)}
                        description={t(copy.description)}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="min-h-11"
                        onClick={() => {
                            // Sans historique (erreur ouverte dans un nouvel
                            // onglet), `history.back()` ne ferait rien : le
                            // bouton mène alors au tableau de bord plutôt que
                            // de rester mort (décision 9).
                            if (window.history.length > 1) {
                                window.history.back();
                            } else {
                                router.visit(adminDashboard());
                            }
                        }}
                    >
                        {t('admin.error.back')}
                    </Button>
                    <Button asChild className="min-h-11">
                        <Link href={adminDashboard()}>
                            {t('admin.error.dashboard')}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
