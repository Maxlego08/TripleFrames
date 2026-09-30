import { CircleAlert, Eye, SkipForward } from 'lucide-react';
import { useId } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';

export type SoloRoundActionsProps = {
    /** Le geste en vol, ou nul : aucun second envoi, le focus reste en place. */
    pending: 'reveal' | 'skip' | null;
    /**
     * Gestes impossibles : onglet supplanté, connexion perdue. Le serveur
     * relit tout sous verrou de toute façon (409 `round_not_running`).
     */
    disabled: boolean;
    /** Dernier échec d'un de ces gestes, déjà traduit, ou nul. */
    error: string | null;
    onReveal: () => void;
    onSkip: () => void;
};

/**
 * Les deux gestes d'entraînement assisté du solo (spec 60 § 16.5, D18 du
 * 23/09 ; 90 § 10, « Solo »), sous la saisie d'une manche en cours :
 *
 * - **Voir la réponse** (`game.solo.reveal_answer`) : la manche se clôt et la
 *   révélation normale suit, à 0 point ;
 * - **Passer la manche** (`game.solo.skip_round`) : la manche se clôt sans
 *   révélation, et la suivante démarre après son décompte.
 *
 * La page ne les rend qu'en solo et tant que la saisie du siège est ouverte ;
 * jamais en multijoueur (barrière 1 de 10 § 7.10). Deux boutons secondaires,
 * côte à côte sur une seule ligne, qui ne disputent pas la place du champ —
 * un libellé trop long pour sa moitié passe à la ligne, jamais hors du
 * cadre ; cibles d'au moins `min-h-11`.
 *
 * - **Chargement** : le bouton du geste en vol est occupé (`aria-busy`,
 *   indicateur neutralisé) ; les deux passent `aria-disabled` — et non
 *   `disabled` —, pour que le focus reste où il est, et aucun second envoi ne
 *   part.
 * - **Erreur** : le message sous les boutons, lié par `aria-describedby`, en
 *   texte et icône (jamais la seule couleur) ; il est annoncé par l'appelant
 *   (`announce()`), cette ligne n'est pas une région vivante (C16 § 4).
 *
 * Composant de présentation : ni Echo, ni horloge, ni requête (C16 § 2.9) ;
 * tokens seulement.
 */
export function SoloRoundActions({
    pending,
    disabled,
    error,
    onReveal,
    onSkip,
}: SoloRoundActionsProps) {
    const { t } = useTranslations();
    const errorId = useId();
    const busy = pending !== null;

    const action = (
        kind: 'reveal' | 'skip',
        label: string,
        icon: ReactNode,
        onClick: () => void,
    ) => (
        <Button
            type="button"
            variant="outline"
            disabled={disabled}
            aria-disabled={busy ? true : undefined}
            aria-busy={pending === kind}
            aria-describedby={error === null ? undefined : errorId}
            onClick={() => {
                if (!busy) {
                    onClick();
                }
            }}
            className="h-auto min-h-11 min-w-0 flex-1 gap-1.5 px-2 whitespace-normal has-[>svg]:px-2 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
        >
            {pending === kind ? (
                <Spinner
                    aria-hidden="true"
                    role="presentation"
                    aria-label={undefined}
                    className="motion-reduce:animate-none"
                />
            ) : (
                icon
            )}
            {label}
        </Button>
    );

    return (
        <div className="flex flex-col gap-1">
            <div className="flex gap-2">
                {action(
                    'reveal',
                    t('game.solo.reveal_answer'),
                    <Eye aria-hidden="true" />,
                    onReveal,
                )}
                {action(
                    'skip',
                    t('game.solo.skip_round'),
                    <SkipForward aria-hidden="true" />,
                    onSkip,
                )}
            </div>

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
