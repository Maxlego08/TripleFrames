import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    connectionStateOf,
    echoOptions,
    realtimeStatusOf,
    ROOM_EVENTS,
    SEAT_EVENTS,
    subscribeGameChannels,
} from '@/lib/game/echo';
import type { PageLocation } from '@/lib/game/echo';
import { createGameStore } from '@/lib/game/store';
import type { ResyncOutcome, ResyncReason } from '@/lib/game/store';
import type { ChoicesPayload } from '@/types/answers';
import type {
    GameEvent,
    GameEventName,
    GameEventPayloads,
    GameStatePacket,
    RealtimeConfig,
    RoundState,
    SeatView,
    TierImageRef,
} from '@/types/game-wire';
import type { TierWindow } from '@/types/scoring';

/*
 * Client Echo des pages de jeu (spec 60 § 10.5, A-27) : configuré à
 * l'exécution depuis la prop `realtime` et l'emplacement de la page, jamais
 * depuis une variable figée au build. Ajout au lot L60-9 (aucun intitulé de
 * la spec) : parties pures seulement, aucune connexion n'est ouverte.
 *
 * BUG-01 : la souscription des deux canaux d'un siège, éprouvée sur un faux
 * `laravel-echo` qui, comme Reverb, ne remet un événement qu'à un canal dont
 * l'abonnement est confirmé (`pusher:subscription_succeeded`).
 */

/** Un faux `laravel-echo` : canaux, confirmations et état de connexion pilotés. */
const wire = vi.hoisted(() => {
    type Handler = (payload?: unknown) => void;
    type Status = 'connecting' | 'connected' | 'disconnected' | 'failed';

    class FakeChannel {
        readonly handlers = new Map<string, Handler[]>();
        confirmed = false;

        listen(event: string, callback: Handler): this {
            return this.on(event, callback);
        }

        subscribed(callback: Handler): this {
            return this.on('pusher:subscription_succeeded', callback);
        }

        error(callback: Handler): this {
            return this.on('pusher:subscription_error', callback);
        }

        on(event: string, callback: Handler): this {
            this.handlers.set(event, [
                ...(this.handlers.get(event) ?? []),
                callback,
            ]);

            return this;
        }

        trigger(event: string, payload?: unknown): void {
            for (const handler of this.handlers.get(event) ?? []) {
                handler(payload);
            }
        }
    }

    let status: Status = 'connecting';
    const channels = new Map<string, FakeChannel>();
    const statusListeners = new Set<(status: Status) => void>();

    function channel(name: string): FakeChannel {
        const known = channels.get(name);

        if (known !== undefined) {
            return known;
        }

        const created = new FakeChannel();

        channels.set(name, created);

        return created;
    }

    class FakeEcho {
        readonly connector = {
            onConnectionChange: (
                listener: (status: Status) => void,
            ): (() => void) => {
                statusListeners.add(listener);

                return () => {
                    statusListeners.delete(listener);
                };
            },
        };

        connectionStatus(): Status {
            return status;
        }

        join(name: string): FakeChannel {
            return channel(`presence-${name}`);
        }

        private(name: string): FakeChannel {
            return channel(`private-${name}`);
        }

        leave(name: string): void {
            channels.delete(`presence-${name}`);
            channels.delete(`private-${name}`);
        }

        disconnect(): void {
            channels.clear();
        }
    }

    return {
        FakeEcho,
        /** Nouvel état de connexion ; hors `connected`, plus aucun canal confirmé. */
        setStatus(next: Status): void {
            status = next;

            if (next !== 'connected') {
                for (const each of channels.values()) {
                    each.confirmed = false;
                }
            }

            for (const listener of statusListeners) {
                listener(next);
            }
        },
        /** Le serveur confirme l'abonnement au canal Pusher `name`. */
        confirm(name: string): void {
            const confirmed = channels.get(name);

            if (confirmed !== undefined) {
                confirmed.confirmed = true;
                confirmed.trigger('pusher:subscription_succeeded', {
                    members: {},
                });
            }
        },
        /** Émission serveur : perdue tant que l'abonnement n'est pas confirmé. */
        broadcast(name: string, event: string, payload: unknown): void {
            const target = channels.get(name);

            if (target?.confirmed === true) {
                target.trigger(`.${event}`, payload);
            }
        },
        reset(): void {
            status = 'connecting';
            channels.clear();
            statusListeners.clear();
        },
    };
});

