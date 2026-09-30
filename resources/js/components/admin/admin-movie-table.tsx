import type { InertiaLinkProps } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { ArrowDownIcon, ArrowUpIcon } from 'lucide-react';
import {
    AvailabilityBadge,
    ContentFlagBadge,
    ExceptionBadge,
    ImportSourceBadge,
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
import { EXCEPTION_MOTIVE_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger, formatMoment } from '@/lib/admin-format';
import { show as catalogShow } from '@/routes/admin/catalog';
import type { AdminCatalogSortDirection, AdminMovieRow } from '@/types/admin';
import type { TranslationKey } from '@/types/translations';

/**
 * Le tri, tel que la liste du catalogue le pilote.
 *
 * Il est **optionnel** : le tableau de bord et le détail d'un balayage servent
 * exactement les mêmes colonnes sans offrir de tri, et ils n'ont pas à porter
 * de query string pour cela. C'est la frontière que la règle 5 demande — la
 * présentation ne sait rien du filtrage, elle reçoit des URL déjà faites.
 */
export type AdminMovieTableSort = {
    active: string;
    direction: AdminCatalogSortDirection;
    href: (column: string) => NonNullable<InertiaLinkProps['href']>;
};

type Props = {
    movies: AdminMovieRow[];
    sort?: AdminMovieTableSort;
};

type Column = {
    key: string;
    label: TranslationKey;
    /** Clé de tri acceptée par `CatalogIndexRequest::SORTS`, ou rien. */
    sortKey?: string;
    numeric?: boolean;
};

const COLUMNS: Column[] = [
    {
        key: 'title',
        label: 'admin.catalog.column.title',
        sortKey: 'title_original',
    },
    {
        key: 'year',
        label: 'admin.catalog.column.year',
        sortKey: 'release_year',
        numeric: true,
    },
    { key: 'language', label: 'admin.catalog.column.language' },
    {
        key: 'votes',
        label: 'admin.catalog.column.votes',
        sortKey: 'vote_count',
        numeric: true,
    },
    { key: 'availability', label: 'admin.catalog.column.availability' },
    { key: 'content_flag', label: 'admin.catalog.column.content_flag' },
    { key: 'source', label: 'admin.catalog.column.source' },
    {
        key: 'levels',
        label: 'admin.catalog.column.levels',
        sortKey: 'levels_count',
        numeric: true,
    },
    {
        key: 'variants',
        label: 'admin.catalog.column.variants',
        sortKey: 'variants_total',
        numeric: true,
    },
    {
        key: 'entered_at',
        label: 'admin.catalog.column.entered_at',
        sortKey: 'created_at',
    },
    { key: 'actions', label: 'admin.catalog.column.actions' },
];

/** Le nombre de niveaux exigé pour couvrir 1, 3 et 5 — affiché en `n / 5`. */
const LEVELS_SCALE = 5;

/**
 * La table de films, partagée par les trois listes de ce lot.
 *
 * Elle ne filtre rien, ne trie rien et ne connaît aucune query string : elle
 * reçoit des lignes et, éventuellement, des URL de tri déjà construites. C'est
 * ce qui permet de la re-skinner sans toucher au filtrage — et de la réutiliser
 * telle quelle sur un écran qui n'a pas de filtres.
 *
 * Le défilement horizontal est porté par le conteneur de `<Table>`
 * (`overflow-x-auto`) : onze colonnes ne tiennent pas en portrait, et c'est le
 * tableau qui défile, jamais le `<body>` (règle 10).
 */
export function AdminMovieTable({ movies, sort }: Props) {
    const { t, locale } = useTranslations();

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    {COLUMNS.map((column) => (
                        <TableHead
                            key={column.key}
                            className={column.numeric ? 'text-right' : ''}
                            aria-sort={ariaSort(column, sort)}
                        >
                            {column.key === 'actions' ? (
                                <span className="sr-only">
                                    {t(column.label)}
                                </span>
                            ) : (
                                <SortableHead column={column} sort={sort} />
                            )}
                        </TableHead>
                    ))}
                </TableRow>
            </TableHeader>

            <TableBody>
                {movies.map((movie) => (
                    <TableRow key={movie.id}>
                        <TableCell className="align-top">
                            <div className="flex min-w-0 flex-col gap-1">
                                <span className="font-medium text-foreground">
                                    {movie.title_original}
                                </span>
                                {movie.title_original_latin !== null && (
                                    <span className="text-xs text-muted-foreground">
                                        {movie.title_original_latin}
                                    </span>
                                )}
                                {movie.is_import_exception && (
                                    <ExceptionMotives movie={movie} />
                                )}
                            </div>
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {movie.release_year === null
                                ? t('admin.common.unknown')
                                : movie.release_year}
                        </TableCell>

                        <TableCell className="align-top uppercase">
                            {movie.original_language}
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(movie.vote_count, locale)}
                        </TableCell>

                        <TableCell className="align-top">
                            <AvailabilityBadge value={movie.availability} />
                        </TableCell>

                        <TableCell className="align-top">
                            <ContentFlagBadge value={movie.content_flag} />
                        </TableCell>

                        <TableCell className="align-top">
                            <ImportSourceBadge value={movie.import_source} />
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {t('admin.movie.projection.levels_ratio', {
                                count: movie.levels_count,
                                total: LEVELS_SCALE,
                            })}
                        </TableCell>

                        <TableCell className="text-right align-top tabular-nums">
                            {formatInteger(movie.variants_total, locale)}
                        </TableCell>

                        <TableCell className="align-top whitespace-nowrap">
                            {formatMoment(movie.created_at, locale) ??
                                t('admin.common.unknown')}
                        </TableCell>

                        <TableCell className="align-top">
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={catalogShow(movie.id)}
                                    aria-label={t('admin.a11y.open_movie', {
                                        title: movie.title_original,
                                    })}
                                >
                                    {t('admin.catalog.row.open')}
                                </Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Les motifs d'entrée par exception, nommés sous le titre.
 *
 * La décision 11 interdit que le marquage soit silencieux : le badge dit
 * « exception », les motifs disent *pourquoi*. Un film peut en cumuler
 * plusieurs — langue rare ET sortie ancienne — et les trois sont donc lus
 * indépendamment, jamais en cascade.
 */
function ExceptionMotives({ movie }: { movie: AdminMovieRow }) {
    const { t } = useTranslations();

    const motives: TranslationKey[] = [];

    if (movie.exception_for_language) {
        motives.push(EXCEPTION_MOTIVE_KEYS.language);
    }

    if (movie.exception_for_vote_count) {
        motives.push(EXCEPTION_MOTIVE_KEYS.vote_count);
    }

    if (movie.exception_for_release_year) {
        motives.push(EXCEPTION_MOTIVE_KEYS.release_year);
    }

    return (
        <div className="flex flex-col gap-1">
            <ExceptionBadge />
            {motives.length > 0 && (
                <ul className="space-y-0.5 text-xs text-muted-foreground">
                    {motives.map((motive) => (
                        <li key={motive}>{t(motive)}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function SortableHead({
    column,
    sort,
}: {
    column: Column;
    sort?: AdminMovieTableSort;
}) {
    const { t } = useTranslations();
    const label = t(column.label);

    if (sort === undefined || column.sortKey === undefined) {
        return <>{label}</>;
    }

    const isActive = sort.active === column.sortKey;
    const Icon = sort.direction === 'asc' ? ArrowUpIcon : ArrowDownIcon;

    return (
        <Link
            href={sort.href(column.sortKey)}
            preserveScroll
            aria-label={t('admin.a11y.sort_by', { column: label })}
            className="inline-flex items-center gap-1 rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none"
        >
            {label}
            {isActive && <Icon aria-hidden className="size-3" />}
        </Link>
    );
}

function ariaSort(
    column: Column,
    sort?: AdminMovieTableSort,
): 'ascending' | 'descending' | 'none' | undefined {
    if (sort === undefined || column.sortKey === undefined) {
        return undefined;
    }

    if (sort.active !== column.sortKey) {
        return 'none';
    }

    return sort.direction === 'asc' ? 'ascending' : 'descending';
}
