import { Form, Head, Link } from '@inertiajs/react';
import { RefreshCwIcon } from 'lucide-react';
import MovieResyncController from '@/actions/App/Http/Controllers/Admin/MovieResyncController';
import {
    AvailabilityBadge,
    ContentFlagBadge,
} from '@/components/admin/admin-badges';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import { AdminInputError } from '@/components/admin/admin-input-error';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslations } from '@/hooks/use-translations';
import type { Translator } from '@/hooks/use-translations';
import { CONTENT_FLAG_KEYS, localeLabel } from '@/lib/admin-enum-keys';
import {
    PROPOSE_QUERY_PARAMETER,
    PROPOSE_UNPUBLISH,
} from '@/lib/admin-catalog-query';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import {
    index as catalogIndex,
    show as catalogShow,
} from '@/routes/admin/catalog';
import { show as runShow } from '@/routes/admin/import';
import type {
    AdminResyncField,
    AdminResyncIneligibility,
    AdminResyncMovie,
    AdminResyncPreview,
    AdminResyncRow,
    ContentFlag,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';
import type { TranslationKey } from '@/types/translations';

type Props = {
    movies: AdminResyncMovie[];
    eligible_count: number;
    max_movies: number;
    preview: AdminResyncPreview | null;
    tmdb_configured: boolean;
    open_run: { id: number } | null;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.catalog', href: catalogIndex() },
    {
        title: 'admin.resync.title',
        href: MovieResyncController.show(),
    },
];

const FIELD_KEYS: Record<AdminResyncField, TranslationKey> = {
    title_original: 'admin.resync.fields.title_original',
    title_original_latin: 'admin.resync.fields.title_original_latin',
    original_language: 'admin.resync.fields.original_language',
    release_year: 'admin.resync.fields.release_year',
    vote_count: 'admin.resync.fields.vote_count',
    adult: 'admin.resync.fields.adult',
    collection_id: 'admin.resync.fields.collection_id',
    genres: 'admin.resync.fields.genres',
    companies: 'admin.resync.fields.companies',
    movie_certification: 'admin.resync.fields.movie_certification',
    movie_title: 'admin.resync.fields.movie_title',
    alias: 'admin.resync.fields.alias',
    content_flag: 'admin.resync.fields.content_flag',
};

const INELIGIBLE_KEYS: Record<AdminResyncIneligibility, TranslationKey> = {
    withdrawn: 'admin.resync.selection.ineligible.withdrawn',
    demo: 'admin.resync.selection.ineligible.demo',
    no_tmdb: 'admin.resync.selection.ineligible.no_tmdb',
};

const PREVIEW_STATE_KEYS: Record<
    'not_configured' | 'unavailable' | 'not_found',
    TranslationKey
> = {
    not_configured: 'admin.resync.preview.not_configured',
    unavailable: 'admin.resync.preview.unavailable',
    not_found: 'admin.resync.preview.not_found',
};

/**
 * La resynchronisation TMDB (spec 20 § 3.7, ligne 26, L20-24).
 *
 * Un film seul : l'**écran de différences** — la fiche TMDB relue, bornée à
 * la liste close de ce qui est écrasable, ligne par ligne « changé » ou
 * « identique » —, les alias que la lecture fera réapparaître, et, quand une
 * certification restrictive apparaît, la proposition de dépublier le film
 * ensuite, jamais prononcée par la resynchronisation. Un lot : la sélection,
 * chaque film écarté nommé avec son motif. Le lancement met le balayage en
 * file ; son résumé est l'écran du balayage.
 */
export default function AdminCatalogResync({
    movies,
    eligible_count,
    max_movies,
    preview,
    tmdb_configured,
    open_run,
}: Props) {
    const { t, locale } = useTranslations();
    const single = movies.length === 1 ? movies[0] : null;
    const launchable =
        tmdb_configured && open_run === null && eligible_count > 0;

    return (
        <>
            <Head title={t('admin.resync.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.resync.heading')}
                    description={t('admin.resync.description')}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={catalogIndex()}>
                                {t('admin.resync.back')}
                            </Link>
                        </Button>
                    }
                />

                {open_run !== null && (
                    <Alert>
                        <AlertTitle>{t('admin.resync.running')}</AlertTitle>
                        <AlertDescription>
                            <Link
                                href={runShow(open_run.id)}
                                className="underline underline-offset-4"
                            >
                                {t('admin.resync.running_link')}
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.resync.selection.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.resync.selection.description', {
                                eligible: formatInteger(eligible_count, locale),
                                total: formatInteger(movies.length, locale),
                                max: formatInteger(max_movies, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="flex flex-col gap-2">
                            {movies.map((movie) => (
                                <li
                                    key={movie.id}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <Link
                                        href={catalogShow(movie.id)}
                                        className="font-medium text-foreground underline-offset-4 hover:underline"
                                    >
                                        {movieLabel(movie, t)}
                                    </Link>
                                    <AvailabilityBadge
                                        value={movie.availability}
                                    />
                                    <ContentFlagBadge
                                        value={movie.content_flag}
                                    />
                                    {movie.ineligible !== null && (
                                        <Badge variant="outline">
                                            {t(
                                                INELIGIBLE_KEYS[
                                                    movie.ineligible
                                                ],
                                            )}
                                        </Badge>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>

                {single !== null && preview !== null && (
                    <PreviewCard movie={single} preview={preview} />
                )}

                <Card>
                    <CardContent className="flex flex-col gap-4 pt-6">
                        <p className="max-w-prose text-sm text-muted-foreground">
                            {t('admin.resync.deferred_notice')}
                        </p>

                        <Form
                            {...MovieResyncController.store.form()}
                            className="flex flex-col gap-3"
                        >
                            {({ processing, errors }) => (
                                <>
                                    {movies
                                        .filter(
                                            (movie) =>
                                                movie.ineligible === null,
                                        )
                                        .map((movie) => (
                                            <input
                                                key={movie.id}
                                                type="hidden"
                                                name="movies[]"
                                                value={movie.id}
                                            />
                                        ))}

                                    <AdminInputError
                                        message={Object.values(errors)[0]}
                                    />

                                    <Button
                                        type="submit"
                                        aria-disabled={
                                            !launchable ||
                                            processing ||
                                            undefined
                                        }
                                        aria-busy={processing || undefined}
                                        onClick={(event) => {
                                            if (!launchable || processing) {
                                                event.preventDefault();
                                            }
                                        }}
                                        className="min-h-11 self-start aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                                    >
                                        <RefreshCwIcon aria-hidden />
                                        {single !== null
                                            ? t('admin.resync.submit')
                                            : t('admin.resync.submit_batch', {
                                                  count: formatInteger(
                                                      eligible_count,
                                                      locale,
                                                  ),
                                              })}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminCatalogResync.layout = { breadcrumbs };

/** « Titre (année) », ou le titre seul quand l'année est inconnue. */
function movieLabel(
    movie: { title_original: string; release_year: number | null },
    t: Translator['t'],
): string {
    return movie.release_year === null
        ? t('admin.movie.group.movie_without_year', {
              title: movie.title_original,
          })
        : t('admin.movie.group.movie', {
              title: movie.title_original,
              year: movie.release_year,
          });
}

/** L'écran de différences d'un film seul, ou l'état qui l'empêche. */
function PreviewCard({
    movie,
    preview,
}: {
    movie: AdminResyncMovie;
    preview: AdminResyncPreview;
}) {
    const { t, locale } = useTranslations();

    if (preview.status !== 'ready') {
        return (
            <Alert variant="destructive">
                <AlertTitle>{t('admin.resync.preview.heading')}</AlertTitle>
                <AlertDescription>
                    {t(PREVIEW_STATE_KEYS[preview.status])}
                </AlertDescription>
            </Alert>
        );
    }

    const readAt = formatMoment(preview.certifications_read_at, locale);

    return (
        <Card>
            <CardHeader>
                <AdminCardTitle>
                    {t('admin.resync.preview.heading')}
                </AdminCardTitle>
                <CardDescription>
                    {t('admin.resync.preview.description')}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {preview.content_flag_blocked && (
                    <Alert variant="destructive">
                        <AlertTitle>
                            {t('admin.resync.preview.blocked.heading')}
                        </AlertTitle>
                        <AlertDescription className="space-y-2">
                            <p>
                                {t('admin.resync.preview.blocked.description')}
                            </p>
                            {preview.propose_unpublish && (
                                <Link
                                    href={catalogShow(movie.id, {
                                        query: {
                                            [PROPOSE_QUERY_PARAMETER]:
                                                PROPOSE_UNPUBLISH,
                                        },
                                    })}
                                    className="underline underline-offset-4"
                                >
                                    {t('admin.resync.preview.blocked.propose')}
                                </Link>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {preview.changed.length === 0 && (
                    <p role="status" className="text-sm text-muted-foreground">
                        {t('admin.resync.preview.no_change')}
                    </p>
                )}

                <p className="text-sm text-muted-foreground">
                    {readAt === null
                        ? t('admin.resync.preview.never_read')
                        : t('admin.resync.preview.read_at', { date: readAt })}
                </p>

                {preview.curator_title_locales.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.resync.preview.curator_titles', {
                            locales: preview.curator_title_locales
                                .map((code) => localeLabel(code, t))
                                .join(t('admin.common.list_separator')),
                        })}
                    </p>
                )}

                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>
                                {t('admin.resync.preview.column.field')}
                            </TableHead>
                            <TableHead>
                                {t('admin.resync.preview.column.before')}
                            </TableHead>
                            <TableHead>
                                {t('admin.resync.preview.column.after')}
                            </TableHead>
                            <TableHead>
                                {t('admin.resync.preview.column.state')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {preview.rows.map((row) => (
                            <TableRow key={row.field}>
                                <TableCell className="align-top font-medium">
                                    {t(FIELD_KEYS[row.field])}
                                </TableCell>
                                <TableCell className="align-top">
                                    <ValueList row={row} side="before" />
                                </TableCell>
                                <TableCell className="align-top">
                                    <ValueList row={row} side="after" />
                                </TableCell>
                                <TableCell className="align-top">
                                    {row.changed ? (
                                        <Badge>
                                            {t('admin.resync.preview.changed')}
                                        </Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            {t('admin.resync.preview.same')}
                                        </Badge>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>

                {preview.reappeared_aliases.length > 0 && (
                    <section
                        aria-labelledby="resync-reappeared-heading"
                        className="space-y-2"
                    >
                        <h3
                            id="resync-reappeared-heading"
                            className="text-sm font-semibold text-foreground"
                        >
                            {t('admin.resync.preview.reappeared.heading')}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            {t('admin.resync.preview.reappeared.description')}
                        </p>
                        <ul className="flex flex-wrap gap-2">
                            {preview.reappeared_aliases.map((alias) => (
                                <li key={alias}>
                                    <Badge variant="outline">{alias}</Badge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * Les valeurs d'un champ d'un côté de la comparaison. Le drapeau de contenu
 * et « adulte » se traduisent ; le reste est une donnée TMDB affichée telle
 * quelle.
 */
function ValueList({
    row,
    side,
}: {
    row: AdminResyncRow;
    side: 'before' | 'after';
}) {
    const { t } = useTranslations();
    const values = row[side];

    if (values.length === 0) {
        return (
            <span className="text-muted-foreground">
                {t('admin.resync.preview.empty')}
            </span>
        );
    }

    const display = (value: string): string => {
        if (row.field === 'adult') {
            return value === '1' ? t('admin.common.yes') : t('admin.common.no');
        }

        if (row.field === 'content_flag' && value in CONTENT_FLAG_KEYS) {
            return t(CONTENT_FLAG_KEYS[value as ContentFlag]);
        }

        return value;
    };

    return (
        <ul className="space-y-0.5 text-sm break-words">
            {values.map((value) => (
                <li key={value}>{display(value)}</li>
            ))}
        </ul>
    );
}
