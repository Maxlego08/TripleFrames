import { CircleAlert, X } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
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
 * Composant de présentation : props seulement, tokens seulement.
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
        <div className="pointer-events-none fixed inset-x-0 bottom-16 z-50 flex justify-center px-4">
            <div className="pointer-events-auto flex max-w-md items-start gap-3 rounded-md border border-destructive bg-card px-4 py-3 text-sm text-card-foreground shadow-lg">
                <CircleAlert
                    aria-hidden="true"
                    className="mt-0.5 size-4 shrink-0 text-destructive"
                />
                <p className="flex-1">{toast.message}</p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="-my-1 size-7"
                    aria-label={t('common.action.close')}
                    onClick={onDismiss}
                >
                    <X aria-hidden="true" />
                </Button>
            </div>
        </div>
    );
}
