import { ArrowRight, CircleAlert, CircleCheck } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Spinner } from '@/components/ui/spinner';
import { useTranslations } from '@/hooks/use-translations';
import { inputFeedback } from '@/lib/game/input-state-keys';
import type { SubmissionError } from '@/lib/game/input-state-keys';
import type { InputState, SubmissionResult } from '@/types/answers';

export type AnswerInputProps = {
    /** État de saisie du siège (`self.input.inputState` du magasin de 60). */
    state: InputState;
    /** Tentatives de texte restantes, affichées tant que la saisie est ouverte. */
    attemptsLeft: number;
    /**
     * `GameStatePacket.maxAnswerLength` (réglage figé de la partie) : confort
     * seulement, la borne reste serveur (422). Jamais un littéral.
     */
    maxLength: number;
    /** Une soumission est en vol (`useAnswerSubmission`). */
    pending: boolean;
    /** Soumet la saisie telle que tapée : le client ne normalise rien. */
    onSubmit: (text: string) => void;
    /** [Ajout] Dernier verdict de la manche (`useAnswerSubmission().last`). */
    last?: SubmissionResult | null;
    /**
     * [Ajout] Dernier échec sans verdict (`useAnswerSubmission().error`) :
     * seuls ceux de la voie texte s'affichent sous le champ ; un onglet
     * supplanté, quelle que soit la voie, rend le champ inerte.
     */
    error?: SubmissionError | null;
    /** [Ajout] Champ inerte : onglet supplanté, siège sorti de la partie. */
    disabled?: boolean;
};

/**
 * La saisie en texte libre d'une manche (spec 70 § 16, contrat C10 ; mise en
 * page en accord avec 90 § 7.2 et § 7.5).
 *
 * - **Champ actif si et seulement si `state === 'open'`** (et ni `disabled`
 *   ni onglet supplanté) ; aucune fermeture n'est décidée ici, ni par un
 *   minuteur (règle 8) : c'est l'état du serveur qui ferme. Une saisie close
 *   vide le champ — le joueur verrouillé ne montre jamais sa réponse (90
 *   § 10) — et dit son état par la table des messages (`INPUT_STATE_KEYS`).
 * - **`Entrée` soumet** (formulaire natif, `enterKeyHint="send"`). Pendant
 *   l'envoi, rien ne repart, mais le champ reste actif et garde le focus :
 *   un champ désactivé perdrait le focus et fermerait le clavier du
 *   téléphone. Le bouton d'envoi ne prend pas le focus au toucher, pour la
 *   même raison. Une saisie vide n'est pas envoyée ; tout le reste l'est,
 *   tel quel.
 * - **Retour neutre** sous le champ (`inputFeedback`) : un refus a un seul
 *   texte, quelle qu'en soit la cause (« Ce n'est pas ça. », jamais
 *   « presque ») ; saisie close ; trop rapide ; onglet supplanté ; hors
 *   ligne. Le message d'un 422 est lié au champ par `aria-describedby` et
 *   `aria-invalid`. Les tentatives restantes sont écrites en texte. Aucune de
 *   ces lignes n'est une région vivante : les annonces passent par
 *   `announce()` (`useAnswerSubmission`), seule région de la page (C16 § 4).
 * - **Focus** (C16 § 2.12) : au montage, sur le champ s'il est actif — la
 *   page monte une saisie neuve à chaque ouverture de manche. Après un
 *   refus, le texte envoyé est sélectionné, pour que la frappe suivante le
 *   remplace ; un texte déjà retouché n'est jamais sélectionné.
 * - Présentation : la carte `answer-form` de la maquette
 *   `design-test/html/game.html` (champ et bouton « Valider » corail),
 *   retours et tentatives sous la carte ; cibles d'au moins 2,75 rem.
 * - Composant découplé : ni Echo, ni horloge, ni requête (C16 § 2.9) ;
 *   aucune couleur ni taille en dur, la présentation vit dans `game.scss`.
 */
