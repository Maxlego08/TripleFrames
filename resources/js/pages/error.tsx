import { Head } from '@inertiajs/react';
import { useTranslations } from '@/hooks/use-translations';
import { home, login } from '@/routes';
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
 * traduits, une action principale et une sortie secondaire (table
 * `ERROR_ACTIONS`). Le code numérique est affiché pour qui voudrait le
 * signaler, et masqué aux lecteurs d'écran, pour qui le titre dit déjà tout.
 * Un statut hors table, qui ne devrait jamais arriver, retombe sur l'erreur
 * de serveur plutôt que sur une clé brute.
 *
 * **Présentation** (design final du 08/10) : la carte `error-card` de la
 * maquette `design-test/html/error-*.html`, dans la coquille publique
 * (`public-shell--error`, `resources/scss/error.scss`) ; en-tête et pied de
 * page sont ceux du site.
 */
/** Où mènent les deux sorties d'une page d'erreur. */
type ErrorExit = 'home' | 'join' | 'login' | 'retry' | 'reload';

/**
 * Action principale et sortie secondaire de chaque statut, d'après la
 * maquette `design-test/html/error-*.html` (08/10) : réessayer quand la
 * panne est passagère, l'accueil sinon.
 */
const ERROR_ACTIONS: Record<ErrorStatus, [ErrorExit, ErrorExit]> = {
    403: ['home', 'login'],
    404: ['home', 'join'],
    419: ['reload', 'home'],
    429: ['retry', 'home'],
    500: ['retry', 'home'],
    503: ['retry', 'home'],
};

const EXIT_LABELS: Record<ErrorExit, TranslationKey> = {
    home: 'common.error.back_home',
    join: 'common.error.join_game',
    login: 'common.error.other_account',
    retry: 'common.error.retry',
    reload: 'common.error.reload',
};

/** Ancre du contenu de l'accueil, où vit le formulaire « rejoindre ». */
const HOME_CONTENT_ANCHOR = '#public-main';

export default function ErrorPage({ status }: Props) {
    const { t } = useTranslations();
    const copy = ERROR_COPY[status] ?? ERROR_COPY[500];
    const actions = ERROR_ACTIONS[status] ?? ERROR_ACTIONS[500];
    const title = t(copy.title);

    const exit = (kind: ErrorExit, className: string) => {
        const label = t(EXIT_LABELS[kind]);

        if (kind === 'retry' || kind === 'reload') {
            return (
                <button
                    type="button"
                    className={className}
                    onClick={() => window.location.reload()}
                >
                    {label}
                </button>
            );
        }

        // Liens ordinaires et non visites Inertia : une page d'erreur peut
        // naître d'une panne qu'une visite partielle ne réparerait pas.
        const href =
            kind === 'login'
                ? login.url()
                : kind === 'join'
                  ? `${home.url()}${HOME_CONTENT_ANCHOR}`
                  : home.url();

        return (
            <a href={href} className={className}>
                {label}
            </a>
        );
    };

    return (
        <>
            <Head title={title} />

            <div className="error-stage">
                <section aria-labelledby="error-title" className="error-card">
                    <p aria-hidden="true" className="error-card__code">
                        {status}
                    </p>

                    <h1 id="error-title" className="error-card__title">
                        {title}
                    </h1>

                    <p className="error-card__message">{t(copy.description)}</p>

                    <div className="error-card__actions">
                        {exit(actions[0], 'error-card__action')}
                        {exit(actions[1], 'error-card__secondary')}
                    </div>
                </section>
            </div>
        </>
    );
}
