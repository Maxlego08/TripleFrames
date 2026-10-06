import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { home } from '@/routes';
import type { TranslationKey } from '@/types/translations';

/**
 * Les statuts que le gestionnaire d'exceptions rend en page (spec 90 § 4.8),
 * miroir de l'enum serveur `App\Enums\ErrorPageStatus`.
 */
type ErrorStatus = 403 | 404 | 419 | 429 | 500 | 503;

type Props = {
    status: ErrorStatus;
};

type ErrorCopy = {
    title: TranslationKey;
    description: TranslationKey;
};

/**
 * Chaque statut et ses deux clés, par une table écrite ici et jamais par une
 * clé construite à l'exécution (spec 90 § 6.7) : un statut ajouté au type sans
 * sa ligne casse `tsc` au lieu d'afficher une clé brute.
 */
const ERROR_COPY: Record<ErrorStatus, ErrorCopy> = {
    403: {
        title: 'common.error.forbidden.title',
        description: 'common.error.forbidden.description',
    },
    404: {
        title: 'common.error.not_found.title',
        description: 'common.error.not_found.description',
    },
    419: {
        title: 'common.error.page_expired.title',
        description: 'common.error.page_expired.description',
    },
    429: {
        title: 'common.error.too_many_requests.title',
        description: 'common.error.too_many_requests.description',
    },
    500: {
        title: 'common.error.server_error.title',
        description: 'common.error.server_error.description',
    },
    503: {
        title: 'common.error.service_unavailable.title',
        description: 'common.error.service_unavailable.description',
    },
};

/**
 * La page d'erreur joueur (spec 90 § 4.8), dans `PublicLayout`, même levée
 * depuis une route de jeu.
 *
 * Rendue par le gestionnaire d'exceptions, hors mode debug, y compris pour
 * les erreurs nées avant le middleware Inertia (URL inconnue, code de salon
 * introuvable, refus de rôle, limiteur) : les props partagées, la locale du
 * visiteur et le domaine `legal` du pied de page y sont posés par le
 * gestionnaire lui-même. Elle n'appelle que des clés `common.*`.
 *
 * Aucun message d'erreur brut : le statut choisit un titre et une explication
 * traduits, et une sortie, l'accueil. Le code numérique est affiché pour qui
 * voudrait le signaler, et masqué aux lecteurs d'écran, pour qui le titre dit
 * déjà tout. Un statut hors table, qui ne devrait jamais arriver, retombe sur
 * l'erreur de serveur plutôt que sur une clé brute.
 */
export default function ErrorPage({ status }: Props) {
    const { t } = useTranslations();
    const copy = ERROR_COPY[status] ?? ERROR_COPY[500];
    const title = t(copy.title);

    return (
        <>
            <Head title={title} />

            <section
                aria-labelledby="error-title"
                className="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-12"
            >
                <p
                    aria-hidden="true"
                    className="font-mono text-sm text-muted-foreground"
                >
                    {status}
                </p>

                <h1
                    id="error-title"
                    className="text-2xl font-semibold tracking-tight"
                >
                    {title}
                </h1>

                <p className="max-w-prose text-muted-foreground">
                    {t(copy.description)}
                </p>

                <div>
                    <Button asChild className="min-h-11">
                        <Link href={home()}>{t('common.error.back_home')}</Link>
                    </Button>
                </div>
            </section>
        </>
    );
}
