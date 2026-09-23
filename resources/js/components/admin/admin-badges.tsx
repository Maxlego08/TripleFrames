import { Badge } from '@/components/ui/badge';
import { useTranslations } from '@/hooks/use-translations';
import {
    AVAILABILITY_KEYS,
    CONTENT_FLAG_KEYS,
    IMPORT_RUN_KIND_KEYS,
    IMPORT_RUN_STATUS_KEYS,
    IMPORT_SOURCE_KEYS,
} from '@/lib/admin-enum-keys';
import type {
    AdminImportRunRow,
    ContentAvailability,
    ContentFlag,
    ImportSource,
} from '@/types/admin';

/**
 * Les badges d'état du back-office.
 *
 * **Aucune couleur littérale** : les quatre variantes de `Badge` sont des
 * tokens de thème (`primary`, `secondary`, `destructive`, `outline`), et c'est
 * tout ce dont un état a besoin. La gravité est portée par la variante, jamais
 * par un `bg-red-500` — un re-skin ne doit toucher que le thème (règle 5).
 *
 * Les libellés viennent tous de tables typées : un cas d'enum ajouté côté PHP
 * sans sa ligne de dictionnaire casse `tsc` au lieu d'afficher `unrated_pending`
 * à un curateur.
 */

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

const AVAILABILITY_VARIANTS: Record<ContentAvailability, BadgeVariant> = {
    draft: 'secondary',
    published: 'default',
    unpublished: 'outline',
    suspended: 'destructive',
    withdrawn: 'destructive',
};

const CONTENT_FLAG_VARIANTS: Record<ContentFlag, BadgeVariant> = {
    clear: 'default',
    blocked: 'destructive',
    unrated_pending: 'secondary',
};

const RUN_STATUS_VARIANTS: Record<
    'running' | 'queued' | 'completed' | 'failed',
    BadgeVariant
> = {
    running: 'secondary',
    queued: 'outline',
    completed: 'default',
    failed: 'destructive',
};

export function AvailabilityBadge({ value }: { value: ContentAvailability }) {
    const { t } = useTranslations();

    return (
        <Badge variant={AVAILABILITY_VARIANTS[value]}>
            {t(AVAILABILITY_KEYS[value])}
        </Badge>
    );
}

export function ContentFlagBadge({ value }: { value: ContentFlag }) {
    const { t } = useTranslations();

    return (
        <Badge variant={CONTENT_FLAG_VARIANTS[value]}>
            {t(CONTENT_FLAG_KEYS[value])}
        </Badge>
    );
}

export function ImportSourceBadge({ value }: { value: ImportSource }) {
    const { t } = useTranslations();

    return <Badge variant="outline">{t(IMPORT_SOURCE_KEYS[value])}</Badge>;
}

export function ImportRunKindBadge({
    value,
}: {
    value: AdminImportRunRow['run_kind'];
}) {
    const { t } = useTranslations();

    return <Badge variant="outline">{t(IMPORT_RUN_KIND_KEYS[value])}</Badge>;
}

/**
 * L'état d'un balayage, « en file » compris.
 *
 * `is_queued` n'est pas une quatrième valeur de colonne : c'est le couple
 * `status = running` ET `started_at = null`, c'est-à-dire l'intervalle pendant
 * lequel aucun worker n'a encore pris le travail. Le distinguer de « en
 * cours » est la seule façon de rendre lisible la panne la plus banale du
 * développement — aucun worker ne tourne.
 *
 * Le badge porte son propre nom accessible : lu hors de sa colonne — sur la
 * fiche d'un balayage, il voisine le badge de nature et le marqueur
 * « élargi » sans aucun en-tête pour le qualifier —, « En file » ne dit pas de
 * quoi il parle.
 *
 * Et il le porte en **contenu**, pas en `aria-label` : `Badge` rend un `<span>`
 * nu, donc un élément de rôle `generic`, sur lequel la spécification ARIA
 * interdit `aria-label` et qu'aucun navigateur n'expose. L'intention serait
 * annoncée sans jamais atteindre personne. Un texte `sr-only` doublé d'un
 * libellé `aria-hidden` dit la même chose et arrive vraiment.
 */
export function ImportRunStatusBadge({ run }: { run: AdminImportRunRow }) {
    const { t } = useTranslations();
    const state = run.is_queued ? 'queued' : run.status;
    const label = t(IMPORT_RUN_STATUS_KEYS[state]);

    return (
        <Badge variant={RUN_STATUS_VARIANTS[state]}>
            <span className="sr-only">
                {t('admin.a11y.run_status', { status: label })}
            </span>
            <span aria-hidden>{label}</span>
        </Badge>
    );
}

/**
 * La marque « entré par exception » (décision 11). Elle n'est jamais
 * silencieuse : le motif exact, lui, est rendu en toutes lettres sous le titre
 * par la liste du catalogue.
 */
export function ExceptionBadge() {
    const { t } = useTranslations();

    return (
        <Badge variant="outline">{t('admin.catalog.exception.badge')}</Badge>
    );
}

/** Le marqueur « filtre élargi » d'un balayage `discover`. */
export function WidenedBadge() {
    const { t } = useTranslations();

    return <Badge variant="secondary">{t('admin.import.runs.widened')}</Badge>;
}
