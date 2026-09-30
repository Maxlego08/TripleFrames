import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import AnswerController from '@/actions/App/Http/Controllers/Game/AnswerController';
import ChoiceController from '@/actions/App/Http/Controllers/Game/ChoiceController';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import {
    INPUT_STATE_KEYS,
    isSubmissionResult,
    readSubmissionFailure,
} from '@/lib/game/input-state-keys';
import type {
    SubmissionError,
    SubmissionSource,
} from '@/lib/game/input-state-keys';
import { roundKeyOf, seatTokenHeaders } from '@/lib/game/store';
import type { GameStore } from '@/lib/game/store';
import type { SubmissionResult } from '@/types/answers';

export type UseAnswerSubmissionOptions = {
    /** Le magasin de la page (`useGameState`), qui applique chaque verdict. */
    store: GameStore;
    /** `public_id` du siège (`self.publicId`) : il adresse la route. */
    publicId: string;
    /**
     * `gameRef` de la partie suivie (`state.gameRef`) : avec `sequenceIndex`,
     * la clé de la manche (`roundKeyOf`), car `sequence_index` repart de 1 à
     * chaque partie (« Rejouer »). Nul sans partie, rien ne part.
     */
    gameRef: string | null;
    /**
     * `sequenceIndex` de la manche que le client croit ouverte, envoyé comme
     * `round` (§ 7.1) — jamais `round.id` ; nul hors manche, rien ne part.
     */
    sequenceIndex: number | null;
};

export type AnswerSubmission = {
    /** Soumet un texte libre (`round.answer.store`). */
    submitText: (text: string) => void;
    /** Soumet une proposition, la chaîne telle que reçue (`round.choice.store`). */
    submitChoice: (choice: string) => void;
    /** Une soumission est en vol : aucune autre ne part. */
    pending: boolean;
    /** Dernier verdict de la manche courante : 200, ou 409 `closed`. */
    last: SubmissionResult | null;
    /**
     * Dernier échec sans verdict de la manche courante (422, 429, onglet
     * supplanté, réseau), message déjà traduit ; effacé par le verdict
     * suivant. `last` reste alors inchangé.
     */
    error: SubmissionError | null;
};

/** Ce que la manche `key` a reçu en dernier. */
type Outcome = {
    /** `roundKeyOf(gameRef, sequenceIndex)` : jamais l'index seul. */
    key: string;
    last: SubmissionResult | null;
    error: SubmissionError | null;
};

/** Corps JSON des deux routes, clés camelCase (§ 7.1). */
type SubmissionBody = { round: number; answer?: string; choice?: string };

/** Champ du sac d'erreurs de chaque voie (422). */
const ERROR_FIELDS: Record<SubmissionSource, string> = {
    text: 'answer',
    choice: 'choice',
};

/**
 * Soumission d'une saisie de manche (spec 70 § 16, contrat C10 § 2) : le
 * texte libre par `round.answer.store`, le clic du QCM par
 * `round.choice.store`, adressés par Wayfinder — jamais une URL écrite —
 * et envoyés par `useHttp()`, sous l'en-tête `X-Seat-Token` du magasin de 60
 * (`seatTokenHeaders()`, que `useGameState` pose aussi sur toute requête).
 *
 * **Le client ne juge rien** (règle 1) : il ne normalise, ne compare ni ne
 * décide ; il envoie, puis applique le verdict du serveur au magasin
 * (`store.applySubmission`), à destinataire unique. Le palier retenu est
 * celui de l'instant serveur de réception, jamais d'une horloge d'ici.
 *
 * - **Une soumission à la fois**, texte et clic confondus : la suivante
 *   attend la réponse (`pending`). Le limiteur `answer` compte au serveur ;
 *   un envoi en parallèle ne ferait que lui échapper (E105-6).
 * - **Verdicts** — 200 `accepted` ou `rejected`, 409 `closed` — : appliqués
 *   au magasin, gardés dans `last`, et annoncés dans l'unique région vivante
 *   (`announce()`, C16 § 4) : un joueur qui garde le focus dans le champ
 *   entend le refus neutre et ses tentatives restantes, ou « Trouvé ! ».
 *   Un 409 dont l'état relu vaut `text_exhausted` dit la table des états,
 *   jamais le `message` du corps (§ 7.7).
 * - **Échecs sans verdict**, jamais comptés : 422 (sac d'erreurs, message du
 *   serveur), 429 (`game.answer.too_fast`, message du serveur), 409
 *   `seat_superseded` (le magasin se relit par l'écouteur d'erreur de
 *   `useGameState` et la page passe en lecture seule), réseau ou autre
 *   (`common.connection.offline` hors ligne, `common.state.error` sinon).
 *   `last` reste inchangé ; l'échec est dans `error`, et annoncé.
 * - `last` et `error` n'appartiennent qu'à leur manche, clé (`gameRef`,
 *   `sequenceIndex`) comme au magasin : une manche nouvelle, ou la manche de
 *   même rang d'une autre partie après « Rejouer », repart de rien, même si
 *   le hook reste monté. Un verdict n'est appliqué au magasin que s'il suit
 *   encore la partie de l'envoi.
 */
