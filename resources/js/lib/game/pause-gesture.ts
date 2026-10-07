/**
 * La pause manuelle vue du client (D64 du 07/10, spec 60 § 14) : quel geste
 * montrer, et comment lire l'échec d'un geste. Fonctions pures, éprouvées par
 * Vitest sans DOM (C18 § 2.4). **Le serveur décide seul** : autorité relue
 * sous le verrou du salon, phase relue après rattrapage, budget et drainage ;
 * ce module ne fait que choisir le bouton à offrir.
 */
import type { GameStoreState } from '@/lib/game/store';
import { parseIsoMs } from '@/lib/game/wire';

/** Les trois gestes de la pause manuelle. */
export type PauseGestureKind = 'pause' | 'cancel' | 'resume';

/** Ce qu'un échec de geste veut dire pour l'écran. */
export type PauseGestureFailure =
    | 'not_running'
    | 'no_round_left'
    | 'budget_exhausted'
    | 'draining'
    | 'superseded'
    | 'cancelled'
    | 'failed';

/** Le geste que l'écran offre au siège, et pourquoi pour la reprise. */
export type PauseControl = {
    kind: PauseGestureKind;
    /**
     * Reprise offerte à un siège qui n'est pas l'hôte, parce que l'hôte n'est
     * pas un siège présent (60 § 1.2) : l'écran le dit.
     */
    hostAbsent: boolean;
};

type PauseControlState = Pick<
    GameStoreState,
    | 'mode'
    | 'status'
    | 'pause'
    | 'pauseRequested'
    | 'seats'
    | 'self'
    | 'roundsCount'
    | 'rounds'
>;

/**
 * La dernière manche est-elle **jouée** à `nowMs` (60 § 1.2) — en cours,
 * close, en révélation, ou programmée et `T₁` franchi ? Le serveur refuse
 * alors toute pause (`no_round_left`) : rien ne reste à suspendre. Son
 * décompte, `T₁` à venir, reste pausable.
 */
function lastRoundPlayed(state: PauseControlState, nowMs: number): boolean {
    const last = state.roundsCount;

    return (
        last !== null &&
        state.rounds.some(
            (round) =>
                round.roundNumber === last &&
                (round.phase === 'running' ||
                    round.phase === 'closed' ||
                    round.phase === 'revealing' ||
                    (round.phase === 'scheduled' &&
                        parseIsoMs(round.startsAt) <= nowMs)),
        )
    );
}

/**
 * Le geste à offrir au siège à l'instant serveur `nowMs`, ou nul :
 *
 * - partie en cours : « Pause » à l'hôte (au joueur en solo), sauf une fois
 *   la dernière manche jouée (le serveur la refuserait, `no_round_left`) ;
 *   « Annuler la pause » si elle est déjà demandée ;
 * - pause **manuelle** : « Reprendre » à l'hôte (au joueur en solo) ; à tout
 *   siège présent de la partie si l'hôte n'en est pas un ;
 * - pause automatique (`empty`) : rien — le retour d'un siège la reprend.
 */
export function pauseControlOf(
    state: PauseControlState,
    nowMs: number,
): PauseControl | null {
    const solo = state.mode === 'solo';
    const author = solo || state.self.isHost;

    if (state.status === 'running') {
        if (!author) {
            return null;
        }

        if (state.pauseRequested) {
            return { kind: 'cancel', hostAbsent: false };
        }

        return lastRoundPlayed(state, nowMs)
            ? null
            : { kind: 'pause', hostAbsent: false };
    }

    if (
        state.status !== 'paused' ||
        state.pause === null ||
        state.pause.kind !== 'manual'
    ) {
        return null;
    }

    if (author) {
        return { kind: 'resume', hostAbsent: false };
    }

    const present = (seat: GameStoreState['seats'][number]): boolean =>
        seat.firstRoundNumber !== null &&
        seat.connection === 'connected' &&
        !seat.kicked;
    const host = state.seats.find((seat) => seat.isHost) ?? null;
    const self =
        state.seats.find((seat) => seat.publicId === state.self.publicId) ??
        null;

    return self !== null && present(self) && (host === null || !present(host))
        ? { kind: 'resume', hostAbsent: true }
        : null;
}

/** HTTP 409 Conflict : refus de la pause, ou onglet supplanté. */
const HTTP_CONFLICT = 409;

/** Codes du 409 des gestes de pause, miroir de `PauseGestureOutcome::conflictCode()`. */
const CONFLICT_CODES: Readonly<Record<string, PauseGestureFailure>> = {
    not_running: 'not_running',
    no_round_left: 'no_round_left',
    budget_exhausted: 'budget_exhausted',
    draining: 'draining',
    seat_superseded: 'superseded',
};

/** Code des annulations du client HTTP d'Inertia (`HttpCancelledError`). */
const CANCELLED_CODE = 'ERR_CANCELLED';

function codeOf(data: unknown): unknown {
    let body: unknown = data;

    if (typeof body === 'string') {
        try {
            body = JSON.parse(body) as unknown;
        } catch {
            return null;
        }
    }

    return typeof body === 'object' && body !== null && 'code' in body
        ? body.code
        : null;
}

/**
 * Lecture d'un échec par la FORME de l'erreur du client HTTP d'Inertia
 * (`response.status`, `response.data` en texte brut ; `ERR_CANCELLED`),
 * jamais par sa classe — même patron que `readNextRoundFailure`.
 */
export function readPauseGestureFailure(error: unknown): PauseGestureFailure {
    if (typeof error !== 'object' || error === null) {
        return 'failed';
    }

    if (
        ('code' in error && error.code === CANCELLED_CODE) ||
        ('name' in error && error.name === 'AbortError')
    ) {
        return 'cancelled';
    }

    if (!('response' in error)) {
        return 'failed';
    }

    const response = (
        error as { response?: { status?: unknown; data?: unknown } }
    ).response;

    if (response?.status !== HTTP_CONFLICT) {
        return 'failed';
    }

    const code = codeOf(response.data);

    return typeof code === 'string' && Object.hasOwn(CONFLICT_CODES, code)
        ? CONFLICT_CODES[code]
        : 'failed';
}
