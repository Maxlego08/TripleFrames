import { Form, Link } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';
import ImportResumeController from '@/actions/App/Http/Controllers/Admin/ImportResumeController';
import {
    ImportRunKindBadge,
    ImportRunStatusBadge,
    WidenedBadge,
} from '@/components/admin/admin-badges';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import { formatInteger, formatMoment, isOlderThan } from '@/lib/admin-format';
import { show as runShow } from '@/routes/admin/import';
import type { AdminImportRunRow } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/** La fonction de traduction, telle que `useTranslations()` la rend. */
type Translate = ReturnType<typeof useTranslations>['t'];

type Props = {
    runs: AdminImportRunRow[];
    /** Le filtre figé : utile sur l'écran d'import, du bruit sur le tableau de bord. */
    showFilter?: boolean;
    /** La reprise n'est offerte que là où l'on peut agir. */
    showResume?: boolean;
    /** Une clé TMDB absente désactive la reprise, comme elle désactive les deux voies. */
    tmdbConfigured?: boolean;
};

/**
 * Au bout de combien de temps un balayage « en file » devient un symptôme.
 *
 * Passé ce délai sans qu'aucun worker n'ait pris le travail, ce n'est plus une
 * attente, c'est une panne — et c'est LA panne quotidienne en développement,
 * où personne n'a lancé `queue:listen`. L'écran doit le dire en français, pas
 * laisser un état « en cours » qui ne bouge jamais.
 */
const WORKER_GRACE_SECONDS = 60;

/**
 * Le journal des balayages.
 *
 * Quatre compteurs distincts, et jamais fondus en un seul : « ignoré » n'est
 * pas « refusé ». Un film ignoré l'a été par le filtre de goût ou parce qu'il
 * était déjà au catalogue ; un film refusé l'a été par le filtre de CONTENU,
 * que personne ne contourne. Les additionner effacerait la seule distinction
 * qui compte devant une mise en demeure.
 */
export function AdminImportRunTable({
    runs,
    showFilter = true,
    showResume = true,
    tmdbConfigured = true,
}: Props) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>{t('admin.import.runs.column.id')}</TableHead>
                    <TableHead>{t('admin.import.runs.column.kind')}</TableHead>
                    <TableHead>
                        {t('admin.import.runs.column.status')}
                    </TableHead>
                    <TableHead>{t('admin.import.runs.column.actor')}</TableHead>
                    {showFilter && (
                        <TableHead>
                            {t('admin.import.runs.column.filter')}
                        </TableHead>
                    )}
                    <TableHead className="text-right">
                        {t('admin.import.runs.column.seen')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.import.runs.column.imported')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.import.runs.column.skipped')}
                    </TableHead>
                    <TableHead className="text-right">
                        {t('admin.import.runs.column.refused')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.runs.column.started_at')}
                    </TableHead>
                    <TableHead>
                        {t('admin.import.runs.column.finished_at')}
                    </TableHead>
                    <TableHead>
                        <span className="sr-only">
                            {t('admin.catalog.column.actions')}
                        </span>
                    </TableHead>
                </TableRow>
            </TableHeader>

            <TableBody>
                {runs.map((run) => (
                    <TableRow key={run.id}>
                        <TableCell className="align-top tabular-nums">
                            {run.id}
                        </TableCell>

                        <TableCell className="align-top">
                            <ImportRunKindBadge value={run.run_kind} />
                        </TableCell>

                        <TableCell className="align-top">
                            <div className="flex flex-col gap-1">
                                <ImportRunStatusBadge run={run} />
                                {run.is_queued &&
                                    isOlderThan(
                                        run.created_at,
                                        WORKER_GRACE_SECONDS,
                                    ) && (
                                        <p className="flex max-w-prose items-start gap-1 text-xs text-muted-foreground">
                                            <TriangleAlertIcon
                                                aria-hidden
                                                className="mt-0.5 size-3 shrink-0"
                                            />
                                            {t(
                                                'admin.import.runs.worker_missing',
                                            )}
                                        </p>
                                    )}
                            </div>
                        </TableCell>

                        <TableCell className="align-top">
                            {run.actor_name ??
                                t('admin.common.deleted_account')}
                        </TableCell>

                        {showFilter && (
                            <TableCell className="align-top">
                                <div className="flex flex-col gap-1 text-xs text-muted-foreground">
                                    <span className="tabular-nums">
                                        {filterSummary(run, locale, t)}
                                    </span>
                                    {run.is_widened && <WidenedBadge />}
                                </div>
                            </TableCell>
                        )}

                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(run.total_seen, locale)}
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(run.total_imported, locale)}
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(run.total_skipped, locale)}
                        </TableCell>
                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(run.total_refused_content, locale)}
                        </TableCell>

                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(run.started_at, locale) ??
                                t('admin.common.none')}
                        </TableCell>
                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(run.finished_at, locale) ??
                                t('admin.common.none')}
                        </TableCell>

                        <TableCell className="align-top">
                            <div className="flex flex-wrap items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={runShow(run.id)}
                                        aria-label={t('admin.a11y.open_run', {
                                            id: run.id,
                                        })}
                                    >
                                        {t('admin.import.runs.open')}
                                    </Link>
                                </Button>
                                {showResume && (
                                    <ResumeButton
                                        run={run}
                                        tmdbConfigured={tmdbConfigured}
                                    />
                                )}
                            </div>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Le bouton « reprendre » — **désactivé avec son motif, jamais absent**.
 *
 * Seuls les balayages `discover` encore en cours sont reprenables : la liste
 * collée d'un `paste` n'est stockée nulle part, aucune colonne de la spec 10
 * ne la porte, et en inventer une serait empiéter sur le propriétaire du
 * schéma. Faire disparaître le bouton en silence laisserait un curateur
 * chercher ce qu'il a mal fait ; le refus nommé lui dit quoi faire — re-coller.
 */
