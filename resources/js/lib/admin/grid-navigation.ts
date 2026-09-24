/**
 * Navigation au clavier dans une grille à tabulation itinérante (spec 20
 * § 6.4) : la grille des visuels TMDB de l'éditeur n'est qu'UN arrêt de
 * tabulation, et les flèches passent d'une vignette à l'autre.
 *
 * Fonction pure, sans DOM : la touche, la vignette courante, le nombre de
 * vignettes et le nombre de colonnes affichées arrivent en paramètre — le
 * composant lit les colonnes sur la grille rendue, qui en change selon la
 * largeur. Testée par Vitest sans environnement DOM (C18 § 2.4).
 *
 * - `←` / `→` : vignette précédente ou suivante, sans boucler ;
 * - `↑` / `↓` : même colonne, rangée précédente ou suivante ; au bord, la
 *   vignette reste la même ;
 * - Origine / Fin : première ou dernière vignette.
 *
 * Rend `null` pour une touche qui n'est pas un déplacement : elle suit son
 * cours (Tab, Entrée, Espace…). Rend l'index courant quand la touche est un
 * déplacement bloqué au bord : elle est consommée, la page ne défile pas.
 */
export function gridNavigationTarget(
    key: string,
    index: number,
    count: number,
    columns: number,
): number | null {
    if (count < 1) {
        return null;
    }

    const last = count - 1;
    const current = Math.min(Math.max(index, 0), last);
    const step = Math.max(1, Math.trunc(columns));

    switch (key) {
        case 'ArrowRight':
            return Math.min(current + 1, last);
        case 'ArrowLeft':
            return Math.max(current - 1, 0);
        case 'ArrowDown':
            return current + step <= last ? current + step : current;
        case 'ArrowUp':
            return current - step >= 0 ? current - step : current;
        case 'Home':
            return 0;
        case 'End':
            return last;
        default:
            return null;
    }
}
