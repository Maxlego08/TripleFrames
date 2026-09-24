import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Portée sombre LOCALE (spec 90 § 2.2, contrat C16 § 2.2 ; D8 du 23/09).
 *
 * La classe `dark` redéfinit les tokens de `.dark` (`resources/css/app.css`)
 * pour ce seul sous-arbre, sans toucher `<html>` ; les utilitaires lisent les
 * tokens sources (`@theme inline`) et suivent donc la portée. Aucune couleur
 * n'est écrite ici : un re-skin ne touche que le thème.
 *
 * Usage **réservé** aux cadres de revue et de prévisualisation du back-office
 * (20, C9-bis) : un curateur voit l'image telle qu'elle sera servie en jeu,
 * quelle que soit l'apparence qu'il a choisie. Les pages de jeu, elles, sont
 * forcées en sombre à la racine, jamais par cette portée.
 *
 * **Aucun portail** (`Dialog`, `Sheet`, `DropdownMenu`) ne s'ouvre depuis ce
 * sous-arbre : un portail se rend hors de lui et perdrait la portée.
 */
export function GameThemeScope({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('dark bg-background text-foreground', className)}>
            {children}
        </div>
    );
}
