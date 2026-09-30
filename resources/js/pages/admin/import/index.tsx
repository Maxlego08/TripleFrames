import { Form, Head, router } from '@inertiajs/react';
import { DownloadCloudIcon, InfoIcon, TriangleAlertIcon } from 'lucide-react';
import { useCallback, useEffect, useEffectEvent, useState } from 'react';
import { toast } from 'sonner';
import ImportDiscoverController from '@/actions/App/Http/Controllers/Admin/ImportDiscoverController';
import ImportIdsController from '@/actions/App/Http/Controllers/Admin/ImportIdsController';
import ImportPreviewController from '@/actions/App/Http/Controllers/Admin/ImportPreviewController';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import {
    AdminImportRunTable,
    ResumeButton,
} from '@/components/admin/admin-import-run-table';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { ImportSearchPanel } from '@/components/admin/import-search-panel';
import { PastePreviewPanel } from '@/components/admin/paste-preview-panel';
import { SeedListPanel } from '@/components/admin/seed-list-panel';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslations } from '@/hooks/use-translations';
import { localeLabel } from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as importIndex } from '@/routes/admin/import';
import type {
    AdminImportDefaults,
    AdminImportRunRow,
    AdminPastePreview,
    AdminSeedList,
    AdminTmdbSearchResults,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    runs: Paginated<AdminImportRunRow>;
    defaults: AdminImportDefaults;
    resumable: AdminImportRunRow | null;
    tmdb_configured: boolean;
    seed_list: AdminSeedList;
    /** Le dernier aperçu à blanc de CE compte, sondé jusqu'à complétude. */
    paste_preview: AdminPastePreview | null;
    /** Cadence du sondage de l'aperçu (`catalog.curation.poll_seconds`). */
    poll_seconds: number;
    /** Présent sur `admin.import.search` seulement. */
    search_results?: AdminTmdbSearchResults;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.import', href: importIndex() },
];

/**
 * Cadence du rafraîchissement partiel pendant qu'un balayage tourne.
 *
 * Cinq secondes : assez pour voir les compteurs bouger, assez peu pour ne pas
 * marteler une base MySQL distante qui porte déjà la session, le cache ET la
 * file (`192.168.10.10`).
 */
const REFRESH_INTERVAL_MS = 5000;

/** Un seul toast « connexion perdue » à la fois, quel que soit le geste. */
const OFFLINE_TOAST_ID = 'admin-import-offline';

/**
 * Les deux voies d'import, et leur ASYMÉTRIE dite AVANT les formulaires.
 *
 * C'est le point de l'écran : le balayage `discover` applique le filtre de
 * notoriété, le collage d'identifiants l'ignore entièrement et marque chaque
 * film « entré par exception » avec son motif. Les filtres de CONTENU, eux, ne
 * sont contournables par aucune des deux voies ni par aucun rôle. Un curateur
 * qui ne comprend pas cette asymétrie collera des identifiants sans savoir
 * qu'il marque une exception — et la décision 11 interdit que le marquage soit
 * silencieux.
 *
 * Les deux envois sont DIFFÉRÉS et l'écran le dit : ils ouvrent une ligne
 * `import_run` et la confient à un worker. Aucun appel TMDB n'a lieu dans la
 * requête web — cinq pages coûtent 25 à 45 secondes d'appels séquentiels, là
 * où le SAPI web coupe à trente.
 */
