import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** Texte DÉJÀ traduit : l'écran appelant possède sa copie, ce composant possède la forme. */
    title: string;
    description?: string;
    icon?: LucideIcon;
    /** Sortie de secours : « réinitialiser les filtres », « lancer un balayage »… */
    action?: ReactNode;
    className?: string;
};

/**
 * État vide réutilisable — l'un des trois états que la définition de « terminé »
 * du projet exige de chaque écran.
 *
 * **Aucun texte n'est écrit ici, et c'est délibéré.** Un état vide ment s'il
 * dit la même chose pour « aucun film au catalogue » et pour « aucun film ne
 * correspond à ces filtres » : la nuance appartient à l'écran, qui passe sa
 * copie déjà traduite. Le composant ne possède que la forme, ce qui le rend
 * remplaçable sans toucher à une seule page (règle 5).
 *
 * `role="status"` : après un changement de filtre, un lecteur d'écran doit
 * apprendre que le tableau s'est vidé sans avoir à le parcourir.
 */
export function AdminEmptyState({
    title,
    description,
    icon: Icon,
    action,
    className,
}: Props) {
    return (
        <div
            role="status"
            className={cn(
                'flex w-full flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border bg-card px-6 py-12 text-center',
                className,
            )}
        >
            {Icon && (
                <Icon aria-hidden className="size-6 text-muted-foreground" />
            )}
            <p className="text-sm font-medium text-card-foreground">{title}</p>
            {description && (
                <p className="max-w-prose text-sm text-balance text-muted-foreground">
                    {description}
                </p>
            )}
            {action}
        </div>
    );
}
