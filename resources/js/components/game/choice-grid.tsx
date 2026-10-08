import { useEffect, useEffectEvent, useId, useRef } from 'react';
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
 * quatre cartes sur deux colonnes (`choice-grid`, aux couleurs de l'écran
 * de manche de la maquette `game.html`), au pouce, cibles d'au moins
 * 2,75 rem.
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
            className="choice-grid"
        >
            <span id={labelId} className="sr-only">
                {t('game.choices.label')}
            </span>

            <div
                lang={payload.lang ?? undefined}
                className="choice-grid__choices"
            >
                {payload.choices.map((choice, index) => (
                    <button
                        key={index}
                        ref={index === 0 ? firstChoice : undefined}
                        type="button"
                        aria-disabled={disabled}
                        onClick={() => choose(choice)}
                        className="choice-grid__choice"
                    >
                        {choice}
                    </button>
                ))}
            </div>

            {(pending || message !== null) && (
                <p className="choice-grid__message">
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

/**
 * Dernière manche dont l'absence de QCM a été annoncée, gardée au niveau du
 * module pour la même raison que `announcedChoices` : ni le double montage
 * de `strictMode`, ni un paquet qui répète l'information ne la relisent.
 */
let announcedUnavailable: string | null = null;

export type ChoicesUnavailableProps = {
    /** Clé de la manche (`roundKeyOf`) : une annonce par manche, pas plus. */
    roundKey: string;
};

/**
 * Le QCM de la manche n'existe pas (cas terminal de 70 § 10.7, D54 du
 * 02/10) : `game.choices.unavailable` à la place de la grille, lu une fois
 * par l'unique région vivante, sans déplacer le focus — le joueur qui tape
 * garde sa frappe (90 § 7.5).
 *
 * Monté **seulement** sur l'information du serveur
 * (`RoundState.choicesUnavailable`, porté par `tier.opened` ou le paquet) :
 * aucun minuteur client n'en décide (règle 8). Tokens seulement.
 */
export function ChoicesUnavailable({ roundKey }: ChoicesUnavailableProps) {
    const { t } = useTranslations();

    const arrive = useEffectEvent((): void => {
        if (announcedUnavailable !== roundKey) {
            announcedUnavailable = roundKey;
            announce(t('game.choices.unavailable'));
        }
    });

    useEffect(() => arrive(), [roundKey]);

    return <p className="answer-zone__note">{t('game.choices.unavailable')}</p>;
}
