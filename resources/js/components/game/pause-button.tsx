import { CircleAlert, CirclePause, CirclePlay, CircleX } from 'lucide-react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import type { PauseGestureKind } from '@/lib/game/pause-gesture';

export type PauseButtonProps = {
    /** Le geste offert (`pauseControlOf()`). */
    kind: PauseGestureKind;
    /** Le geste est en vol : aucun second envoi, le focus reste en place. */
    pending: boolean;
    /** Geste impossible : onglet supplanté, connexion perdue, siège sorti. */
    disabled: boolean;
    /** Dernier échec, déjà traduit, ou nul. */
    error: string | null;
    onPress: (kind: PauseGestureKind) => void;
};

const LABELS = {
    pause: 'game.pause.pause',
    cancel: 'game.pause.cancel',
    resume: 'game.pause.resume',
} as const;

const ICONS = {
    pause: CirclePause,
    cancel: CircleX,
    resume: CirclePlay,
} as const;

/**
 * « Pause », « Annuler la pause » ou « Reprendre » (D64 du 07/10, spec 60
 * § 14 ; 90 § 10). La page le rend au siège qui a l'autorité
 * (`pauseControlOf()`) ; le serveur la relit de toute façon.
 *
 * Même patron que `NextRoundButton` : `aria-disabled` pendant l'envoi (le
 * focus reste), indicateur neutralisé, erreur liée par `aria-describedby`,
 * en texte et icône, annoncée par l'appelant. Cible d'au moins `min-h-11`,
 * tokens seulement ; ni Echo, ni horloge, ni requête.
 */
export function PauseButton({
    kind,
    pending,
    disabled,
    error,
    onPress,
}: PauseButtonProps) {
    const { t } = useTranslations();
    const errorId = useId();
    const Icon = ICONS[kind];

    return (
        <div className="flex flex-col gap-1">
            <Button
                type="button"
                variant={kind === 'resume' ? 'default' : 'outline'}
                disabled={disabled}
                aria-disabled={pending ? true : undefined}
                aria-busy={pending}
                aria-describedby={error === null ? undefined : errorId}
                onClick={() => {
                    if (!pending) {
                        onPress(kind);
                    }
                }}
                className="min-h-11 w-full aria-disabled:cursor-not-allowed aria-disabled:opacity-50 sm:w-auto sm:self-start"
            >
                {pending ? (
                    <Spinner
                        aria-hidden="true"
                        role="presentation"
                        aria-label={undefined}
                        className="motion-reduce:animate-none"
                    />
                ) : (
                    <Icon aria-hidden="true" />
                )}
                {t(LABELS[kind])}
            </Button>

            {error !== null && (
                <p
                    id={errorId}
                    className="flex items-center gap-1 text-sm text-destructive"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-4 shrink-0"
                    />
                    {error}
                </p>
            )}
        </div>
    );
}
