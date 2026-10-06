import { CircleCheck } from 'lucide-react';
import { useEffect, useEffectEvent, useId, useRef } from 'react';
import { AnswerInput } from '@/components/game/answer-input';
import { ChoiceGrid, ChoicesUnavailable } from '@/components/game/choice-grid';
import { LoadingState } from '@/components/state/loading-state';
import type { AnswerSubmission } from '@/hooks/game/use-answer-submission';
import { useTranslations } from '@/hooks/use-translations';
import { INPUT_STATE_KEYS } from '@/lib/game/input-state-keys';
import { ordinalKey } from '@/lib/game/scoring-format';
import type { ChoicesPayload, SeatInputView } from '@/types/answers';
import type { InputDifficulty } from '@/types/room-settings';

export type RoundInputProps = {
    /**
     * Clé de la manche (`roundKeyOf`) : la saisie et la grille sont neuves à
     * chaque manche, et prennent le focus d'ouverture à leur montage.
     */
    roundKey: string;
    /** `input_difficulty` figée de la partie. */
    difficulty: InputDifficulty;
    /**
     * `self.input` du magasin : nul au début d'une manche, tant qu'aucune
     * soumission ni relecture ne l'a rempli — la ligne de manche naît `open`
     * à `T₁` (`OpenTier(1)`, 70 § 3.1).
     */
    input: SeatInputView | null;
    /** Les quatre propositions du siège pour cette manche, ou nulles. */
    choices: ChoicesPayload | null;
    /**
     * Le palier du QCM s'est ouvert sans propositions (`RoundState`, cas
     * terminal de 70 § 10.7, D54 du 02/10) : su du serveur, jamais déduit.
     */
    choicesUnavailable: boolean;
    /** Tentatives restantes (repli : `attemptsPerRound` des réglages figés). */
    attemptsLeft: number;
    /** `maxAnswerLength` de la partie : un confort, la borne reste serveur. */
    maxLength: number;
    /** La soumission de la manche (`useAnswerSubmission`). */
    submission: AnswerSubmission;
    /** Saisie inerte : onglet supplanté, siège sorti. */
    disabled: boolean;
    /**
     * Position d'arrivée du siège dans `round.locked` (`player.locked`) : non
     * nulle, le siège est verrouillé, même si sa saisie est encore inconnue
     * (réponse de la soumission acceptée perdue) — la relecture suivante rend
     * les points.
     */
    lockRank: number | null;
};

/**
 * La saisie d'une manche ouverte (spec 70 § 16, 90 § 7.2, § 7.5 et § 10),
 * selon la difficulté de saisie figée :
 *
 * - **Facile** : le QCM dès `T₁`, focus au premier bouton ; tant que les
 *   propositions ne sont pas arrivées (`seat.choices`), le chargement ; l'état
 *   d'une saisie close se dit sous la grille.
 * - **Normal** : le texte libre, puis le QCM à `T_N`, annoncé sans voler le
 *   focus ; « texte épuisé, QCM attendu » se dit sous le champ (D20 du
 *   23/09). Si le serveur dit le QCM indisponible (D54 du 02/10),
 *   `game.choices.unavailable` prend la place de la grille.
 * - **Expert** : le texte libre seul.
 * - **Joueur verrouillé** : ni champ ni grille, mais {@link LockedPanel} —
 *   points gagnés, position d'arrivée —, jamais le titre ni la réponse
 *   saisie (00 § Écran du joueur verrouillé).
 *
 * Aucune fermeture n'est décidée ici (règle 8 reformulée) : c'est l'état du
 * serveur qui ferme la saisie, et la page qui retire la zone à la clôture.
 */
