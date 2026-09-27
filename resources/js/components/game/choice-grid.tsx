import { useEffect, useEffectEvent, useId, useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import type { ChoicesPayload } from '@/types/answers';

export type ChoiceGridProps = {
    /** Les quatre propositions du siège (`seat.choices`, `self.input.choices`). */
    payload: ChoicesPayload;
    /** Aucun clic ne part : envoi en vol, saisie close, onglet supplanté. */
    disabled: boolean;
    /** Rend la chaîne telle que reçue, jamais un index. */
    onChoose: (choice: string) => void;
    /**
     * [Ajout] Le QCM est la saisie de la manche (Facile) : le focus va au
     * premier bouton au montage. Sinon (Normal, texte épuisé compris), son
     * apparition est annoncée sans déplacer le focus (C16 § 2.12).
     */
    primary?: boolean;
    /** [Ajout] Un clic est en vol (`useAnswerSubmission().pending`). */
    pending?: boolean;
    /**
     * [Ajout] Texte déjà traduit sous la grille, lié au groupe par
     * `aria-describedby` : échec d'un clic, ou état de la saisie en Facile.
     */
    message?: string | null;
};

/**
 * Dernières propositions annoncées, gardées au niveau du module : le double
 * montage de `strictMode`, une resynchronisation qui rend les mêmes quatre
 * chaînes ou un second rendu ne les annoncent jamais deux fois. Une manche
 * nouvelle a une cible nouvelle, donc d'autres chaînes.
 */
let announcedChoices: string | null = null;

/**
 * La grille du QCM (spec 70 § 16, contrat C11 ; 90 § 7.2, § 7.5 et § 9.2) :
 * quatre boutons en `grid grid-cols-2`, au pouce, cibles d'au moins
 * `min-h-11 min-w-11`.
 *
 * - **Règle 3** : l'ordre affiché est celui reçu, propre au siège ; aucune
 *   couleur, aucune classe, aucun `data-*`, aucune marque qui dépende
 *   d'autre chose que la charge — les quatre boutons sont identiques, et
 *   l'envoi en vol se dit sur le groupe (`aria-busy`, indicateur sous la
 *   grille), jamais sur la proposition cliquée. Un clic renvoie la chaîne
 *   reçue, à l'octet près.
 * - **Langue** : le conteneur des quatre chaînes porte
 *   `lang={payload.lang ?? undefined}` — la locale atteinte à la
 *   composition, absente pour des titres originaux (05 § QCM, A-41) ; le
 *   libellé du groupe, dans la langue du joueur, reste hors de ce conteneur.
 * - **Clavier** : `Tab` parcourt les quatre propositions dans l'ordre
 *   visuel. Désactivée, la grille garde ses boutons focalisables
 *   (`aria-disabled`) : le clic en vol ne fait pas perdre sa place au
 *   joueur, et un clic de trop ne part pas.
 * - **Focus et annonce** (C16 § 2.12) : voir `primary`. L'annonce
 *   `game.a11y.choices_shown` passe par l'unique région vivante.
 * - Composant découplé : ni Echo, ni horloge, ni requête ; tokens seulement.
 */
export function ChoiceGrid({
    payload,
    disabled,
    onChoose,
    primary = false,
    pending = false,
    message = null,
}: ChoiceGridProps) {
    const { t } = useTranslations();
    const id = useId();
    const labelId = `${id}-label`;
    const messageId = `${id}-message`;
    const firstChoice = useRef<HTMLButtonElement>(null);
    const signature = JSON.stringify([payload.lang, payload.choices]);

    const arrive = useEffectEvent((): void => {
        if (primary) {
            if (!disabled) {
                firstChoice.current?.focus();
            }

            return;
        }

        if (announcedChoices !== signature) {
            announcedChoices = signature;
            announce(t('game.a11y.choices_shown'));
        }
    });

    useEffect(() => arrive(), [signature]);

    const choose = (choice: string): void => {
        if (!disabled) {
            onChoose(choice);
        }
    };

    return (
        <div
            role="group"
            aria-labelledby={labelId}
            aria-describedby={message === null ? undefined : messageId}
            aria-busy={pending}
            className="flex flex-col gap-1"
        >
            <span id={labelId} className="sr-only">
                {t('game.choices.label')}
            </span>

            <div
                lang={payload.lang ?? undefined}
                className="grid grid-cols-2 gap-2"
            >
                {payload.choices.map((choice, index) => (
                    <Button
                        key={index}
                        ref={index === 0 ? firstChoice : undefined}
                        type="button"
                        variant="outline"
                        aria-disabled={disabled}
                        onClick={() => choose(choice)}
                        className="h-auto min-h-11 min-w-11 py-2 text-center break-words whitespace-normal aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                    >
                        {choice}
                    </Button>
                ))}
            </div>

            {(pending || message !== null) && (
                <p className="flex items-center gap-2 text-sm text-foreground">
                    {pending && (
                        <Spinner
                            aria-hidden="true"
                            role="presentation"
                            aria-label={undefined}
                            className="motion-reduce:animate-none"
                        />
                    )}
                    {message !== null && <span id={messageId}>{message}</span>}
                </p>
            )}
        </div>
    );
}
