import { CircleAlert, CirclePause, CirclePlay, CircleX } from 'lucide-react';
import { useId } from 'react';
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
 * Pastille `pause-button` de la maquette `design-test/html/game.html`
 * (icône dans un rond, libellé), en haut à droite de l'écran de manche ;
 * sur l'écran de pause, la même devient le bouton « Reprendre »
 * (`pause-overlay__action`). Même patron que `NextRoundButton` :
 * `aria-disabled` pendant l'envoi (le focus reste), indicateur neutralisé,
 * erreur liée par `aria-describedby`, en texte et icône, annoncée par
 * l'appelant. Ni Echo, ni horloge, ni requête ; présentation dans
 * `game.scss`.
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
        <div className="pause-control">
            <button
                type="button"
                className="pause-button"
                disabled={disabled}
                aria-disabled={pending ? true : undefined}
                aria-busy={pending}
                aria-describedby={error === null ? undefined : errorId}
                onClick={() => {
                    if (!pending) {
                        onPress(kind);
                    }
                }}
            >
                <span className="pause-button__icon" aria-hidden="true">
                    {pending ? (
                        <Spinner
                            aria-hidden="true"
                            role="presentation"
                            aria-label={undefined}
                            className="motion-reduce:animate-none"
                        />
                    ) : (
                        <Icon />
                    )}
                </span>
                <span className="pause-button__label">{t(LABELS[kind])}</span>
            </button>

            {error !== null && (
                <p id={errorId} className="pause-control__error">
                    <CircleAlert aria-hidden="true" />
                    {error}
                </p>
            )}
        </div>
    );
}
