import { useLayoutEffect } from 'react';
import type { ResolvedAppearance } from '@/hooks/use-appearance';
import {
    applyResolvedAppearance,
    FORCED_APPEARANCE_ATTRIBUTE,
    resolveStoredAppearance,
} from '@/hooks/use-appearance';

/**
 * Forçage d'apparence sur un sous-arbre — moitié CLIENTE.
 *
 * **Un seul forçage est prévu : l'écran de jeu, en sombre** (spec 90 § 2.2).
 * Le back-office ne force plus rien depuis D8 du 23/09 : il suit l'apparence
 * choisie par le visiteur, et seuls ses cadres de revue passent en sombre, par
 * une portée LOCALE (classe `dark` sur un sous-arbre), jamais par ce hook. Le
 * forçage passe par les **tokens de thème** — ce hook ne fait que poser (ou
 * retirer) la classe `dark` sur `<html>`, il n'écrit pas une seule couleur.
 *
 * Le forçage se fait en trois moitiés. La moitié serveur — un middleware de
 * route, posé sur le groupe qui rend les pages `game/*` — partagera
 * `appearance = 'dark'` ET le drapeau `appearanceForced`, dont Blade tire
 * l'attribut `data-appearance-forced` sur `<html>`. C'est cet attribut, et non
 * ce hook, qui supprime le clignotement au premier chargement :
 * `initializeTheme()` le lit et n'applique pas la préférence stockée
 * par-dessus.
 *
 * Ce hook reste nécessaire pour la seconde entrée, celle que Blade ne voit
 * jamais : une navigation Inertia depuis une page hors jeu ne recharge pas le
 * document. Il devient alors le propriétaire de l'attribut — il le pose au
 * montage et le retire au dernier démontage, de sorte que le forçage et sa
 * marque ne se désynchronisent jamais.
 *
 * `useLayoutEffect` et non `useEffect` : le forçage doit être appliqué **avant
 * la peinture**, sinon une image claire s'affiche le temps d'une frame. Aucun
 * SSR en v1 (`config/inertia.php`, spec 90 § 2.2), donc aucun avertissement
 * de rendu serveur à craindre.
 *
 * Le compteur de montages vit au niveau MODULE : `strictMode` monte deux fois
 * en développement (monte → démonte → remonte), et une restauration naïve au
 * démontage rendrait la main à l'apparence stockée entre les deux. Le compteur
 * fait que la restauration n'a lieu qu'au dernier démontage réel — celui qui
 * quitte l'écran de jeu pour une page hors jeu. Même piège, même parade que
 * `use-appearance.tsx` avec `useSyncExternalStore`.
 *
 * La restauration **recalcule** la préférence au lieu de la mémoriser : le
 * thème du système peut basculer pendant une partie, et rendre la main à une
 * valeur capturée au montage afficherait le reste du site dans un thème que le
 * visiteur n'a plus.
 */

let forcedMountCount = 0;

export function useForcedAppearance(forced: ResolvedAppearance): void {
    useLayoutEffect(() => {
        const root = document.documentElement;

        forcedMountCount += 1;
        root.dataset[FORCED_APPEARANCE_ATTRIBUTE] = forced;
        applyResolvedAppearance(forced);

        // `initializeTheme()` a posé un écouteur qui réapplique la préférence
        // stockée quand le thème du système bascule. Il respecte désormais
        // l'attribut ; celui-ci reste par sécurité, pour que le forçage tienne
        // même si le boot n'a jamais eu lieu (montage de test, hydratation
        // partielle).
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const reapply = (): void => applyResolvedAppearance(forced);

        media.addEventListener('change', reapply);

        return () => {
            media.removeEventListener('change', reapply);

            forcedMountCount -= 1;

            if (forcedMountCount === 0) {
                delete root.dataset[FORCED_APPEARANCE_ATTRIBUTE];
                applyResolvedAppearance(resolveStoredAppearance());
            }
        };
    }, [forced]);
}
