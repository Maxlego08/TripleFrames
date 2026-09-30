import { RotateCw, TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type ErrorStateProps = {
    /** Texte DÉJÀ traduit : ce qui n'a pas pu se faire, dit en clair. */
    title: string;
    /** Texte DÉJÀ traduit : le motif, jamais un message d'erreur brut. */
    description?: string;
    className?: string;
} & (
    | {
          /** Rejoue ce qui a échoué. */
          onRetry: () => void;
          /**
           * Libellé DÉJÀ traduit du bouton, obligatoire avec `onRetry` : un
           * bouton sans nom n'est pas atteignable au lecteur d'écran.
           */
          retryLabel: string;
      }
    | { onRetry?: undefined; retryLabel?: undefined }
);

/**
 * Erreur rejouable d'un bouton (spec 90 § 7.6).
 *
 * Reçoit ses chaînes déjà traduites et n'appelle jamais `t()` (règle 5). Le
 * bouton « Réessayer » n'est rendu qu'avec son action ET son libellé, ce que
 * le type impose : l'un sans l'autre ne compile pas.
 *
 * **Il ne parle pas** : `Alert` porte ici le rôle `note`, qui supplante le
 * `role="alert"` du composant généré. Sur une page de jeu, `GameAnnouncer`
 * est la seule région vivante (C16 § 4) ; l'écran qui doit annoncer l'échec
 * passe par `announce()`. L'état n'est jamais porté par la seule couleur :
 * icône, titre et texte. Cible du bouton à 44 px (spec 90 § 8).
 */
export function ErrorState({
    title,
    description,
    onRetry,
    retryLabel,
    className,
}: ErrorStateProps) {
    return (
        <Alert role="note" variant="destructive" className={className}>
            <TriangleAlert aria-hidden="true" />
            <AlertTitle className="line-clamp-none">{title}</AlertTitle>
            <AlertDescription>
                {description !== undefined && <p>{description}</p>}
                {onRetry !== undefined && (
                    <Button
                        type="button"
                        variant="outline"
                        className="mt-2 min-h-11"
                        onClick={onRetry}
                    >
                        <RotateCw aria-hidden="true" />
                        {retryLabel}
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
}
