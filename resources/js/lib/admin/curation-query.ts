import type { AdminCurationFilters } from '@/types/admin';
import type { QueryParams } from '@/wayfinder';

/**
 * La query string de la file de curation (spec 20 § 4.1), recomposée
 * **explicitement** depuis les filtres que le serveur a retenus — jamais depuis
 * `window.location`, qui rendrait un lien différent selon l'endroit d'où il
 * est rendu. Une valeur refusée par la liste blanche revient `null` et
 * disparaît donc des liens.
 *
 * `current` : le film que « film suivant » saute. `page` : la page de la file.
 */

/** Les trois filtres de la file, par nom de paramètre. */
export const CURATION_FILTER_PARAMETERS = [
    'entry',
    'motive',
    'content_flag',
] as const satisfies readonly (keyof AdminCurationFilters)[];

export type CurationQueryOverrides = {
    current?: number;
    page?: number;
};

export function curationQuery(
    filters: AdminCurationFilters,
    overrides: CurationQueryOverrides = {},
): QueryParams {
    const query: QueryParams = {};

    for (const parameter of CURATION_FILTER_PARAMETERS) {
        const value = filters[parameter];

        if (value !== null) {
            query[parameter] = value;
        }
    }

    if (overrides.current !== undefined) {
        query.current = overrides.current;
    }

    if (overrides.page !== undefined) {
        query.page = overrides.page;
    }

    return query;
}

/** Un filtre est-il posé ? Distingue « file vide » de « filtre vide ». */
export function hasCurationFilters(filters: AdminCurationFilters): boolean {
    return CURATION_FILTER_PARAMETERS.some(
        (parameter) => filters[parameter] !== null,
    );
}

/**
 * Les filtres de la file que porte une URL de l'éditeur — « film suivant »
 * les y dépose —, pour que le film d'après reste dans la même strate du lot
 * pilote. Fonction pure : l'appelant passe l'URL de la page Inertia. Le
 * serveur revalide chaque valeur ; une valeur inconnue y est refusée, jamais
 * appliquée.
 */
export function curationFiltersFromUrl(url: string): QueryParams {
    const questionMark = url.indexOf('?');
    const search = new URLSearchParams(
        questionMark === -1 ? '' : url.slice(questionMark + 1),
    );
    const query: QueryParams = {};

    for (const parameter of CURATION_FILTER_PARAMETERS) {
        const value = search.get(parameter);

        if (value !== null && value !== '') {
            query[parameter] = value;
        }
    }

    return query;
}
