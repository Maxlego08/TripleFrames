import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { watchMaintenance } from '@/lib/game/maintenance-refresh';

/*
 * Relecture du drapeau de drainage (spec 50 § 8.1, 100 § 11.3 ; BUG-P2 de la
 * répétition VM du 28/09). Tant que la prop partagée `maintenance` vaut
 * vrai, « Lancer la partie », « Rejouer » et la relance solo sont
 * désactivés : sans relecture, la page qui a vu le drapeau le garde après sa
 * levée, puisque le geste désactivé était la seule requête qui l'aurait
 * relu. Parties pures seulement, aucun réseau ni DOM (C18 § 2.4) : la
 * relecture et les réveils de l'onglet sont injectés, les minuteurs
 * factices ; la cadence est une donnée de test, jamais une constante du
 * client.
 */

/** Cadence de test, en millisecondes (`heartbeatIntervalMs` de `realtime`). */
const INTERVAL_MS = 10_000;

function harness() {
    let reloads = 0;
    let pending: Array<() => void> = [];
    let settleImmediately = true;
    const wakes = new Set<() => void>();

    const stop = watchMaintenance({
        intervalMs: INTERVAL_MS,
        reload: (done) => {
            reloads += 1;

            if (settleImmediately) {
                done();
            } else {
                pending.push(done);
            }
        },
        subscribeWake: (listener) => {
            wakes.add(listener);

            return () => wakes.delete(listener);
        },
    });

    return {
        stop,
        wakes,
        reloads: () => reloads,
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

            for (const done of settle) {
                done();
            }
        },
    };
}

describe('relecture du drapeau de drainage', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('relit la prop à chaque cadence, jamais au démarrage', async () => {
        const watch = harness();

        // La prop vient d'être lue avec la page : rien au démarrage.
        await vi.advanceTimersByTimeAsync(INTERVAL_MS - 1);
        expect(watch.reloads()).toBe(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(watch.reloads()).toBe(1);

        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        expect(watch.reloads()).toBe(2);

        watch.stop();
    });

    it('relit aussitôt au réveil de l’onglet', async () => {
        const watch = harness();

        watch.wake();
        expect(watch.reloads()).toBe(1);

        watch.stop();
    });

    it('ne chevauche jamais une relecture en vol', async () => {
        const watch = harness();

        watch.hold();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        expect(watch.reloads()).toBe(1);

        watch.wake();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        expect(watch.reloads()).toBe(1);

        watch.release();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        expect(watch.reloads()).toBe(2);

        watch.stop();
    });

    it('s’arrête pour de bon : ni cadence, ni réveil, ni fin tardive', async () => {
        const watch = harness();

        watch.hold();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        watch.stop();

        expect(watch.wakes.size).toBe(0);

        watch.release();
        await vi.advanceTimersByTimeAsync(INTERVAL_MS * 3);
        expect(watch.reloads()).toBe(1);
    });

    it('supporte le double montage de strictMode sans doubler la cadence', async () => {
        harness().stop();

        const watch = harness();

        await vi.advanceTimersByTimeAsync(INTERVAL_MS);
        expect(watch.reloads()).toBe(1);
        expect(vi.getTimerCount()).toBe(1);

        watch.stop();
        expect(vi.getTimerCount()).toBe(0);
    });
});
