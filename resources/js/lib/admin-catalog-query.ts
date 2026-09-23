import type { QueryParams } from '@/wayfinder';
import type {
    AdminCatalogFilters,
    AdminCatalogSortDirection,
} from '@/types/admin';

/**
 * La query string du catalogue, recomposée **explicitement**.
 *
 * Volontairement pas `mergeQuery` de Wayfinder : celui-ci lit
 * `window.location.search`, donc rien du tout hors navigateur, et il rendrait
 * un lien de tri différent selon l'endroit d'où il est rendu. Les filtres
 * arrivent du serveur dans la prop `filters` — miroir exact de ce que le
 * `CatalogIndexRequest` a retenu — et c'est cette copie-là, et elle seule, qui
 * fabrique tous les liens de la page.
 *
 * Conséquence utile : une valeur refusée par la liste blanche du FormRequest
 * revient `null` dans `filters` et disparaît donc des liens, au lieu de se
 * propager de page en page.
 */

export type CatalogQueryOverrides = {
    sort?: string;
    direction?: AdminCatalogSortDirection;
    /** `null` retire le paramètre — un changement de tri doit revenir page 1. */
    page?: number | null;
};

export function catalogQuery(
    filters: AdminCatalogFilters,
    overrides: CatalogQueryOverrides = {},
): QueryParams {
    const query: QueryParams = {};

    if (filters.q !== null) {
        query.q = filters.q;
    }

    if (filters.availability !== null) {
        query.availability = filters.availability;
    }

    if (filters.content_flag !== null) {
        query.content_flag = filters.content_flag;
    }

    if (filters.import_source !== null) {
        query.import_source = filters.import_source;
    }

    if (filters.exception !== null) {
        query.exception = filters.exception;
    }

    if (filters.playable_at !== null) {
        query.playable_at = filters.playable_at;
    }

    query.sort = overrides.sort ?? filters.sort;
    query.direction = overrides.direction ?? filters.direction;

    if (overrides.page !== undefined && overrides.page !== null) {
        query.page = overrides.page;
    }

    return query;
}

/**
 * Le sens à donner à une colonne cliquée : on bascule si c'est déjà la colonne
 * de tri, sinon on repart en décroissant — la lecture par défaut d'un
 * catalogue est « les plus récents d'abord ».
 */
export function nextDirection(
    filters: AdminCatalogFilters,
    column: string,
): AdminCatalogSortDirection {
    if (filters.sort !== column) {
        return 'desc';
    }

    return filters.direction === 'asc' ? 'desc' : 'asc';
}

/** Un filtre est-il posé ? Sert à distinguer « catalogue vide » de « filtre vide ». */
export function hasActiveFilters(filters: AdminCatalogFilters): boolean {
    return (
        filters.q !== null ||
        filters.availability !== null ||
        filters.content_flag !== null ||
        filters.import_source !== null ||
        filters.exception !== null ||
        filters.playable_at !== null
    );
}
