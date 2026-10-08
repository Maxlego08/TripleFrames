import { describe, expect, it } from 'vite-plus/test';
import {
    pauseControlOf,
    readPauseGestureFailure,
} from '@/lib/game/pause-gesture';
import type { GameStoreState } from '@/lib/game/store';
import type { RoundState, SeatView } from '@/types/game-wire';

/*
 * Pause manuelle vue du client (D64 du 07/10, spec 60 § 14) : quel geste
 * offrir, et la lecture des refus du serveur (codes 409).
 */

const SELF = 'SELFSEAT000A';
const HOST = 'HOSTSEAT000B';

function seat(publicId: string, extra: Partial<SeatView> = {}): SeatView {
    return {
        publicId,
        nickname: publicId,
        masked: false,
        avatar: { kind: null, url: null, altKey: 'avatar', initials: 'X' },
        isHost: publicId === HOST,
        connection: 'connected',
        kicked: false,
        firstRoundNumber: 1,
        ...extra,
    };
}

type ControlState = Parameters<typeof pauseControlOf>[0];

const SELF_STATE: GameStoreState['self'] = {
    publicId: SELF,
    seatActive: true,
    isHost: false,
    member: true,
    participates: true,
    input: null,
    ownScore: 0,
};

const HOST_STATE: GameStoreState['self'] = {
    ...SELF_STATE,
    publicId: HOST,
    isHost: true,
};

function state(extra: Partial<ControlState> = {}): ControlState {
    return {
        mode: 'multiplayer',
        status: 'running',
        pause: null,
        pauseRequested: false,
        seats: [seat(HOST), seat(SELF)],
        self: SELF_STATE,
        roundsCount: null,
        rounds: [],
        ...extra,
    };
}

/** L'instant serveur des cas d'essai. */
const NOW = Date.parse('2026-10-07T14:00:00.000Z');

function control(
    controlState: ControlState,
): ReturnType<typeof pauseControlOf> {
    return pauseControlOf(controlState, NOW);
}

/** Une manche du magasin, de numéro `roundNumber` sur 10, à `startsAt`. */
function round(
    roundNumber: number,
    phase: RoundState['phase'],
    startsAtMs: number,
): RoundState {
    return {
        sequenceIndex: roundNumber,
        roundNumber,
        roundsCount: 10,
        startsAt: new Date(startsAtMs).toISOString(),
        durationMs: 30_000,
        tiers: [],
        choicesAtTierIndex: null,
        phase,
        currentTierIndex: null,
        images: [],
        locked: [],
        endedAt: null,
        revealStartsAt: null,
        revealEndsAt: null,
        reveal: null,
        choicesUnavailable: false,
    } as RoundState;
}

const MANUAL = {
    pausedAt: '2026-10-07T14:00:00.000Z',
    interruptsAt: '2026-10-07T14:14:55.000Z',
    kind: 'manual',
} as const;

/** Une erreur de réponse telle que la lève le client HTTP d'Inertia. */
function responseError(status: number, data: unknown): unknown {
    return {
        name: 'HttpResponseError',
        response: {
            status,
            data: typeof data === 'string' ? data : JSON.stringify(data),
        },
    };
}