vi.mock('laravel-echo', () => ({ default: wire.FakeEcho }));

const CONFIG: RealtimeConfig = {
    key: 'cle-publique',
    host: null,
    port: null,
    scheme: null,
    heartbeatIntervalMs: 10_000,
    clockSamples: 3,
};

const HTTPS_PAGE: PageLocation = {
    hostname: 'jeu.example.test',
    port: '',
    protocol: 'https:',
};

describe('echo', () => {
    it("se configure à l'exécution depuis la prop realtime et l'emplacement de la page", () => {
        // Hôte, port et schéma nuls : ceux de la page, TLS en `https`, ports
        // implicites laissés aux défauts de `pusher-js`.
        expect(echoOptions(CONFIG, HTTPS_PAGE)).toEqual({
            broadcaster: 'reverb',
            key: 'cle-publique',
            wsHost: 'jeu.example.test',
            forceTLS: true,
            enabledTransports: ['ws', 'wss'],
            enableStats: false,
            withoutInterceptors: true,
        });

        // Valeurs de la prop : elles priment sur la page.
        expect(
            echoOptions(
                { ...CONFIG, host: 'localhost', port: 8080, scheme: 'http' },
                HTTPS_PAGE,
            ),
        ).toMatchObject({
            wsHost: 'localhost',
            wsPort: 8080,
            wssPort: 8080,
            forceTLS: false,
        });

        // Port explicite de la page.
        expect(
            echoOptions(CONFIG, {
                hostname: '127.0.0.1',
                port: '8000',
                protocol: 'http:',
            }),
        ).toMatchObject({ wsHost: '127.0.0.1', wsPort: 8000, forceTLS: false });

        // Aucune clé : aucune connexion tentée.
        expect(echoOptions({ ...CONFIG, key: '' }, HTTPS_PAGE)).toBeNull();
    });

    it('sépare les seize événements du salon des trois du siège', () => {
        expect(ROOM_EVENTS).toHaveLength(16);
        expect(SEAT_EVENTS).toEqual([
            'seat.choices',
            'seat.superseded',
            'seat.kicked',
        ]);
        expect(
            ROOM_EVENTS.filter((name) =>
                (SEAT_EVENTS as readonly string[]).includes(name),
            ),
        ).toEqual([]);
    });

    it('ne montre le bandeau que sur une coupure, jamais pendant la première ouverture', () => {
        expect(realtimeStatusOf('connecting', false)).toBe('connecting');
        expect(realtimeStatusOf('connecting', true)).toBe('reconnecting');
        expect(realtimeStatusOf('failed', false)).toBe('unavailable');

        expect(connectionStateOf('connecting', true)).toBe('connected');
        expect(connectionStateOf('idle', true)).toBe('connected');
        expect(connectionStateOf('reconnecting', true)).toBe('reconnecting');
        expect(connectionStateOf('unavailable', true)).toBe('reconnecting');
        expect(connectionStateOf('connected', false)).toBe('offline');
    });
});

// --- Souscription des canaux d'un siège (BUG-01) ------------------------------

/*
 * Une manche de salon à N = 2, D = 10 s, en Normal, la chronologie relevée au
 * navigateur (partie 2, manche 2) ramenée à T₁ = 0 : page rendue à 3 934 ms,
 * WebSocket ouverte à 4 746 ms, `tier.opened` et `seat.choices` émis à
 * T_N = 5 000 ms, présence confirmée à 6 524 ms, canal privé à 6 872 ms.
 * Préchargement de 2 s et battement de 10 s : des données de test, jamais
 * des constantes du client.
 */

