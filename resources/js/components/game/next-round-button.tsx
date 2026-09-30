import { CircleAlert, SkipForward } from 'lucide-react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

export type NextRoundButtonProps = {
    /**
     * Libellé déjà traduit : `game.host.next_round` au salon (l'hôte seul),
     * `game.solo.next_round` en solo (L60-16).
     */
    label: string;
    /** Le geste est en vol : aucun second envoi, le focus reste en place. */
    pending: boolean;
    /**
     * Geste impossible : onglet supplanté, connexion perdue, siège sorti.
     * Le serveur relit tout sous verrou de toute façon.
     */
    disabled: boolean;
    /** Dernier échec, déjà traduit (`game.errors.not_revealing`…), ou nul. */
    error: string | null;
    onAdvance: () => void;
};

/**
 * « Manche suivante » (spec 60 § 5.4, § 13.5 ; 90 § 10, état « Révélation ») :
 * pendant la révélation seulement, il raccourcit `R`, jamais `D`. La page le
 * rend à l'hôte, et à lui seul ; le serveur répond 409 hors révélation.
 *
 * - **Chargement** : bouton occupé (`aria-busy`, indicateur neutralisé),
 *   `aria-disabled` et non `disabled` — le focus reste sur lui —, aucun
 *   second envoi.
 * - **Erreur** : le message sous le bouton, lié par `aria-describedby`, en
 *   texte et icône (jamais la seule couleur) ; il est annoncé par l'appelant
 *   (`announce()`), cette ligne n'est pas une région vivante (C16 § 4).
 * - Cible d'au moins `min-h-11` ; tokens seulement ; ni Echo, ni horloge,
 *   ni requête (C16 § 2.9) : le geste est `onAdvance`.
 */
export function NextRoundButton({
    label,
    pending,
    disabled,
    error,
    onAdvance,
}: NextRoundButtonProps) {
    const errorId = useId();

    return (
        <div className="flex flex-col gap-1">
            <Button
                type="button"
                disabled={disabled}
                aria-disabled={pending ? true : undefined}
                aria-busy={pending}
                aria-describedby={error === null ? undefined : errorId}
                onClick={() => {
                    if (!pending) {
                        onAdvance();
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
                    <SkipForward aria-hidden="true" />
                )}
                {label}
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