describe('pause-gesture', () => {
    it("offre la pause puis son annulation à l'hôte, rien aux autres sièges", () => {
        expect(control(state({ self: HOST_STATE }))).toEqual({
            kind: 'pause',
            hostAbsent: false,
        });
        expect(
            control(state({ self: HOST_STATE, pauseRequested: true })),
        ).toEqual({ kind: 'cancel', hostAbsent: false });
        expect(control(state())).toBeNull();
    });

    it("n'offre la reprise qu'en pause manuelle, à l'hôte ou à un siège présent quand l'hôte ne l'est plus", () => {
        expect(
            control(
                state({ self: HOST_STATE, status: 'paused', pause: MANUAL }),
            ),
        ).toEqual({ kind: 'resume', hostAbsent: false });

        // Hôte présent : le siège n'a pas la main.
        expect(control(state({ status: 'paused', pause: MANUAL }))).toBeNull();

        // Hôte déconnecté : tout siège présent reprend.
        expect(
            control(
                state({
                    status: 'paused',
                    pause: MANUAL,
                    seats: [
                        seat(HOST, { connection: 'disconnected' }),
                        seat(SELF),
                    ],
                }),
            ),
        ).toEqual({ kind: 'resume', hostAbsent: true });

        // Le siège lui-même absent : rien.
        expect(
            control(
                state({
                    status: 'paused',
                    pause: MANUAL,
                    seats: [
                        seat(HOST, { connection: 'left' }),
                        seat(SELF, { connection: 'disconnected' }),
                    ],
                }),
            ),
        ).toBeNull();

        // Pause automatique : le retour d'un siège la reprend, aucun geste.
        expect(
            control(
                state({
                    self: HOST_STATE,
                    status: 'paused',
                    pause: { ...MANUAL, kind: 'empty' },
                }),
            ),
        ).toBeNull();
    });

    it("ne l'offre plus une fois la dernière manche jouée, mais pendant son décompte", () => {
        const host = { self: HOST_STATE, roundsCount: 10 };

        // Avant-dernière manche en cours : la dernière reste à jouer.
        expect(
            control(
                state({ ...host, rounds: [round(9, 'running', NOW - 5_000)] }),
            ),
        ).toEqual({ kind: 'pause', hostAbsent: false });

        // Dernière manche en décompte, T₁ à venir : la pause est immédiate.
        expect(
            control(
                state({
                    ...host,
                    rounds: [round(10, 'scheduled', NOW + 3_000)],
                }),
            ),
        ).toEqual({ kind: 'pause', hostAbsent: false });

        // Dernière manche jouée : T₁ franchi, en cours, close ou révélée.
        for (const [phase, startsAt] of [
            ['scheduled', NOW - 1],
            ['running', NOW - 5_000],
            ['closed', NOW - 31_000],
            ['revealing', NOW - 32_000],
        ] as const) {
            expect(
                control(
                    state({ ...host, rounds: [round(10, phase, startsAt)] }),
                ),
            ).toBeNull();
        }

        // Une demande déjà posée reste annulable.
        expect(
            control(
                state({
                    ...host,
                    pauseRequested: true,
                    rounds: [round(10, 'running', NOW - 5_000)],
                }),
            ),
        ).toEqual({ kind: 'cancel', hostAbsent: false });
    });

    it('offre les trois gestes au joueur solo, sans hôte', () => {
        expect(control(state({ mode: 'solo' }))).toEqual({
            kind: 'pause',
            hostAbsent: false,
        });
        expect(
            control(state({ mode: 'solo', status: 'paused', pause: MANUAL })),
        ).toEqual({ kind: 'resume', hostAbsent: false });
        expect(
            control(state({ mode: 'solo', status: 'completed' })),
        ).toBeNull();
    });

    it('lit les refus de la pause, un onglet supplanté, une annulation, et tout le reste comme un échec', () => {
        for (const code of [
            'not_running',
            'no_round_left',
            'budget_exhausted',
            'draining',
        ] as const) {
            expect(readPauseGestureFailure(responseError(409, { code }))).toBe(
                code,
            );
        }

        expect(
            readPauseGestureFailure(
                responseError(409, { code: 'seat_superseded' }),
            ),
        ).toBe('superseded');
        expect(readPauseGestureFailure({ code: 'ERR_CANCELLED' })).toBe(
            'cancelled',
        );
        expect(
            readPauseGestureFailure(responseError(409, { code: 'toString' })),
        ).toBe('failed');
        expect(readPauseGestureFailure(responseError(403, ''))).toBe('failed');
        expect(readPauseGestureFailure(responseError(409, 'pas du json'))).toBe(
            'failed',
        );
        expect(readPauseGestureFailure(null)).toBe('failed');
    });
});
