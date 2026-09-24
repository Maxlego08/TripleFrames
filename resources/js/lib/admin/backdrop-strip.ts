/**
 * La bande balayable de l'éditeur de la banque (spec 20 § 6.2 et § 6.3, lot
 * L20-11) : la rangée, sous le recadreur, des visuels TMDB du film **non
 * encore utilisés**, dans l'ordre de la grille.
 *
 * Tout choix de la bande passe par ici — quel visuel est dans la bande, quel
 * est le voisin du visuel ouvert, lequel s'ouvre après un envoi, et si un
 * glissement vaut un pas. Aucune fonction ne lit le DOM : la liste et les
 * distances arrivent en paramètre, pour que Vitest les prouve sans
 * environnement DOM (C18 § 2.4) et que le composant ne fasse que brancher ces
 * fonctions sur ses événements.
 *
 * La liste reçue est TOUJOURS la liste de la grille (`AdminBackdropSet.items`,
 * ordre fixé par le serveur : sans texte d'abord, ordre TMDB ensuite). Le
 * visuel ouvert peut ne pas être dans la bande — un visuel déjà utilisé,
 * rouvert depuis la grille pour une autre variante : sa place dans la grille
 * suffit à situer ses voisins.
 */

import type { AdminBackdrop } from '@/types/admin';

/** Le sens d'un pas dans la bande : `[` et `]`, ses boutons, un glissement. */
export type StripDirection = 'previous' | 'next';

/**
 * Distance horizontale minimale, en pixels CSS de l'écran, pour qu'un
 * glissement sur la bande vaille un pas. En deçà, le geste reste un clic sur
 * une vignette ; au-delà, il passe au visuel voisin.
 */
export const STRIP_SWIPE_MIN_DISTANCE = 48;

/** La place d'un visuel dans la bande, à partir de 1, et la taille de la bande. */
export type StripPosition = {
    index: number;
    count: number;
};

/**
 * Un visuel est dans la bande s'il n'a encore servi à aucune image
 * (`used_levels` vide) et s'il peut s'ouvrir dans le cadre : un visuel trop
 * étroit ou en portrait, proposé désactivé par la grille avec son motif, ne
 * s'ouvre jamais dans le cadre, et la bande ne s'y arrête donc pas.
 */
export function isInStrip(backdrop: AdminBackdrop): boolean {
    return backdrop.used_levels.length === 0 && backdrop.refusal === null;
}

/** Les visuels de la bande, dans l'ordre de la grille. */
export function stripItems(items: readonly AdminBackdrop[]): AdminBackdrop[] {
    return items.filter(isInStrip);
}

/** Place du visuel `path` dans la grille, `-1` s'il n'y est pas. */
function gridPosition(
    items: readonly AdminBackdrop[],
    path: string | null,
): number {
    return path === null
        ? -1
        : items.findIndex((backdrop) => backdrop.file_path === path);
}

/**
 * Le visuel voisin du visuel ouvert dans la bande : le premier visuel de la
 * bande après lui (`next`) ou avant lui (`previous`) dans l'ordre de la
 * grille. `[` et `]`, les boutons « précédent » / « suivant » et un
 * glissement passent par ici.
 *
 * - Sans boucle : au bord de la bande, rend `null` et le cadre ne change pas,
 *   comme les flèches de la grille (`gridNavigationTarget`).
 * - Aucun visuel ouvert, ou un visuel sorti de la grille : `next` ouvre le
 *   premier visuel de la bande, `previous` ne mène nulle part.
 */
export function stripNeighbour(
    items: readonly AdminBackdrop[],
    openedPath: string | null,
    direction: StripDirection,
): AdminBackdrop | null {
    const position = gridPosition(items, openedPath);

    if (position === -1) {
        return direction === 'next' ? (items.find(isInStrip) ?? null) : null;
    }

    return direction === 'next'
        ? (items.slice(position + 1).find(isInStrip) ?? null)
        : (items.slice(0, position).findLast(isInStrip) ?? null);
}

/**
 * Le visuel qui s'ouvre dans le cadre après l'envoi du visuel `sentPath`
 * (§ 6.3) : le premier visuel de la bande qui le suit dans la grille ; à
 * défaut, le premier de la bande depuis le début — le curateur reprend les
 * visuels qu'il avait sautés ; `null` quand la bande est épuisée.
 *
 * Le visuel envoyé n'est jamais proposé, que la liste soit celle d'avant
 * l'envoi (il y paraît encore inutilisé) ou celle d'après (il y porte déjà
 * son niveau) : l'image qu'il vient de donner est en traitement.
 */
export function stripAfterSend(
    items: readonly AdminBackdrop[],
    sentPath: string,
): AdminBackdrop | null {
    const position = gridPosition(items, sentPath);
    const candidate = (backdrop: AdminBackdrop): boolean =>
        backdrop.file_path !== sentPath && isInStrip(backdrop);

    if (position === -1) {
        return items.find(candidate) ?? null;
    }

    return (
        items.slice(position + 1).find(candidate) ??
        items.slice(0, position).find(candidate) ??
        null
    );
}

/**
 * La place du visuel ouvert dans la bande, ou `null` s'il n'y est pas (aucun
 * visuel ouvert, ou un visuel déjà utilisé rouvert depuis la grille).
 */
export function stripPosition(
    items: readonly AdminBackdrop[],
    openedPath: string | null,
): StripPosition | null {
    const strip = stripItems(items);
    const index = strip.findIndex(
        (backdrop) => backdrop.file_path === openedPath,
    );

    return index === -1 ? null : { index: index + 1, count: strip.length };
}

/**
 * Un glissement sur la bande, de `(dx, dy)` pixels entre le point posé et le
 * point levé, traduit en pas : vers la gauche, le visuel suivant ; vers la
 * droite, le précédent — la bande suit le doigt, comme un carrousel. Rend
 * `null` pour un geste trop court ou plus vertical qu'horizontal : un clic
 * sur une vignette, ou le défilement de la page.
 */
export function swipeDirection(
    dx: number,
    dy: number,
    minDistance: number = STRIP_SWIPE_MIN_DISTANCE,
): StripDirection | null {
    if (Math.abs(dx) < minDistance || Math.abs(dx) <= Math.abs(dy)) {
        return null;
    }

    return dx < 0 ? 'next' : 'previous';
}
