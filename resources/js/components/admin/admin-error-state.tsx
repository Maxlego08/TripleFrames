import { TriangleAlert } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Props = {
    /** Texte DÉJÀ traduit. Ce que le curateur n'a pas obtenu, dit en clair. */
    title: string;
    /** Le motif, et si possible la sortie. Jamais un message d'erreur brut. */
    description?: string;
    /**
     * Libellé du bouton de reprise, DÉJÀ traduit. Obligatoire dès que
     * `onRetry` est fourni : un bouton sans nom n'est pas rejouable au clavier.
     */
    retryLabel?: string;
    onRetry?: () => void;
    /** Action de remplacement quand la reprise n'est pas un simple bouton (un lien, par exemple). */
    action?: ReactNode;
    className?: string;
};

/**
 * État d'erreur réutilisable.
 *
 * Décision 9 : **aucun message d'erreur brut**, chaque état d'échec traduit et
 * rejouable d'un bouton. D'où `onRetry` : un balayage qui a échoué, un
 * rafraîchissement partiel qui n'est pas revenu, un worker absent — le
 * curateur doit pouvoir réessayer sans savoir ce qu'est une file.
 *
 * Comme les deux autres états, il n'écrit aucun texte : l'écran possède sa
 * copie, le composant possède la forme. `<Alert variant="destructive">` porte
 * déjà `role="alert"`.
 */
export function AdminErrorState({
    title,
    description,
    retryLabel,
    onRetry,
    action,
    className,
}: Props) {
    return (
        <Alert variant="destructive" className={cn('w-full', className)}>
            <TriangleAlert aria-hidden />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription>
                {description && <p>{description}</p>}
                {onRetry && retryLabel && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onRetry}
                        className="mt-2"
                    >
                        {retryLabel}
                    </Button>
                )}
                {action}
            </AlertDescription>
        </Alert>
    );
}