export function useAnswerSubmission(
    options: UseAnswerSubmissionOptions,
): AnswerSubmission {
    const { store, publicId, gameRef, sequenceIndex } = options;
    const { t, tChoice, locale } = useTranslations();
    const http = useHttp<SubmissionBody, SubmissionResult>({ round: 0 });
    const inFlight = useRef(false);
    const [pending, setPending] = useState(false);
    const [outcome, setOutcome] = useState<Outcome | null>(null);

    const attemptsText = (count: number): string =>
        tChoice('game.answer.attempts_left', count, {
            count: new Intl.NumberFormat(locale).format(count),
        });

    /** Ce que la région vivante dit d'un verdict. */
    const verdictMessages = (
        source: SubmissionSource,
        result: SubmissionResult,
    ): string[] => {
        const stateKey = INPUT_STATE_KEYS[result.inputState];

        switch (result.result) {
            case 'accepted':
                return stateKey === null ? [] : [t(stateKey)];
            case 'closed':
                return [stateKey === null ? result.message : t(stateKey)];
            case 'rejected':
                // Le refus neutre ne vaut que pour le texte : un clic faux
                // dit `qcm_wrong`, par la table.
                return [
                    ...(source === 'text' ? [t('game.answer.rejected')] : []),
                    stateKey === null
                        ? attemptsText(result.attemptsLeft)
                        : t(stateKey),
                ];
        }
    };

    const settle = (
        game: string,
        round: number,
        source: SubmissionSource,
        result: SubmissionResult,
    ): void => {
        // Le magasin n'est idempotent que sur (`gameRef`, `sequenceIndex`) :
        // jamais le verdict d'une partie quittée sur la manche de même rang
        // d'une autre.
        if (store.getState().gameRef === game) {
            store.applySubmission(round, result);
        }

        setOutcome({ key: roundKeyOf(game, round), last: result, error: null });

        for (const message of verdictMessages(source, result)) {
            announce(message);
        }
    };

    const fail = (key: string, error: SubmissionError): void => {
        setOutcome((previous) => ({
            key,
            last: previous?.key === key ? previous.last : null,
            error,
        }));
        announce(error.message);
    };

    const failedMessage = (): string =>
        typeof navigator !== 'undefined' && !navigator.onLine
            ? t('common.connection.offline')
            : t('common.state.error');

    const send = (source: SubmissionSource, value: string): void => {
        if (gameRef === null || sequenceIndex === null || inFlight.current) {
            return;
        }

        const game = gameRef;
        const round = sequenceIndex;
        const key = roundKeyOf(game, round);
        const body: SubmissionBody =
            source === 'text'
                ? { round, answer: value }
                : { round, choice: value };
        const action =
            source === 'text'
                ? AnswerController.store(publicId)
                : ChoiceController.store(publicId);
        let invalid: string | null = null;

        inFlight.current = true;
        setPending(true);
        http.transform(() => body);

        http.submit(action, {
            headers: seatTokenHeaders(),
            onError: (errors) => {
                const field = errors[ERROR_FIELDS[source]];

                invalid =
                    typeof field === 'string' && field !== ''
                        ? field
                        : (Object.values(errors).find(
                              (message) => message !== '',
                          ) ?? null);
            },
        })
            .then((response: unknown) => {
                if (isSubmissionResult(response)) {
                    settle(game, round, source, response);

                    return;
                }

                // `useHttp()` rend un 422 sans lever : son sac d'erreurs
                // est passé par `onError`.
                fail(
                    key,
                    invalid === null
                        ? { source, kind: 'failed', message: failedMessage() }
                        : { source, kind: 'invalid', message: invalid },
                );
            })
            .catch((error: unknown) => {
                const failure = readSubmissionFailure(error);

                switch (failure.kind) {
                    case 'result':
                        settle(game, round, source, failure.result);
                        break;
                    case 'superseded':
                        fail(key, {
                            source,
                            kind: 'superseded',
                            message: t('game.errors.seat_superseded'),
                        });
                        break;
                    case 'too_fast':
                        fail(key, {
                            source,
                            kind: 'too_fast',
                            message:
                                failure.message ?? t('game.answer.too_fast'),
                        });
                        break;
                    case 'cancelled':
                        break;
                    case 'failed':
                        fail(key, {
                            source,
                            kind: 'failed',
                            message: failedMessage(),
                        });
                        break;
                }
            })
            .finally(() => {
                inFlight.current = false;
                setPending(false);
            });
    };

    const currentKey =
        gameRef === null || sequenceIndex === null
            ? null
            : roundKeyOf(gameRef, sequenceIndex);
    const current =
        outcome !== null && outcome.key === currentKey ? outcome : null;

    return {
        submitText: (text) => send('text', text),
        submitChoice: (choice) => send('choice', choice),
        pending,
        last: current?.last ?? null,
        error: current?.error ?? null,
    };
}