/** Origine arbitraire, T₁ de la manche : 28/09/2026 01:56:44.472 UTC. */
const ORIGIN_MS = Date.UTC(2026, 8, 28, 1, 56, 44, 472);
const GAME = '3f9a0c1d2e4b5a69';
const SELF = 'WMV5N1RAMWCJ';
const HOST = 'HOSTSEAT000A';
const CHANNELS = { room: 'room.clef', seat: `seat.${SELF}` };
const LEAD_MS = 2000;
const HEARTBEAT_MS = 10_000;
const TIERS: TierWindow[] = [
    { tierIndex: 1, startsAtOffsetMs: 0, durationMs: 5000, points: 200 },
    { tierIndex: 2, startsAtOffsetMs: 5000, durationMs: 5000, points: 100 },
];
const T_N = 5000;
const RENDERED = 3934;
const SOCKET_OPEN = 4746;
const PRESENCE_CONFIRMED = 6524;
const SEAT_CONFIRMED = 6872;
const CHOICES: ChoicesPayload = {
    choices: ['Alpha', 'Bravo', 'Charlie', 'Delta'],
    useOriginalTitle: false,
    lang: 'fr',
};

function iso(ms: number): string {
    return new Date(ORIGIN_MS + ms).toISOString();
}

async function advanceTo(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ORIGIN_MS + ms - Date.now());
}

function image(tierIndex: number): TierImageRef {
    return {
        tierIndex,
        url: `/f/${String(tierIndex).repeat(32)}?expires=1&signature=s`,
        fetchNotBefore: iso(TIERS[tierIndex - 1].startsAtOffsetMs - LEAD_MS),
    };
}

function seat(publicId: string): SeatView {
    return {
        publicId,
        nickname: publicId === SELF ? 'Invité B' : 'Invité A',
        masked: false,
        avatar: { kind: null, url: null, altKey: 'avatar', initials: 'I' },
        isHost: publicId === HOST,
        connection: 'connected',
        kicked: false,
        firstRoundNumber: 1,
    };
}

function runningRound(currentTierIndex: number): RoundState {
    return {
        sequenceIndex: 2,
        roundNumber: 2,
        roundsCount: 3,
        startsAt: iso(0),
        durationMs: 10_000,
        tiers: TIERS,
        choicesAtTierIndex: 2,
        phase: 'running',
        currentTierIndex,
        images: [image(1), image(2)],
        locked: [],
        endedAt: null,
        revealStartsAt: null,
        revealEndsAt: null,
        reveal: null,
        choicesUnavailable: false,
    };
}

/** Le paquet du siège B à `atMs` : palier courant, propositions reçues ou non. */
function packetAt(
    atMs: number,
    currentTierIndex: number,
    choices: ChoicesPayload | null,
): GameStatePacket {
    return {
        v: 1,
        serverNow: iso(atMs),
        gameRef: GAME,
        mode: 'multiplayer',
        channels: CHANNELS,
        status: 'running',
        roundsCount: 3,
        roundsCompleted: 1,
        framesPerRound: 2,
        inputDifficulty: 'normal',
        maxAnswerLength: 60,
        seats: [seat(HOST), seat(SELF)],
        pause: null,
        round: runningRound(currentTierIndex),
        self: {
            publicId: SELF,
            seatActive: true,
            isHost: false,
            member: true,
            participates: true,
            input: {
                inputState: 'open',
                attemptsLeft: 5,
                choices,
                locked: null,
            },
            ownScore: 0,
        },
        leaderboard: { scoreless: false, roundNumber: 1, rows: [] },
        podium: null,
        nextTransitionAt: iso(currentTierIndex === 1 ? T_N : 10_000),
    };
}

function event<N extends GameEventName>(
    atMs: number,
    payload: GameEventPayloads[N],
): GameEvent<N> {
    return { v: 1, serverNow: iso(atMs), gameRef: GAME, ...payload };
}

