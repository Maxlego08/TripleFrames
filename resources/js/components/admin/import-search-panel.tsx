import { Form, Link, router } from '@inertiajs/react';
import { SearchIcon, SearchXIcon } from 'lucide-react';
import { useState } from 'react';
import ImportIdsController from '@/actions/App/Http/Controllers/Admin/ImportIdsController';
import ImportSearchController from '@/actions/App/Http/Controllers/Admin/ImportSearchController';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminErrorState } from '@/components/admin/admin-error-state';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminLoadingState } from '@/components/admin/admin-loading-state';
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
import { AVAILABILITY_KEYS, localeLabel } from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { show as catalogShow } from '@/routes/admin/catalog';
import { index as importIndex } from '@/routes/admin/import';
import type {
    AdminImportDefaults,
    AdminTmdbSearchItem,
    AdminTmdbSearchResults,
} from '@/types/admin';

type Props = {
    /** Absent sur `admin.import.index` : la recherche n'a pas été lancée. */
    results: AdminTmdbSearchResults | undefined;
    defaults: AdminImportDefaults;
    tmdbConfigured: boolean;
    /** Un collage est ouvert : « Importer » attend sa fin (§ 3.4). */
    pasteBusy: boolean;
};

/**
 * La recherche TMDB et l'import unitaire — spec 20 § 3.4.
 *
 * La recherche est une visite GET vers sa propre route, à son propre
 * limiteur ; « Importer » poste UN identifiant sur la voie de collage, donc
 * marqué exception, et sous le verrou d'un seul collage ouvert à la fois. Le
 * bouton est inactif pendant un collage ouvert — le serveur le refuserait de
 * toute façon, par un message traduit.
 *
 * Chaque état a sa copie : chargement, vide, quota atteint ou panne
 * (« Réessayer »), clé absente. Aucune panne n'est une page d'erreur.
 */