export function ResumeButton({
    run,
    tmdbConfigured = true,
}: {
    run: AdminImportRunRow;
    tmdbConfigured?: boolean;
}) {
    const { t } = useTranslations();
    const reasonId = `resume-reason-${run.id}`;
    const reasonKey = resumeBlockedBy(run, tmdbConfigured);

    if (reasonKey !== null) {
        const reason = t(reasonKey);

        return (
            <span className="inline-flex">
                <Button
                    variant="secondary"
                    size="sm"
                    disabled
                    aria-describedby={reasonId}
                >
                    {t('admin.import.runs.resume')}
                </Button>
                <span id={reasonId} className="sr-only">
                    {reason}
                </span>
            </span>
        );
    }

    return (
        <Form
            {...ImportResumeController.store.form(run.id)}
            options={{ preserveScroll: true }}
            className="inline-flex"
        >
            {({ processing }) => (
                <Button variant="secondary" size="sm" disabled={processing}>
                    {t('admin.import.runs.resume')}
                </Button>
            )}
        </Form>
    );
}

/**
 * Ce qui empêche une reprise, ou `null` si rien ne l'empêche.
 *
 * Les trois refus sont distincts et jamais fondus en un « indisponible » :
 * re-coller une liste, attendre la fin d'un balayage et renseigner une clé
 * TMDB ne demandent pas le même geste au curateur.
 */
function resumeBlockedBy(
    run: AdminImportRunRow,
    tmdbConfigured: boolean,
): TranslationKey | null {
    if (run.run_kind !== 'discover') {
        return 'admin.import.runs.resume_unavailable_paste';
    }

    if (run.status !== 'running') {
        return 'admin.import.runs.resume_unavailable_finished';
    }

    // « En file » n'est pas « suspendu » : le job dort déjà dans la file, et
    // `ShouldBeUnique` avalerait un second dispatch en silence pendant que
    // l'écran annoncerait « balayage repris ». Le remède est côté serveur, hors
    // du chemin du curateur — ce que dit `worker_missing` juste à côté, sans
    // jamais nommer de commande (spec 20 § 13.1).
    if (run.is_queued) {
        return 'admin.import.runs.resume_unavailable_queued';
    }

    if (!tmdbConfigured) {
        return 'admin.import.disabled';
    }

    return null;
}

/**
 * Le filtre figé, en une ligne : seuil de votes, langues, année minimale.
 *
 * Ces trois colonnes sont copiées au démarrage et jamais recalculées — le
 * défaut du site peut changer après coup, la preuve de ce qu'un balayage a
 * appliqué, non.
 *
 * Le symbole de seuil et le séparateur passent par le dictionnaire : ce ne
 * sont pas des phrases, mais la ponctuation EST une convention de langue, et
 * ce composant n'est attaché au français par rien d'autre qu'une décision
 * réversible.
 */
function filterSummary(
    run: AdminImportRunRow,
    locale: string,
    t: Translate,
): string {
    const parts: string[] = [];

    if (run.filter_min_vote_count !== null) {
        parts.push(
            t('admin.common.at_least', {
                value: formatInteger(run.filter_min_vote_count, locale),
            }),
        );
    }

    if (run.filter_languages !== null) {
        parts.push(run.filter_languages);
    }

    if (run.filter_min_release_year !== null) {
        parts.push(
            t('admin.common.at_least', { value: run.filter_min_release_year }),
        );
    }

    return parts.join(t('admin.common.list_separator'));
}