export function AnswerInput({
    state,
    attemptsLeft,
    maxLength,
    pending,
    onSubmit,
    last = null,
    error = null,
    disabled = false,
}: AnswerInputProps) {
    const { t, tChoice, locale } = useTranslations();
    const id = useId();
    const inputId = `${id}-answer`;
    const attemptsId = `${id}-attempts`;
    const feedbackId = `${id}-feedback`;
    const inputRef = useRef<HTMLInputElement>(null);
    const submitted = useRef<string | null>(null);
    const [value, setValue] = useState('');

    const open = state === 'open';
    const active = open && !disabled && error?.kind !== 'superseded';
    const feedback = inputFeedback(
        state,
        last,
        error?.source === 'text' ? error : null,
    );
    const feedbackText =
        feedback === null
            ? null
            : feedback.kind === 'state'
              ? t(feedback.key)
              : feedback.kind === 'rejected'
                ? t('game.answer.rejected')
                : feedback.message;
    const invalid = feedback?.kind === 'message' && feedback.invalid;
    const describedBy = [
        feedbackText === null ? null : feedbackId,
        open ? attemptsId : null,
    ]
        .filter((each) => each !== null)
        .join(' ');

    // Ouverture de manche : le focus va au champ (C16 § 2.12). Idempotent au
    // double montage de `strictMode`.
    const focusOnMount = useEffectEvent((): void => {
        if (active) {
            inputRef.current?.focus();
        }
    });

    useEffect(() => focusOnMount(), []);

    // Après un refus, la frappe suivante remplace le texte envoyé, s'il n'a
    // pas été retouché entre-temps.
    const selectRejected = useEffectEvent((): void => {
        const input = inputRef.current;

        if (
            input !== null &&
            document.activeElement === input &&
            input.value === submitted.current
        ) {
            input.select();
        }
    });

    useEffect(() => {
        if (last?.result === 'rejected') {
            selectRejected();
        }
    }, [last]);

    const submit = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();

        if (!active || pending || value.trim() === '') {
            return;
        }

        submitted.current = value;
        onSubmit(value);
    };

    return (
        <form
            noValidate
            onSubmit={submit}
            aria-busy={pending}
            className="answer-form"
        >
            <div className="answer-form__card">
                <label htmlFor={inputId} className="sr-only">
                    {t('game.answer.label')}
                </label>

                <input
                    ref={inputRef}
                    id={inputId}
                    type="text"
                    value={open ? value : ''}
                    onChange={(event) => setValue(event.target.value)}
                    disabled={!active}
                    maxLength={maxLength}
                    placeholder={t('game.answer.placeholder')}
                    autoComplete="off"
                    autoCorrect="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    enterKeyHint="send"
                    aria-invalid={invalid ? true : undefined}
                    aria-describedby={
                        describedBy === '' ? undefined : describedBy
                    }
                    className="answer-form__input"
                />

                <button
                    type="submit"
                    aria-disabled={!active || pending}
                    onMouseDown={(event) => event.preventDefault()}
                    className="answer-form__submit"
                >
                    <span className="answer-form__submit-label">
                        {t('game.answer.submit')}
                    </span>
                    {pending ? (
                        <Spinner
                            aria-hidden="true"
                            role="presentation"
                            aria-label={undefined}
                            className="motion-reduce:animate-none"
                        />
                    ) : (
                        <ArrowRight aria-hidden="true" />
                    )}
                </button>
            </div>

            {(feedbackText !== null || open) && (
                <p className="answer-form__meta">
                    {feedback !== null && feedbackText !== null && (
                        <span
                            id={feedbackId}
                            className={
                                feedback.kind === 'message'
                                    ? 'answer-form__feedback answer-form__feedback--error'
                                    : 'answer-form__feedback'
                            }
                        >
                            {feedback.kind === 'message' && (
                                <CircleAlert aria-hidden="true" />
                            )}
                            {state === 'locked' && (
                                <CircleCheck aria-hidden="true" />
                            )}
                            {feedbackText}
                        </span>
                    )}
                    {open && (
                        <span id={attemptsId}>
                            {tChoice(
                                'game.answer.attempts_left',
                                attemptsLeft,
                                {
                                    count: new Intl.NumberFormat(locale).format(
                                        attemptsLeft,
                                    ),
                                },
                            )}
                        </span>
                    )}
                </p>
            )}
        </form>
    );
}
