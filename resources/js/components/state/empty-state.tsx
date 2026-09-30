import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type EmptyStateProps = {
    /** Texte DÉJÀ traduit : ce qui manque, dit en clair. */
    title: string;
    /** Texte DÉJÀ traduit : pourquoi, ou comment en sortir. */
    description?: string;
    /** Sortie utile, rendue telle quelle : un bouton, un lien. */
    action?: ReactNode;
    className?: string;
};

/**
 * État vide réutilisable (spec 90 § 7.6).
 *
 * **Aucun texte n'est écrit ici** : un état vide ment s'il dit la même chose
 * pour « personne n'a encore rejoint » et pour « aucun film ne correspond » ;
 * la nuance appartient à l'écran, qui passe sa copie déjà traduite. Le
 * composant n'appelle jamais `t()` et ne possède que la forme (règle 5).
 *
 * Pas de région vivante : sur une page de jeu, seule `GameAnnouncer` parle
 * (C16 § 4). Le titre est un paragraphe et non un titre de section : le
 * composant ne connaît pas la hiérarchie de la page qui le monte.
 */
export function EmptyState({
    title,
    description,
    action,
    className,
}: EmptyStateProps) {
    return (
        <div
            className={cn(
                'flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border px-4 py-8 text-center',
                className,
            )}
        >
            <p className="text-sm font-medium text-foreground">{title}</p>
            {description !== undefined && (
                <p className="max-w-prose text-sm text-balance text-muted-foreground">
                    {description}
                </p>
            )}
            {action}
        </div>
    );
}
