import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

type LoadingStateProps = {
    /** Texte DÉJÀ traduit, toujours visible : ce qui est en train de charger. */
    label: string;
    className?: string;
};

/**
 * État de chargement réutilisable (spec 90 § 7.6) : un `Spinner` neutralisé
 * et un libellé visible.
 *
 * Comme les trois autres composants d'état, il reçoit une chaîne déjà
 * traduite et n'appelle jamais `t()` : l'écran possède sa copie, le composant
 * sa forme, et un re-skin ne touche que lui (règle 5).
 *
 * **Il ne parle pas.** Le `Spinner` généré porte `role="status"` et un nom
 * « Loading » en dur, en anglais : il est masqué aux lecteurs d'écran, et le
 * libellé visible dit la même chose dans la langue du joueur. Aucune région
 * vivante non plus — sur une page de jeu, `GameAnnouncer` est la seule qui
 * parle (C16 § 4) ; l'écran qui veut annoncer un chargement passe par
 * `announce()`. `aria-busy` marque le bloc comme en cours de remplissage. Le
 * tourniquet s'arrête quand le visiteur réduit les animations.
 */
export function LoadingState({ label, className }: LoadingStateProps) {
    return (
        <div
            aria-busy="true"
            className={cn(
                'flex w-full items-center justify-center gap-2 px-4 py-6 text-sm text-muted-foreground',
                className,
            )}
        >
            <Spinner
                aria-hidden="true"
                role="presentation"
                aria-label={undefined}
                className="motion-reduce:animate-none"
            />
            <span>{label}</span>
        </div>
    );
}
