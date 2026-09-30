import type { InputState, SubmissionResult } from '@/types/answers';
import type { TranslationKey } from '@/types/translations';

/**
 * Messages de la saisie (spec 70 § 16 et § 17, contrats C10 et C11) : la
 * table des messages d'état, ce que la saisie affiche après une soumission,
 * et la lecture d'une réponse HTTP qui n'est pas un verdict.
 *
 * Tout ici est **en données** : une clé, un genre de retour, un message déjà
 * résolu par le serveur. Le texte naît au rendu, dans la langue du joueur.
 * Rien ne juge la réponse (règle 1) : le client ne sait jamais pourquoi une
 * saisie est refusée, et le refus n'a qu'un seul texte, quelle qu'en soit la
 * cause (décision 13 : jamais « presque »).
 */

/**
 * Message de chaque état de saisie, sur le patron de `lib/admin-enum-keys.ts`
 * : `t()` est typé, une clé composée par gabarit ne compilerait pas (C15
 * § 2.7), et un état ajouté à `InputState` sans sa ligne ici casse `tsc`.
 *
 * `open` n'a pas de message : la saisie est ouverte. `text_exhausted` (D20 du
 * 23/09) dit que les propositions arrivent avec la dernière image ; c'est ce
 * texte, et jamais le `message` du corps, qu'affiche un 409 dont l'état relu
 * vaut `text_exhausted` (§ 7.7) : ce 409 ne ferme pas le QCM attendu.
 * `revealed` et `skipped` (solo, D18 du 23/09) disent la saisie close.
 */
export const INPUT_STATE_KEYS: Record<InputState, TranslationKey | null> = {
    open: null,
    text_exhausted: 'game.answer.text_exhausted',
    attempts_exhausted: 'game.answer.exhausted',
    locked: 'game.answer.locked',
    qcm_wrong: 'game.choices.wrong',
    revealed: 'game.answer.closed',
    skipped: 'game.answer.closed',
};

/** Voie d'une soumission : la route texte ou la route clic (§ 7.1). */
export type SubmissionSource = 'text' | 'choice';

/**
 * Pourquoi une soumission n'a rendu aucun verdict — aucune n'est comptée
 * (§ 8) :
 *
 * - `invalid` : 422, saisie illisible, trop longue ou proposition inconnue,
 *   message du sac d'erreurs résolu par le serveur ;
 * - `too_fast` : 429 du limiteur `answer`, message résolu par le serveur ;
 * - `superseded` : 409 `seat_superseded`, un autre onglet tient le siège ;
 * - `failed` : réseau, 403, 5xx, corps illisible.
 */
export type SubmissionErrorKind =
    | 'invalid'
    | 'too_fast'
    | 'superseded'
    | 'failed';

/** L'échec d'une soumission, message déjà traduit. */
export type SubmissionError = {
    source: SubmissionSource;
    kind: SubmissionErrorKind;
    message: string;
};

/**
 * Ce que la saisie affiche sous son champ, en données :
 *
 * - `state` : le message de la table, pour une saisie qui n'est plus ouverte ;
 * - `rejected` : le refus neutre (« Ce n'est pas ça. »), un seul texte pour
 *   toute cause ;
 * - `message` : un texte déjà résolu — saisie close d'une manche finie (409,
 *   `message` du serveur) ou échec sans verdict ; `invalid` marque le 422,
 *   lié au champ par `aria-describedby` et `aria-invalid`.
 */
export type InputFeedback =
    | { kind: 'state'; key: TranslationKey }
    | { kind: 'rejected' }
    | { kind: 'message'; message: string; invalid: boolean };

/**
 * Le retour à afficher pour une saisie dans l'état `state` (magasin de 60,
 * qui a déjà appliqué la dernière réponse), après la dernière réponse `last`
 * et le dernier échec `error` de la manche :
 *
 * 1. une saisie qui n'est plus ouverte dit son état, par la table : l'état
 *    du serveur l'emporte sur tout, car il peut se fermer sans passer par
 *    la soumission (`player.locked` du siège, relecture, « Passer » ou
 *    « Voir la réponse » en solo) — un échec antérieur, qu'aucun verdict
 *    n'a effacé, ne masque jamais « Trouvé ! » ni la saisie close ;
 * 2. un échec sans verdict, sur une saisie ouverte : c'est la dernière
 *    chose arrivée, et un nouvel envoi l'efface ;
 * 3. une réponse close dit l'état relu s'il n'est pas `open` — jamais le
 *    `message` d'un 409 `text_exhausted` —, sinon le `message` du serveur
 *    (manche finie pendant l'envoi) ;
 * 4. un refus dit le refus neutre ;
 * 5. sinon, rien.
 */
