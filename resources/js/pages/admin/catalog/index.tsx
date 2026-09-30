import { Head, Link, router } from '@inertiajs/react';
import { ClapperboardIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AdminCatalogFiltersForm } from '@/components/admin/admin-catalog-filters';
import { AdminEmptyState } from '@/components/admin/admin-empty-state';
import { AdminMovieTable } from '@/components/admin/admin-movie-table';
import { AdminPageHeading } from '@/components/admin/admin-page-heading';
import { AdminPagination } from '@/components/admin/admin-pagination';
import { AdminStatTile } from '@/components/admin/admin-stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { AdminCardTitle } from '@/components/admin/admin-card-title';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { useTranslations } from '@/hooks/use-translations';
import {
    catalogQuery,
    hasActiveFilters,
    nextDirection,
} from '@/lib/admin-catalog-query';
import { EXCEPTION_MOTIVE_KEYS } from '@/lib/admin-enum-keys';
import { formatInteger } from '@/lib/admin-format';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as catalogIndex } from '@/routes/admin/catalog';
import type {
    AdminCatalogFacets,
    AdminCatalogFilters,
    AdminCatalogOptions,
    AdminMovieRow,
    Paginated,
} from '@/types/admin';
import type { BreadcrumbItem } from '@/types/navigation';

type Props = {
    movies: Paginated<AdminMovieRow>;
    filters: AdminCatalogFilters;
    facets: AdminCatalogFacets;
    options: AdminCatalogOptions;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'admin.nav.dashboard', href: adminDashboard() },
    { title: 'admin.nav.catalog', href: catalogIndex() },
];

/**
 * La liste du catalogue — **lecture seule**, entièrement pilotée par l'URL.
 *
 * Publier, dépublier, corriger : chacun de ces gestes a son propre seuil, et
 * tous sont tranchés par la spec 20. Aucun bouton d'écriture n'est offert ici,
 * et l'écran le dit plutôt que de le laisser deviner.
 *
 * Le COMPTAGE PAR MOTIF d'exception est affiché au-dessus du tableau parce que
 * la décision 11 interdit nommément que le marquage soit silencieux. Sa
 * sémantique est écrite à l'écran : les quatre nombres portent sur le jeu
 * filtré COURANT privé de la seule facette « entrés par exception » — sans
 * quoi, en filtrant sur « motif langue », on lirait trois zéros par
 * construction, c'est-à-dire une facette qui ne sert plus à rien.
 */
export default function AdminCatalogIndex({
    movies,
    filters,
    facets,
    options,
}: Props) {
    const { t, locale } = useTranslations();
    const filtered = hasActiveFilters(filters);

    // Filtrer, trier et paginer sont trois allers-retours serveur : sans
    // signal, la barre de filtres réagit et le tableau ne bouge pas, ce qui se
    // lit comme un filtre sans effet.
    //
    // L'attente s'exprime par un ATTRIBUT, jamais par un démontage. Retirer le
    // tableau et la pagination du DOM détruit le focus : un curateur qui
    // active « Suivant » au clavier voit disparaître l'élément focalisé, le
    // focus retombe sur `<body>`, et il doit re-tabuler depuis le lien
    // d'évitement — six champs de filtre et onze en-têtes triables plus loin.
    // Maintenus montés, React réconcilie les mêmes nœuds et le focus survit.
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        // `router.on` est GLOBAL : sans ce filtre, ouvrir une fiche film ou
        // une entrée de la barre latérale ferait clignoter l'attente de cet
        // écran-ci avant même de le quitter.
        const here = catalogIndex().url;
        const concerns = (visited: URL): boolean => visited.pathname === here;

        const stopStart = router.on('start', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(true);
            }
        });

        const stopFinish = router.on('finish', (event) => {
            if (concerns(event.detail.visit.url)) {
                setBusy(false);
            }
        });

        // Les deux écouteurs sont retirés au démontage : `strictMode` monte
        // deux fois, et un abonnement oublié se cumulerait à chaque visite.
        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    return (
        <>
            <Head title={t('admin.catalog.title')} />

            <div className="flex w-full flex-col gap-6 p-4 md:p-6">
                <AdminPageHeading
                    title={t('admin.catalog.heading')}
                    description={t('admin.catalog.description')}
                    actions={
                        <Badge variant="outline">
                            {t('admin.common.read_only')}
                        </Badge>
                    }
                />

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.catalog.filters.heading')}
                        </AdminCardTitle>
                    </CardHeader>
                    <CardContent>
                        <AdminCatalogFiltersForm
                            filters={filters}
                            options={options}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.catalog.facets.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.catalog.facets.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <AdminStatTile
                                label={t('admin.catalog.facets.total')}
                                value={facets.exception_total}
                            />
                            <AdminStatTile
                                label={t('admin.catalog.facets.language')}
                                value={facets.exception_language}
                                hint={t(EXCEPTION_MOTIVE_KEYS.language)}
                            />
                            <AdminStatTile
                                label={t('admin.catalog.facets.vote_count')}
                                value={facets.exception_vote_count}
                                hint={t(EXCEPTION_MOTIVE_KEYS.vote_count)}
                            />
                            <AdminStatTile
                                label={t('admin.catalog.facets.release_year')}
                                value={facets.exception_release_year}
                                hint={t(EXCEPTION_MOTIVE_KEYS.release_year)}
                            />
                        </div>

                        <p className="max-w-prose text-xs text-muted-foreground">
                            {t('admin.catalog.facets.scope_notice')}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <AdminCardTitle>
                            {t('admin.catalog.heading')}
                        </AdminCardTitle>
                        <CardDescription>
                            {t('admin.catalog.results', {
                                total: formatInteger(movies.meta.total, locale),
                            })}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <span
                            role="status"
                            aria-live="polite"
                            className="sr-only"
                        >
                            {busy ? t('admin.common.loading') : ''}
                        </span>

                        {movies.data.length === 0 && (
                            <AdminEmptyState
                                icon={ClapperboardIcon}
                                title={t('admin.catalog.empty.heading')}
                                description={
                                    filtered
                                        ? t('admin.catalog.empty.filtered')
                                        : t('admin.catalog.empty.no_movies')
                                }
                                action={
                                    filtered ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={catalogIndex()}>
                                                {t(
                                                    'admin.catalog.filters.reset',
                                                )}
                                            </Link>
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}

                        {movies.data.length > 0 && (
                            <div
                                aria-busy={busy}
                                className={
                                    busy
                                        ? 'space-y-4 opacity-60 transition-opacity'
                                        : 'space-y-4'
                                }
                            >
                                <AdminMovieTable
                                    movies={movies.data}
                                    sort={{
                                        active: filters.sort,
                                        direction: filters.direction,
                                        href: (column) =>
                                            catalogIndex({
                                                query: catalogQuery(filters, {
                                                    sort: column,
                                                    direction: nextDirection(
                                                        filters,
                                                        column,
                                                    ),
                                                }),
                                            }),
                                    }}
                                />

                                <AdminPagination
                                    meta={movies.meta}
                                    href={(page) =>
                                        catalogIndex({
                                            query: catalogQuery(filters, {
                                                page,
                                            }),
                                        })
                                    }
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

AdminCatalogIndex.layout = { breadcrumbs };