export default function AdminImportIndex({
    runs,
    defaults,
    resumable,
    tmdb_configured,
    seed_list,
    paste_preview,
    poll_seconds,
    search_results,
}: Props) {
    const { t, locale } = useTranslations();

    /*
     * L'écran de recherche est la MÊME page, servie par une route à son propre
     * limiteur (`admin-tmdb-search`) : aucun sondage n'y tourne, sans quoi
     * chaque tick consommerait le quota des recherches. Le suivi en direct
     * reprend en fermant la recherche.
     */
    const isSearchPage = search_results !== undefined;

    // Un collage ouvert tient le verrou : « Importer » (recherche, aperçu)
    // attend sa fin, et le serveur refuserait de toute façon (§ 3.4).
    const pasteBusy = seed_list.busy_run_id !== null;

    // Le collage saisi, partagé par l'import direct et par « Prévisualiser ».
    const [pasteText, setPasteText] = useState('');

    // Déconnexion pendant une visite : rien n'est parti, la saisie reste en
    // place, et le curateur l'apprend (§ 13.5).
    const announceOffline = useEffectEvent((): void => {
        toast.error(t('admin.common.offline'), { id: OFFLINE_TOAST_ID });
    });

    useEffect(() => router.on('networkError', () => announceOffline()), []);

    const [minVotes, setMinVotes] = useState<number>(defaults.min_vote_count);
    const [minYear, setMinYear] = useState<number>(defaults.min_release_year);
    const [languages, setLanguages] = useState<string[]>(defaults.languages);

    const widened = isWiderThanDefault(
        { minVotes, minYear, languages },
        defaults,
    );

    const [refreshing, setRefreshing] = useState(false);
    const [refreshFailed, setRefreshFailed] = useState(false);

    // Un balayage ouvert fait vivre la page : `only` ne recharge que le journal,
    // le bouton de reprise et la liste d'amorçage, jamais les deux formulaires
    // — un curateur en train de coller cinquante identifiants ne doit pas les
    // voir disparaître. La liste d'amorçage est du lot parce qu'elle porte le
    // verrou du collage (`busy_run_id`) : le tick qui voit le collage fini
    // doit aussi rendre utiles « Importer la liste d'amorçage » et « Importer
    // ces films », et remplacer le lot déjà importé par le suivant (§ 3.4,
    // § 3.5).
    const isLive =
        !isSearchPage && runs.data.some((run) => run.status === 'running');

    const refresh = useCallback(() => {
        router.reload({
            only: ['runs', 'resumable', 'seed_list'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
            onSuccess: () => setRefreshFailed(false),
            // Décision 9 : aucun message brut, et un échec rejouable d'un
            // bouton. Un rafraîchissement qui ne revient pas laisserait sinon
            // des compteurs figés qu'aucun signe ne distingue d'un balayage
            // qui n'avance plus.
            onError: () => setRefreshFailed(true),
        });
    }, []);

    useEffect(() => {
        if (!isLive) {
            return;
        }

        const timer = window.setInterval(refresh, REFRESH_INTERVAL_MS);

        // Nettoyage indispensable : `strictMode` monte deux fois en
        // développement, et un intervalle non rendu se dédouble à chaque montage.
        return () => window.clearInterval(timer);
    }, [isLive, refresh]);

    return (
        <>
            <Head title={t('admin.import.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.import.heading')}
                    description={t('admin.import.description')}
                />

                {!tmdb_configured && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertTitle>{t('admin.import.disabled')}</AlertTitle>
                    </Alert>
                )}

                {isSearchPage && (
                    <Alert>
                        <InfoIcon />
                        <AlertDescription>
                            {t('admin.import.search.live_paused')}
                        </AlertDescription>
                    </Alert>
                )}

                {/* L'asymétrie, en toutes lettres, AVANT les deux cartes. */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.asymmetry.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {t('admin.import.asymmetry.discover')}
                        </p>
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {t('admin.import.asymmetry.paste')}
                        </p>
                        <p className="max-w-prose text-sm font-medium text-foreground">
                            {t('admin.import.asymmetry.content_filter')}
                        </p>
                        <Alert>
                            <InfoIcon />
                            <AlertDescription>
                                {t('admin.import.deferred_notice')}
                            </AlertDescription>
                        </Alert>
                    </CardContent>
                </Card>

                {/* `grid-cols-1` et non la piste implicite : celle-ci
                    s'élargirait à la largeur intrinsèque du collage — une
                    zone de texte à dimensionnement par contenu, dont le
                    texte indicatif porte une URL — et ferait défiler le
                    document à 390 px. */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    {/* Voie ordinaire */}
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.import.discover.heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.import.discover.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...ImportDiscoverController.store.form()}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <div className="space-y-1.5">
                                            <Label htmlFor="discover-min-votes">
                                                {t(
                                                    'admin.import.discover.min_votes.label',
                                                )}
                                            </Label>
                                            <Input
                                                id="discover-min-votes"
                                                name="min_votes"
                                                type="number"
                                                min={0}
                                                step={1}
                                                value={minVotes}
                                                onChange={(event) =>
                                                    setMinVotes(
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                disabled={!tmdb_configured}
                                                aria-describedby="discover-min-votes-hint"
                                            />
                                            <p
                                                id="discover-min-votes-hint"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t(
                                                    'admin.import.discover.min_votes.hint',
                                                    {
                                                        default: formatInteger(
                                                            defaults.min_vote_count,
                                                            locale,
                                                        ),
                                                    },
                                                )}
                                            </p>
                                            <AdminInputError
                                                message={errors.min_votes}
                                            />
                                        </div>

                                        <fieldset className="space-y-2">
                                            <legend className="text-sm font-medium text-foreground">
                                                {t(
                                                    'admin.import.discover.languages.label',
                                                )}
                                            </legend>
                                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                                {defaults.language_choices.map(
                                                    (language) => (
                                                        <div
                                                            key={language}
                                                            className="flex items-center gap-2"
                                                        >
                                                            <Checkbox
                                                                id={`discover-language-${language}`}
                                                                checked={languages.includes(
                                                                    language,
                                                                )}
                                                                disabled={
                                                                    !tmdb_configured
                                                                }
                                                                onCheckedChange={(
                                                                    checked,
                                                                ) =>
                                                                    setLanguages(
                                                                        (
                                                                            current,
                                                                        ) =>
                                                                            toggleLanguage(
                                                                                current,
                                                                                language,
                                                                                checked ===
                                                                                    true,
                                                                            ),
                                                                    )
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`discover-language-${language}`}
                                                            >
                                                                {localeLabel(
                                                                    language,
                                                                    t,
                                                                )}
                                                            </Label>
                                                        </div>
                                                    ),
                                                )}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'admin.import.discover.languages.hint',
                                                    {
                                                        default:
                                                            defaults.languages
                                                                .map(
                                                                    (
                                                                        language,
                                                                    ) =>
                                                                        localeLabel(
                                                                            language,
                                                                            t,
                                                                        ),
                                                                )
                                                                .join(', '),
                                                    },
                                                )}
                                            </p>
                                            <AdminInputError
                                                message={errors.languages}
                                            />
                                            {/* La sélection part en champs cachés : c'est l'état React
                                                qui fait foi, et non la case de Radix. */}
                                            {languages.map((language) => (
                                                <input
                                                    key={language}
                                                    type="hidden"
                                                    name="languages[]"
                                                    value={language}
                                                />
                                            ))}
                                        </fieldset>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="discover-min-year">
                                                {t(
                                                    'admin.import.discover.min_year.label',
                                                )}
                                            </Label>
                                            <Input
                                                id="discover-min-year"
                                                name="min_year"
                                                type="number"
                                                min={1888}
                                                step={1}
                                                value={minYear}
                                                onChange={(event) =>
                                                    setMinYear(
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                disabled={!tmdb_configured}
                                                aria-describedby="discover-min-year-hint"
                                            />
                                            <p
                                                id="discover-min-year-hint"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t(
                                                    'admin.import.discover.min_year.hint',
                                                    {
                                                        default:
                                                            defaults.min_release_year,
                                                    },
                                                )}
                                            </p>
                                            <AdminInputError
                                                message={errors.min_year}
                                            />
                                        </div>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="discover-pages">
                                                {t(
                                                    'admin.import.discover.pages.label',
                                                )}
                                            </Label>
                                            <Input
                                                id="discover-pages"
                                                name="pages"
                                                type="number"
                                                min={defaults.pages_min}
                                                max={defaults.pages_max}
                                                step={1}
                                                defaultValue={
                                                    defaults.pages_default
                                                }
                                                disabled={!tmdb_configured}
                                                aria-describedby="discover-pages-hint"
                                            />
                                            <p
                                                id="discover-pages-hint"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t(
                                                    'admin.import.discover.pages.hint',
                                                    {
                                                        min: defaults.pages_min,
                                                        max: defaults.pages_max,
                                                    },
                                                )}
                                            </p>
                                            <AdminInputError
                                                message={errors.pages}
                                            />
                                        </div>

                                        {widened && (
                                            <Alert>
                                                <TriangleAlertIcon />
                                                <AlertDescription>
                                                    {t(
                                                        'admin.import.discover.widened_warning',
                                                    )}
                                                </AlertDescription>
                                            </Alert>
                                        )}

                                        <Button
                                            type="submit"
                                            disabled={
                                                processing || !tmdb_configured
                                            }
                                        >
                                            {t('admin.import.discover.submit')}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>

                    {/* Voie d'exception */}
                    <Card>
                        <CardHeader>
                            <AdminCardTitle>
                                {t('admin.import.ids.heading')}
                            </AdminCardTitle>
                            <CardDescription>
                                {t('admin.import.ids.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Form
                                {...ImportIdsController.store.form()}
                                options={{ preserveScroll: true }}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <Alert>
                                            <TriangleAlertIcon />
                                            <AlertDescription>
                                                {t(
                                                    'admin.import.ids.exception_notice',
                                                )}
                                            </AlertDescription>
                                        </Alert>

                                        <div className="space-y-1.5">
                                            <Label htmlFor="ids-list">
                                                {t(
                                                    'admin.import.ids.list.label',
                                                )}
                                            </Label>
                                            <Textarea
                                                id="ids-list"
                                                name="ids"
                                                rows={8}
                                                spellCheck={false}
                                                value={pasteText}
                                                onChange={(event) =>
                                                    setPasteText(
                                                        event.target.value,
                                                    )
                                                }
                                                placeholder={t(
                                                    'admin.import.ids.list.placeholder',
                                                )}
                                                disabled={!tmdb_configured}
                                                aria-describedby="ids-list-hint"
                                            />
                                            <p
                                                id="ids-list-hint"
                                                className="text-xs text-muted-foreground"
                                            >
                                                {t(
                                                    'admin.import.ids.list.hint',
                                                    {
                                                        max: defaults.paste_max_ids,
                                                    },
                                                )}
                                            </p>
                                            <AdminInputError
                                                message={errors.ids}
                                            />
                                        </div>

                                        <Button
                                            type="submit"
                                            disabled={
                                                processing || !tmdb_configured
                                            }
                                        >
                                            {t('admin.import.ids.submit')}
                                        </Button>
                                    </>
                                )}
                            </Form>

                            {/* L'aperçu à blanc du MÊME collage (§ 3.3) :
                                un formulaire à part, qui poste la saisie
                                telle quelle, et ses propres erreurs. */}
                            <Form
                                {...ImportPreviewController.store.form()}
                                options={{ preserveScroll: true }}
                                className="mt-4 space-y-2 border-t border-border pt-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="ids"
                                            value={pasteText}
                                        />
                                        <p
                                            id="ids-preview-hint"
                                            className="max-w-prose text-xs text-muted-foreground"
                                        >
                                            {t('admin.import.ids.preview_hint')}
                                        </p>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={
                                                processing || !tmdb_configured
                                            }
                                            aria-describedby="ids-preview-hint"
                                        >
                                            {t('admin.import.ids.preview')}
                                        </Button>
                                        <AdminInputError message={errors.ids} />
                                    </>
                                )}
                            </Form>
                        </CardContent>
                    </Card>
                </div>

                <ImportSearchPanel
                    results={search_results}
                    defaults={defaults}
                    tmdbConfigured={tmdb_configured}
                    pasteBusy={pasteBusy}
                />

                <SeedListPanel
                    seedList={seed_list}
                    tmdbConfigured={tmdb_configured}
                />

                {paste_preview !== null && (
                    <PastePreviewPanel
                        key={paste_preview.token}
                        preview={paste_preview}
                        live={!isSearchPage}
                        pollSeconds={poll_seconds}
                        tmdbConfigured={tmdb_configured}
                        pasteBusy={pasteBusy}
                    />
                )}

                {/* Journal des balayages */}
                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.import.runs.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.import.runs.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {isLive && (
                            <p
                                role="status"
                                className="text-xs text-muted-foreground"
                            >
                                {t('admin.import.run.live')}
                            </p>
                        )}

                        {refreshFailed && (
                            <AdminErrorState
                                title={t('admin.common.error')}
                                retryLabel={t('admin.common.refresh')}
                                onRetry={refresh}
                            />
                        )}

                        {refreshing && (
                            <AdminLoadingState
                                label={t('admin.common.loading')}
                                rows={1}
                            />
                        )}

                        {resumable !== null && (
                            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted p-3">
                                <p className="text-sm text-foreground">
                                    {t('admin.import.run.heading', {
                                        id: resumable.id,
                                    })}
                                </p>
                                <ResumeButton
                                    run={resumable}
                                    tmdbConfigured={tmdb_configured}
                                />
                            </div>
                        )}

                        {runs.data.length === 0 ? (
                            <AdminEmptyState
                                icon={DownloadCloudIcon}
                                title={t('admin.import.runs.empty')}
                            />
                        ) : (
                            <>
                                <AdminImportRunTable
                                    runs={runs.data}
                                    tmdbConfigured={tmdb_configured}
                                />
                                <AdminPagination
                                    meta={runs.meta}
                                    href={(page) =>
                                        importIndex({ query: { page } })
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

AdminImportIndex.layout = { breadcrumbs };

/**
 * Le filtre saisi élargit-il le défaut du site ?
 *
 * Transcription littérale de `ImportFilter::isWiderThanDefault()` — moins de
 * votes, une année plus basse, ou une langue hors du défaut. Ce n'est qu'un
 * AVERTISSEMENT : la valeur qui compte est celle que le serveur fige dans
 * `import_run.is_widened` au démarrage. Le front la devine pour prévenir, il
 * ne la décide pas.
 */
function isWiderThanDefault(
    current: { minVotes: number; minYear: number; languages: string[] },
    defaults: AdminImportDefaults,
): boolean {
    if (current.minVotes < defaults.min_vote_count) {
        return true;
    }

    if (current.minYear < defaults.min_release_year) {
        return true;
    }

    return current.languages.some(
        (language) => !defaults.languages.includes(language),
    );
}

/** Ajoute ou retire une langue, sans jamais réordonner celles qui restent. */
function toggleLanguage(
    current: string[],
    language: string,
    checked: boolean,
): string[] {
    if (checked) {
        return current.includes(language) ? current : [...current, language];
    }

    return current.filter((value) => value !== language);
}
