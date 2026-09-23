import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

type Props = {
    /**
     * Texte DÉJÀ traduit, annoncé aux lecteurs d'écran. Il est **obligatoire** :
     * une grille de rectangles gris ne dit rien à qui ne la voit pas, et un
     * `aria-busy` muet laisse croire à un écran vide.
     */
    label: string;
    /** Nombre de lignes fantômes. Par défaut cinq, la hauteur d'un tableau court. */
    rows?: number;
    className?: string;
};

/**
 * État de chargement réutilisable — un squelette, jamais un tourniquet.
 *
 * Il sert aux rafraîchissements partiels (`router.reload({ only: [...] })`) de
 * l'écran d'import et de la liste du catalogue, où la structure reste en place
 * pendant que les données reviennent : le squelette en conserve le rythme, là
 * où un tourniquet centré ferait sauter la page.
 *
 * `aria-live="polite"` et non `assertive` : le chargement n'interrompt pas la
 * lecture en cours.
 */
export function AdminLoadingState({ label, rows = 5, className }: Props) {
    return (
        <div
            role="status"
            aria-live="polite"
            aria-busy="true"
            className={cn('flex w-full flex-col gap-3', className)}
        >
            <span className="sr-only">{label}</span>
            {Array.from({ length: rows }, (_, index) => (
                <div
                    key={index}
                    aria-hidden
                    className="flex w-full items-center gap-3"
                >
                    <Skeleton className="h-4 w-2/5" />
                    <Skeleton className="h-4 flex-1" />
                    <Skeleton className="h-4 w-1/6" />
                </div>
            ))}
        </div>
    );
}
