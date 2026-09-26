import { useLayoutEffect } from 'react';
import type { StyleTarget } from '@/hooks/game/use-visual-viewport';

/** Propriété posée sur `<html>` et `<body>` pendant le verrou. */
export const OVERSCROLL_PROPERTY = 'overscroll-behavior-y';

/** Valeur du verrou : ni rebond, ni geste de rafraîchissement. */
export const OVERSCROLL_LOCKED = 'none';

/** Nombre de verrous en cours, au niveau MODULE (double montage). */
let locks = 0;

/** Cibles du verrou en cours ; vide quand personne ne le tient. */
let locked: readonly StyleTarget[] = [];

/**
 * Pose le verrou pour un montage ; rend sa levée, sans effet au-delà du
 * premier appel. La propriété n'est posée qu'au premier verrou et retirée au
 * dernier : `strictMode` monte deux fois en développement, et une levée naïve
 * au premier démontage rendrait le geste de rafraîchissement au milieu d'une
 * manche. Rien d'autre n'écrit cette propriété en ligne : la retirer rend la
 * main à la feuille de style.
 */
export function lockOverscroll(targets: readonly StyleTarget[]): () => void {
    locks += 1;

    if (locks === 1) {
        locked = targets;

        for (const target of locked) {
            target.style.setProperty(OVERSCROLL_PROPERTY, OVERSCROLL_LOCKED);
        }
    }

    let released = false;

    return () => {
        if (released) {
            return;
        }

        released = true;
        locks -= 1;

        if (locks === 0) {
            for (const target of locked) {
                target.style.removeProperty(OVERSCROLL_PROPERTY);
            }

            locked = [];
        }
    };
}

/**
 * Désactive le geste « tirer pour rafraîchir » et le rebond de défilement
 * pendant que l'écran de jeu est monté (spec 90 § 2.3, contrat C16 § 2.3,
 * principe 5) : un geste vertical sur l'image ou la liste des joueurs ne
 * recharge jamais la page en pleine manche — ce qui démonterait la
 * souscription et ferait courir le délai de grâce de déconnexion.
 *
 * `overscroll-behavior-y: none` sur `<html>` ET `<body>` : selon le
 * navigateur, c'est l'un ou l'autre qui porte le défilement du document. Même
 * patron de compteur de module que `useForcedAppearance`.
 */
export function useOverscrollLock(): void {
    useLayoutEffect(
        () => lockOverscroll([document.documentElement, document.body]),
        [],
    );
}