describe('echo : souscription des canaux du siège', () => {
    let unsubscribe: (() => void) | null = null;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(ORIGIN_MS);
        vi.stubGlobal('window', { location: HTTPS_PAGE });
        wire.reset();
    });

    afterEach(() => {
        // Canaux quittés et connexion fermée au tour suivant : l'instance
        // paresseuse du module ne survit pas au test.
        unsubscribe?.();
        unsubscribe = null;
        vi.runOnlyPendingTimers();
        vi.runOnlyPendingTimers();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('signale les deux canaux prêts à chaque confirmation de la paire, jamais avant', async () => {
        const ready = vi.fn();
        const noop = (): void => undefined;

        // Double montage de `strictMode` : souscrit, quitte, resouscrit.
        subscribeGameChannels(CONFIG, CHANNELS, noop, ready)();
        unsubscribe = subscribeGameChannels(CONFIG, CHANNELS, noop, ready);

        // Connexion ouverte : les abonnements ne sont pas encore confirmés.
        wire.setStatus('connected');
        wire.confirm('presence-room.clef');
        expect(ready).not.toHaveBeenCalled();

        wire.confirm(`private-seat.${SELF}`);
        expect(ready).toHaveBeenCalledTimes(1);

        // Un abonné qui se branche sur une paire déjà confirmée n'a rien reçu
        // de ce qui est parti avant lui : il est prévenu, une fois.
        const late = vi.fn();
        const lateListener = (): void => undefined;
        const unsubscribeLate = subscribeGameChannels(
            CONFIG,
            CHANNELS,
            lateListener,
            late,
        );

        await Promise.resolve();
        expect(late).toHaveBeenCalledTimes(1);
        expect(ready).toHaveBeenCalledTimes(1);
        unsubscribeLate();

        // Coupure : `pusher-js` resouscrit chaque canal à la reconnexion, et
        // la paire n'est de nouveau prête qu'à la seconde confirmation.
        wire.setStatus('connecting');
        wire.setStatus('connected');
        wire.confirm(`private-seat.${SELF}`);
        expect(ready).toHaveBeenCalledTimes(1);

        wire.confirm('presence-room.clef');
        expect(ready).toHaveBeenCalledTimes(2);

        // Désabonné : plus aucun signal.
        unsubscribe();
        unsubscribe = null;
        wire.confirm('presence-room.clef');
        expect(ready).toHaveBeenCalledTimes(2);
    });

    it('rattrape par une relecture le QCM émis entre le rendu de la page et la confirmation des canaux', async () => {
        vi.setSystemTime(ORIGIN_MS + RENDERED);

        const resyncs: ResyncReason[] = [];
        const answers: Array<(outcome: ResyncOutcome) => void> = [];
        const store = createGameStore({
            // La page, rendue avant T_N : aucune proposition encore composée.
            initial: packetAt(RENDERED, 1, null),
            resync: (reason) => {
                resyncs.push(reason);

                return new Promise<ResyncOutcome>((resolve) => {
                    answers.push(resolve);
                });
            },
            now: () => Date.now(),
            heartbeatIntervalMs: HEARTBEAT_MS,
        });
        const stopObserving = store.subscribe(() => undefined);

        // Le branchement de `use-game-state` : événements au magasin, relecture
        // à chaque confirmation de la paire.
        unsubscribe = subscribeGameChannels(
            CONFIG,
            CHANNELS,
            store.receive,
            () => store.requestResync('subscribed'),
        );

        await advanceTo(SOCKET_OPEN);
        wire.setStatus('connected');

        // À T_N, le serveur émet sur des canaux pas encore confirmés : Reverb
        // ne les remet pas, et ne les rejouera jamais.
        await advanceTo(T_N);
        wire.broadcast(
            'presence-room.clef',
            'tier.opened',
            event<'tier.opened'>(T_N, {
                sequenceIndex: 2,
                roundNumber: 2,
                tierIndex: 2,
                opensAt: iso(T_N),
                next: null,
                choicesUnavailable: false,
            }),
        );
        wire.broadcast(
            `private-seat.${SELF}`,
            'seat.choices',
            event<'seat.choices'>(T_N, { sequenceIndex: 2, ...CHOICES }),
        );

        await advanceTo(PRESENCE_CONFIRMED);
        wire.confirm('presence-room.clef');
        await advanceTo(SEAT_CONFIRMED);
        wire.confirm(`private-seat.${SELF}`);

        // Les deux canaux confirmés : l'état est relu, et le paquet porte les
        // quatre chaînes déjà composées pour ce siège (60 § 12.2).
        expect(resyncs).toEqual(['subscribed']);

        answers.shift()?.({
            kind: 'packet',
            packet: packetAt(SEAT_CONFIRMED + 60, 2, CHOICES),
        });
        await advanceTo(T_N + 3500);

        const state = store.getState();

        expect(state.offeredChoices).toEqual({
            sequenceIndex: 2,
            payload: CHOICES,
        });
        expect(state.self.input?.choices).toEqual(CHOICES);

        stopObserving();
    });
});
