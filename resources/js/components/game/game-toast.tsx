import { CircleAlert, X } from 'lucide-react';
import { useEffect } from 'react';
import { useTranslations } from '@/hooks/use-translations';

/** Un toast du jeu : son texte déjà traduit, et une clé qui le distingue d'un même texte répété. */
export type GameToastMessage = {
    id: number;
    message: string;
};

/** Durée d'affichage d'un toast : un confort d'interface, pas une valeur de jeu. */
const TOAST_DURATION_MS = 6000;

type GameToastProps = {
    toast: GameToastMessage | null;
    onDismiss: () => void;
};

/**
 * Le toast d'erreur des pages de jeu — **visuel seulement**. Une page de jeu
 * n'a qu'une région `aria-live`, son annonceur (90 § 7.4, `ShellTest`) :
 * sonner n'y est pas monté, et ce toast n'ouvre aucune région ; l'appelant
 * annonce le même texte par `announce()`. Se ferme seul, ou par sa croix.
 *
 * Présentation : la pastille `toast` de la maquette `game.html`, en haut
 * de l'écran (`game-toast`, `game.scss`). Composant de présentation : props
 * seulement.
 */
export function GameToast({ toast, onDismiss }: GameToastProps) {
    const { t } = useTranslations();

    useEffect(() => {
        if (toast === null) {
            return;
        }

        const timer = window.setTimeout(onDismiss, TOAST_DURATION_MS);

        return () => window.clearTimeout(timer);
    }, [toast, onDismiss]);

    if (toast === null) {
        return null;
    }

    return (
        <div className="game-toast">
            <CircleAlert aria-hidden="true" />
            <p>{toast.message}</p>
            <button
                type="button"
                className="game-toast__close"
                aria-label={t('common.action.close')}
                onClick={onDismiss}
            >
                <X aria-hidden="true" />
            </button>
        </div>
    );
}
