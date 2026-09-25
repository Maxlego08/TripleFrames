import { Lock } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';

type ReadOnlyNoticeProps = {
    /**
     * Texte DÉJÀ traduit : pourquoi cet écran est en lecture seule — second
     * onglet (`game.seat.superseded`), réglages verrouillés d'un non-hôte.
     */
    message: string;
    className?: string;
};

/**
 * Avis de lecture seule (spec 90 § 7.6).
 *
 * Reçoit son message déjà traduit et n'appelle jamais `t()` (règle 5). Ce
 * n'est pas une région vivante : `Alert` au rôle `note`, qui supplante le
 * `role="alert"` généré — un avis inséré au rendu n'a rien à interrompre, et
 * sur une page de jeu seule `GameAnnouncer` parle (C16 § 4). L'avis dit
 * l'état par une icône et un texte, jamais par la seule couleur.
 */
export function ReadOnlyNotice({ message, className }: ReadOnlyNoticeProps) {
    return (
        <Alert role="note" className={className}>
            <Lock aria-hidden="true" />
            <AlertDescription className="text-foreground">
                {message}
            </AlertDescription>
        </Alert>
    );
}