export function RoundInput({
    roundKey,
    difficulty,
    input,
    choices,
    choicesUnavailable,
    attemptsLeft,
    maxLength,
    submission,
    disabled,
    lockRank,
}: RoundInputProps) {
    const { t } = useTranslations();
    const state = lockRank !== null ? 'locked' : (input?.inputState ?? 'open');

    if (state === 'locked') {
        return <LockedPanel lock={input?.locked ?? null} lockRank={lockRank} />;
    }

    const easy = difficulty === 'easy';
    const acceptsChoice = state === 'open' || state === 'text_exhausted';
    const stateKey = INPUT_STATE_KEYS[state];
    const choiceError =
        submission.error?.source === 'choice' ? submission.error.message : null;
    // En Facile, la grille est la saisie : l'état clos se dit sous elle. En
    // Normal, il se dit sous le champ (70 § 16).
    const choiceMessage =
        choiceError ?? (easy && stateKey !== null ? t(stateKey) : null);

    return (
        <div className="flex flex-col gap-2">
            {!easy && (
                <AnswerInput
                    key={`${roundKey}:text`}
                    state={state}
                    attemptsLeft={attemptsLeft}
                    maxLength={maxLength}
                    pending={submission.pending}
                    onSubmit={submission.submitText}
                    last={submission.last}
                    error={submission.error}
                    disabled={disabled}
                />
            )}

            {choices !== null ? (
                <ChoiceGrid
                    key={`${roundKey}:choices`}
                    payload={choices}
                    primary={easy}
                    disabled={submission.pending || !acceptsChoice || disabled}
                    pending={submission.pending}
                    onChoose={submission.submitChoice}
                    message={choiceMessage}
                />
            ) : easy ? (
                <LoadingState
                    label={t('common.state.loading')}
                    className="py-2"
                />
            ) : (
                choicesUnavailable && <ChoicesUnavailable roundKey={roundKey} />
            )}
        </div>
    );
}

type LockedPanelProps = {
    /**
     * Les points du siège, par la réponse HTTP de sa soumission (`TierScore`,
     * destinataire unique) ou, après une relecture, `SelfState.input.locked`.
     * Nuls quand le verrou n'est connu que par `player.locked` : la réponse
     * s'est perdue, la prochaine relecture les rendra.
     */
    lock: SeatInputView['locked'];
    /** Position d'arrivée lue dans `player.locked`, à défaut du verrou. */
    lockRank: number | null;
};

/**
 * L'écran du joueur verrouillé (spec 60 § 8.4 ; 90 § 10 ; 00 § Le jeu en une
 * manche) : « Trouvé ! » en texte, icône et token `--success` ; les points
 * gagnés en grand (`pointsTotal`), le bonus de rapidité en détail quand il
 * n'est pas nul ; la position d'arrivée. Les images continuent, le chrono
 * reste affiché et le fil « a trouvé » vit à côté : la page les garde.
 *
 * **Focus** : le champ de saisie, démonté au verrouillage, emporte le focus ;
 * le titre du panneau (`tabIndex={-1}`) le reprend, seulement s'il est perdu
 * (sur `body`) — jamais volé à un contrôle qui le tient.
 */
function LockedPanel({ lock, lockRank }: LockedPanelProps) {
    const { t, tChoice, locale } = useTranslations();
    const headingId = useId();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const number = new Intl.NumberFormat(locale);
    const rank = lock?.lockRank ?? lockRank;

    const reclaimFocus = useEffectEvent((): void => {
        const focused = document.activeElement;

        if (focused === null || focused === document.body) {
            headingRef.current?.focus();
        }
    });

    useEffect(() => reclaimFocus(), []);

    return (
        <section
            aria-labelledby={headingId}
            className="flex flex-col gap-1 rounded-md border border-border px-3 py-2"
        >
            <h2
                id={headingId}
                ref={headingRef}
                tabIndex={-1}
                className="flex items-center gap-2 font-semibold text-success outline-none"
            >
                <CircleCheck aria-hidden="true" className="size-5 shrink-0" />
                {t('game.answer.locked')}
            </h2>

            {lock !== null && (
                <p className="text-2xl font-semibold tabular-nums">
                    {t('game.score.gained', {
                        points: tChoice('game.score.points', lock.pointsTotal, {
                            count: number.format(lock.pointsTotal),
                        }),
                    })}
                </p>
            )}

            {lock !== null && lock.pointsBonus > 0 && (
                <p className="text-sm text-muted-foreground">
                    {t('game.score.bonus', {
                        points: number.format(lock.pointsBonus),
                    })}
                </p>
            )}

            {rank !== null && (
                <p className="text-sm">
                    {t('game.round.lock_rank', {
                        rank: t(ordinalKey(rank, locale), {
                            rank: number.format(rank),
                        }),
                    })}
                </p>
            )}
        </section>
    );
}
