import { describe, expect, it } from 'vite-plus/test';
import { gridNavigationTarget } from '@/lib/admin/grid-navigation';

/*
 * Grille des visuels de l'éditeur de la banque (spec 20 § 6.4) : un seul
 * arrêt de tabulation, les flèches pour se déplacer. Fonction pure, sans DOM
 * (C18 § 2.4).
 */
describe('gridNavigationTarget', () => {
    it('les flèches parcourent la grille des visuels sans en sortir', () => {
        // Sept vignettes sur trois colonnes : 0 1 2 / 3 4 5 / 6.
        expect(gridNavigationTarget('ArrowRight', 0, 7, 3)).toBe(1);
        expect(gridNavigationTarget('ArrowRight', 6, 7, 3)).toBe(6);
        expect(gridNavigationTarget('ArrowLeft', 0, 7, 3)).toBe(0);
        expect(gridNavigationTarget('ArrowLeft', 4, 7, 3)).toBe(3);
        expect(gridNavigationTarget('ArrowDown', 1, 7, 3)).toBe(4);
        expect(gridNavigationTarget('ArrowDown', 4, 7, 3)).toBe(4);
        expect(gridNavigationTarget('ArrowDown', 3, 7, 3)).toBe(6);
        expect(gridNavigationTarget('ArrowUp', 6, 7, 3)).toBe(3);
        expect(gridNavigationTarget('ArrowUp', 2, 7, 3)).toBe(2);
        expect(gridNavigationTarget('Home', 5, 7, 3)).toBe(0);
        expect(gridNavigationTarget('End', 1, 7, 3)).toBe(6);
    });

    it('laisse passer toute touche qui ne déplace pas', () => {
        expect(gridNavigationTarget('Enter', 2, 7, 3)).toBeNull();
        expect(gridNavigationTarget(' ', 2, 7, 3)).toBeNull();
        expect(gridNavigationTarget('Tab', 2, 7, 3)).toBeNull();
        expect(gridNavigationTarget('ArrowRight', 0, 0, 3)).toBeNull();
    });

    it('tient une colonne nulle ou un index hors grille pour le plus proche', () => {
        expect(gridNavigationTarget('ArrowDown', 0, 3, 0)).toBe(1);
        expect(gridNavigationTarget('ArrowRight', 9, 3, 2)).toBe(2);
        expect(gridNavigationTarget('ArrowLeft', -4, 3, 2)).toBe(0);
    });
});
