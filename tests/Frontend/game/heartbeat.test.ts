import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { createHeartbeat } from '@/lib/game/heartbeat';
import type { HeartbeatSendOutcome } from '@/lib/game/heartbeat';

/*
 * Battement de présence côté client (spec 60 § 13.1, lot L60-13). Ajout au
 * lot, au-delà de ses intitulés (qui ne nomment aucun test front) : parties
 * pures seulement, aucun réseau ni DOM (C18 § 2.4). L'envoi et les réveils de
 * l'onglet sont injectés, les minuteurs factices ; la cadence est une donnée
 * de test, jamais une constante du client.
 */

/** Cadence de test, en millisecondes. */
const INTERVAL_MS = 10_000;

const HEARTBEAT_URL = '/r/ABC234/heartbeat';

function harness(outcome: () => HeartbeatSendOutcome = () => 'ok') {
    const sent: string[] = [];
    const wakes = new Set<() => void>();
    let pending: Array<() => void> = [];
    let settleImmediately = true;

    const heartbeat = createHeartbeat({
        url: HEARTBEAT_URL,
        intervalMs: INTERVAL_MS,
        send: (url) => {
            sent.push(url);

            if (settleImmediately) {
                return Promise.resolve(outcome());
            }

            return new Promise<HeartbeatSendOutcome>((resolve) => {
                pending.push(() => resolve(outcome()));
            });
        },
        subscribeWake: (listener) => {
            wakes.add(listener);

            return () => wakes.delete(listener);
        },
    });

    return {
        heartbeat,
        sent,
        wakes,
        wake: () => {
            for (const listener of wakes) {
                listener();
            }
        },
        hold: () => {
            settleImmediately = false;
        },
        release: () => {
            const settle = pending;

            pending = [];
            settleImmediately = true;

            for (const resolve of settle) {
                resolve();
            }
        },
    };
}

describe('battement de présence', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it("n'envoie qu'un battement au montage, même sous le double montage de strictMode", async () => {
        const { heartbeat, sent } = harness();

        // strictMode : abonne, désabonne, réabonne dans le même tour.
        heartbeat.subscribe(() => undefined)();
        const unsubscribe = heartbeat.subscribe(() => undefined);

        await vi.advanceTimersByTimeAsync(0);

        expect(sent).toEqual([HEARTBEAT_URL]);
        expect(heartbeat.status()).toBe('beating');

        // Puis un battement par intervalle, et un seul minuteur.
        await vi.advanceTimersByTimeAsync(INTERVAL_MS * 3);

        expect(sent).toHaveLength(4);

        // Désabonné : plus rien.
        unsubscribe();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS * 3);

        expect(sent).toHaveLength(4);
        expect(heartbeat.status()).toBe('idle');
    });

    it('bat aussitôt au retour de visibilité ou en ligne, sans jamais chevaucher le battement en vol', async () => {
        const { heartbeat, sent, wake, hold, release } = harness();

        heartbeat.subscribe(() => undefined);
        await vi.advanceTimersByTimeAsync(0);

        expect(sent).toHaveLength(1);

        // Réveil de l'onglet : un battement tout de suite.
        wake();
        await vi.advanceTimersByTimeAsync(0);

        expect(sent).toHaveLength(2);

        // Un battement qui tarde : ni le réveil ni l'intervalle n'en lancent
        // un second tant qu'il est en vol.
        hold();
        wake();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        wake();

        expect(sent).toHaveLength(3);

        release();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);

        expect(sent).toHaveLength(4);
    });

    it("s'arrête pour de bon sur un 403 et le signale, mais pas sur un échec passager", async () => {
        const outcomes: HeartbeatSendOutcome[] = ['failed', 'ok', 'refused'];
        const { heartbeat, sent, wakes } = harness(
            () => outcomes.shift() ?? 'ok',
        );
        const notified: string[] = [];

        heartbeat.subscribe(() => notified.push(heartbeat.status()));
        await vi.advanceTimersByTimeAsync(0);

        // Réseau coupé : abandonné en silence, la cadence continue.
        expect(heartbeat.status()).toBe('beating');

        await vi.advanceTimersByTimeAsync(INTERVAL_MS * 2);

        // Le troisième est refusé : plus aucun minuteur, plus aucun réveil.
        expect(sent).toHaveLength(3);
        expect(heartbeat.status()).toBe('refused');
        expect(notified.at(-1)).toBe('refused');
        expect(wakes.size).toBe(0);

        await vi.advanceTimersByTimeAsync(INTERVAL_MS * 5);

        expect(sent).toHaveLength(3);

        // Un nouvel abonné ne le relance pas.
        heartbeat.subscribe(() => undefined);
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);

        expect(sent).toHaveLength(3);
    });
});