export function ImportSearchPanel({
    results,
    defaults,
    tmdbConfigured,
    pasteBusy,
}: Props) {
    const { t } = useTranslations();

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.import.search.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.import.search.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <Form
                    {...ImportSearchController.index.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                                <div className="flex-1 space-y-1.5">
                                    <Label htmlFor="import-search-q">
                                        {t('admin.import.search.label')}
                                    </Label>
                                    <Input
                                        id="import-search-q"
                                        name="q"
                                        type="search"
                                        defaultValue={results?.query ?? ''}
                                        minLength={defaults.search_min_length}
                                        maxLength={defaults.search_max_length}
                                        required
                                        autoComplete="off"
                                        placeholder={t(
                                            'admin.import.search.placeholder',
                                        )}
                                        disabled={!tmdbConfigured}
                                        aria-invalid={
                                            errors.q === undefined
                                                ? undefined
                                                : true
                                        }
                                        aria-describedby="import-search-hint import-search-error"
                                    />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="submit"
                                        className="min-h-11"
                                        disabled={processing || !tmdbConfigured}
                                    >
                                        <SearchIcon aria-hidden />
                                        {t('admin.import.search.submit')}
                                    </Button>
                                    {results !== undefined && (
                                        <Button
                                            asChild
                                            variant="ghost"
                                            className="min-h-11"
                                        >
                                            <Link href={importIndex()}>
                                                {t('admin.import.search.close')}
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <p
                                id="import-search-hint"
                                className="text-xs text-muted-foreground"
                            >
                                {t('admin.import.search.hint', {
                                    min: defaults.search_min_length,
                                    max: defaults.search_max_length,
                                })}
                            </p>
                            <AdminInputError
                                id="import-search-error"
                                message={errors.q}
                            />
                            {processing && (
                                <AdminLoadingState
                                    label={t('admin.import.search.loading')}
                                    rows={3}
                                />
                            )}
                        </>
                    )}
                </Form>

                {results !== undefined && (
                    <SearchResults
                        results={results}
                        tmdbConfigured={tmdbConfigured}
                        pasteBusy={pasteBusy}
                    />
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Les résultats, ou l'état qui en tient lieu.
 */
function SearchResults({
    results,
    tmdbConfigured,
    pasteBusy,
}: {
    results: AdminTmdbSearchResults;
    tmdbConfigured: boolean;
    pasteBusy: boolean;
}) {
    const { t, locale } = useTranslations();
    const [retrying, setRetrying] = useState(false);

    // « Réessayer » rappelle TMDB par un rechargement partiel de la seule
    // prop de recherche : le journal et les formulaires restent en place.
    function retry(): void {
        router.reload({
            only: ['search_results'],
            onStart: () => setRetrying(true),
            onFinish: () => setRetrying(false),
        });
    }

    if (retrying) {
        return (
            <AdminLoadingState
                label={t('admin.import.search.loading')}
                rows={3}
            />
        );
    }

    if (results.status === 'rate_limited' || results.status === 'failed') {
        return (
            <AdminErrorState
                title={t(results.error_key ?? 'admin.import.search.failed')}
                retryLabel={t('admin.import.search.retry')}
                onRetry={retry}
            />
        );
    }

    if (results.status === 'not_configured') {
        return (
            <AdminErrorState
                title={t(results.error_key ?? 'admin.error.tmdb_disabled')}
            />
        );
    }

    if (results.status === 'empty' || results.items.length === 0) {
        return (
            <AdminEmptyState
                icon={SearchXIcon}
                title={t('admin.import.search.empty')}
            />
        );
    }

    return (
        <div className="space-y-3">
            <p role="status" className="text-sm text-muted-foreground">
                {t('admin.import.search.results', {
                    count: formatInteger(results.total_results, locale),
                    query: results.query,
                })}
            </p>

            {pasteBusy && (
                <p className="text-sm text-muted-foreground">
                    {t('admin.import.search.import_busy')}
                </p>
            )}

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>
                            {t('admin.import.search.column.title')}
                        </TableHead>
                        <TableHead>
                            {t('admin.import.search.column.year')}
                        </TableHead>
                        <TableHead>
                            {t('admin.import.search.column.language')}
                        </TableHead>
                        <TableHead className="text-right">
                            {t('admin.import.search.column.votes')}
                        </TableHead>
                        <TableHead>
                            {t('admin.import.search.column.state')}
                        </TableHead>
                        <TableHead>
                            <span className="sr-only">
                                {t('admin.import.search.column.actions')}
                            </span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {results.items.map((item) => (
                        <SearchRow
                            key={item.tmdb_id}
                            item={item}
                            disabled={!tmdbConfigured || pasteBusy}
                        />
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

/**
 * Un résultat : titres, année, langue, votes, état local, et le geste.
 */
function SearchRow({
    item,
    disabled,
}: {
    item: AdminTmdbSearchItem;
    disabled: boolean;
}) {
    const { t, locale } = useTranslations();

    const withdrawn = item.catalog?.availability === 'withdrawn';

    return (
        <TableRow>
            <TableCell className="align-top">
                <div className="flex flex-col gap-0.5">
                    <span className="font-medium text-foreground">
                        {item.title}
                    </span>
                    {item.title_original !== item.title && (
                        <span className="text-xs text-muted-foreground">
                            {t('admin.common.label_value', {
                                label: t(
                                    'admin.import.search.column.title_original',
                                ),
                                value: item.title_original,
                            })}
                        </span>
                    )}
                </div>
            </TableCell>
            <TableCell className="align-top tabular-nums">
                {item.release_year ?? t('admin.common.unknown')}
            </TableCell>
            <TableCell className="align-top">
                {localeLabel(item.original_language, t)}
            </TableCell>
            <TableCell className="text-right align-top tabular-nums">
                {formatInteger(item.vote_count, locale)}
            </TableCell>
            <TableCell className="align-top">
                {item.catalog === null ? (
                    <Badge variant="outline">
                        {t('admin.import.search.state.absent')}
                    </Badge>
                ) : withdrawn ? (
                    <Badge variant="destructive">
                        {t('admin.import.search.state.withdrawn')}
                    </Badge>
                ) : (
                    <Badge variant="secondary">
                        {t('admin.import.search.state.in_catalog', {
                            availability: t(
                                AVAILABILITY_KEYS[item.catalog.availability],
                            ),
                        })}
                    </Badge>
                )}
            </TableCell>
            <TableCell className="align-top">
                {item.catalog === null ? (
                    <Form
                        {...ImportIdsController.store.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="ids"
                                    value={String(item.tmdb_id)}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="min-h-11 min-w-11"
                                    disabled={processing || disabled}
                                    aria-label={t(
                                        'admin.import.search.import_label',
                                        { title: item.title },
                                    )}
                                >
                                    {t('admin.import.search.import')}
                                </Button>
                            </>
                        )}
                    </Form>
                ) : (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="min-h-11"
                    >
                        <Link href={catalogShow(item.catalog.movie_id)}>
                            {t('admin.import.search.open_movie')}
                        </Link>
                    </Button>
                )}
            </TableCell>
        </TableRow>
    );
}