export function inputFeedback(
    state: InputState,
    last: SubmissionResult | null,
    error: SubmissionError | null,
): InputFeedback | null {
    const stateKey = INPUT_STATE_KEYS[state];

    if (stateKey !== null) {
        return { kind: 'state', key: stateKey };
    }

    if (error !== null) {
        return {
            kind: 'message',
            message: error.message,
            invalid: error.kind === 'invalid',
        };
    }

    if (last?.result === 'closed') {
        const closedKey = INPUT_STATE_KEYS[last.inputState];

        return closedKey === null
            ? { kind: 'message', message: last.message, invalid: false }
            : { kind: 'state', key: closedKey };
    }

    return last?.result === 'rejected' ? { kind: 'rejected' } : null;
}

/** Les sept états, lus sur la table : la seule liste côté client. */
const INPUT_STATES: ReadonlySet<string> = new Set(
    Object.keys(INPUT_STATE_KEYS),
);

function isInputState(value: unknown): value is InputState {
    return typeof value === 'string' && INPUT_STATES.has(value);
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value);
}

/**
 * Le corps est-il un verdict de `round.answer.store` ou `round.choice.store`
 * (§ 7.7) ? Vérifié champ par champ : un corps d'une autre forme n'est jamais
 * appliqué au magasin.
 */
export function isSubmissionResult(body: unknown): body is SubmissionResult {
    if (typeof body !== 'object' || body === null || !('result' in body)) {
        return false;
    }

    const value = body as Record<string, unknown>;

    switch (value.result) {
        case 'accepted':
            return (
                value.inputState === 'locked' &&
                isCount(value.lockRank) &&
                isCount(value.tierIndex) &&
                isCount(value.pointsTier) &&
                isCount(value.pointsBonus) &&
                isCount(value.pointsTotal)
            );
        case 'rejected':
            return (
                isInputState(value.inputState) && isCount(value.attemptsLeft)
            );
        case 'closed':
            return (
                isInputState(value.inputState) &&
                typeof value.message === 'string'
            );
        default:
            return false;
    }
}

/**
 * Ce que dit une soumission rejetée par le client HTTP d'Inertia (réponse
 * non 2xx, réseau, annulation) :
 *
 * - `result` : 409 `closed`, un verdict à appliquer comme un autre ;
 * - `superseded` : 409 `seat_superseded` de `seat.active` ;
 * - `too_fast` : 429 du limiteur, avec son `message` s'il est lisible ;
 * - `cancelled` : requête interrompue (page quittée), rien à dire ;
 * - `failed` : tout le reste.
 *
 * Le 422 n'arrive jamais ici : `useHttp()` le rend par son rappel `onError`.
 */
export type SubmissionFailure =
    | { kind: 'result'; result: SubmissionResult }
    | { kind: 'superseded' }
    | { kind: 'too_fast'; message: string | null }
    | { kind: 'cancelled' }
    | { kind: 'failed' };

/** HTTP 409 Conflict : saisie close, ou onglet supplanté. */
const HTTP_CONFLICT = 409;

/** HTTP 429 Too Many Requests : limiteur `answer`. */
const HTTP_TOO_MANY_REQUESTS = 429;

/** Code du 409 de `seat.active`, miroir de `EnsureActiveSeat::SUPERSEDED`. */
const SEAT_SUPERSEDED = 'seat_superseded';

/** Code des annulations du client HTTP d'Inertia (`HttpCancelledError`). */
const CANCELLED_CODE = 'ERR_CANCELLED';

function parseBody(data: unknown): unknown {
    if (typeof data !== 'string') {
        return data;
    }

    try {
        return JSON.parse(data) as unknown;
    } catch {
        return null;
    }
}

/**
 * Lecture d'un échec du client HTTP d'Inertia, par sa forme et non par sa
 * classe : `HttpResponseError` porte `response.status` et `response.data`
 * (texte brut), `HttpCancelledError` le code `ERR_CANCELLED`.
 */
export function readSubmissionFailure(error: unknown): SubmissionFailure {
    if (typeof error !== 'object' || error === null) {
        return { kind: 'failed' };
    }

    if (
        ('code' in error && error.code === CANCELLED_CODE) ||
        ('name' in error && error.name === 'AbortError')
    ) {
        return { kind: 'cancelled' };
    }

    if (!('response' in error)) {
        return { kind: 'failed' };
    }

    const response = (
        error as { response?: { status?: unknown; data?: unknown } }
    ).response;
    const body = parseBody(response?.data);

    if (response?.status === HTTP_CONFLICT) {
        if (isSubmissionResult(body) && body.result === 'closed') {
            return { kind: 'result', result: body };
        }

        if (
            typeof body === 'object' &&
            body !== null &&
            'code' in body &&
            body.code === SEAT_SUPERSEDED
        ) {
            return { kind: 'superseded' };
        }
    }

    if (response?.status === HTTP_TOO_MANY_REQUESTS) {
        const message =
            typeof body === 'object' &&
            body !== null &&
            'message' in body &&
            typeof body.message === 'string' &&
            body.message !== ''
                ? body.message
                : null;

        return { kind: 'too_fast', message };
    }

    return { kind: 'failed' };
}
