import { describe, expect, it } from 'vite-plus/test';
import { createServerClock } from '@/lib/game/server-clock';
import type { ClockTransport } from '@/lib/game/server-clock';
import type { IsoMs } from '@/types/game-wire';

/*
 * Horloge serveur, pour l'affichage seulement (spec 60 § 2.4, contrat C7
 * § 2.6).
 *
 * Le cœur `createServerClock()` reçoit son horloge locale et son transport :
 * aucun réseau, aucun DOM (C18 § 2.4). Chaque échantillon lit l'horloge
 * locale deux fois, au départ (t₀) puis à la réception (t₁), dans cet ordre :
 * la file de valeurs locales ci-dessous le rejoue pas à pas.
 */

/** Origine arbitraire des scénarios : 23/09/2026 14:05:13.004 UTC. */
const ORIGIN_MS = Date.UTC(2026, 8, 23, 14, 5, 13, 4);

/** Un instant du fil (`IsoMs`) à `ms` millisecondes de l'origine. */
function iso(ms: number): IsoMs {
    return new Date(ORIGIN_MS + ms).toISOString();
}

/** Horloge locale qui rend les valeurs données, dans l'ordre, puis la dernière. */
function localClock(values: readonly number[]): () => number {
    let index = 0;

    return () => {
        const value = values[Math.min(index, values.length - 1)];
        index += 1;

        return ORIGIN_MS + value;
    };
}

/**
 * Un échantillon : aller-retour local de `sentAt` à `receivedAt`, instant
 * serveur `offset` millisecondes au-dessus du milieu de l'aller-retour.
 * `null` : échantillon perdu (réseau, 429, réponse illisible).
 */
type Sample = {
    sentAt: number;
    receivedAt: number;
    offset: number | null;
};

/** Horloge locale et transport qui rejouent les échantillons, l'un après l'autre. */
function scenario(
    samples: readonly Sample[],
    after: readonly number[] = [],
): {
    localNow: () => number;
    transport: ClockTransport;
    calls: () => number;
} {
    let calls = 0;
    const local = localClock([
        ...samples.flatMap((sample) => [sample.sentAt, sample.receivedAt]),
        ...after,
    ]);

    const transport: ClockTransport = () => {
        const sample = samples[calls];
        calls += 1;

        if (sample === undefined || sample.offset === null) {
            return Promise.resolve(null);
        }

        const middle = (sample.sentAt + sample.receivedAt) / 2;

        return Promise.resolve(iso(middle + sample.offset));
    };

    return { localNow: local, transport, calls: () => calls };
}

describe('server-clock', () => {
    it('retient la médiane des échantillons', async () => {
        // Trois échantillons aux décalages 120, −30 et 400, pris l'un après
        // l'autre : la médiane (120) n'est ni la moyenne (163), ni le
        // dernier échantillon, ni le plus rapide.
        const { localNow, transport, calls } = scenario(
            [
                { sentAt: 0, receivedAt: 100, offset: 120 },
                { sentAt: 1000, receivedAt: 1040, offset: -30 },
                { sentAt: 2000, receivedAt: 2200, offset: 400 },
            ],
            [5000],
        );
        const clock = createServerClock(localNow, transport);

        expect(clock.offsetMs()).toBe(0);
        await expect(clock.handshake(3)).resolves.toBe(true);

        // Médiane de [120, −30, 400] = 120 ; la moyenne vaudrait 163.
        expect(calls()).toBe(3);
        expect(clock.offsetMs()).toBe(120);
        expect(clock.now()).toBe(ORIGIN_MS + 5000 + 120);

        // Un échantillon perdu est écarté : la médiane porte sur les autres.
        // Nombre pair d'échantillons retenus : moyenne des deux du milieu,
        // arrondie à la milliseconde.
        const lossy = scenario([
            { sentAt: 0, receivedAt: 10, offset: 7 },
            { sentAt: 100, receivedAt: 110, offset: null },
            { sentAt: 200, receivedAt: 210, offset: 900 },
            { sentAt: 300, receivedAt: 310, offset: 12 },
            { sentAt: 400, receivedAt: 410, offset: -50 },
        ]);
        const lossyClock = createServerClock(lossy.localNow, lossy.transport);

        await expect(lossyClock.handshake(5)).resolves.toBe(true);
        // Retenus [7, 900, 12, −50] → triés [−50, 7, 12, 900] → (7 + 12) / 2 = 9,5 → 10.
        expect(lossy.calls()).toBe(5);
        expect(lossyClock.offsetMs()).toBe(10);

        // Aucun échantillon abouti : le décalage courant est gardé.
        const silent = scenario([
            { sentAt: 0, receivedAt: 10, offset: null },
            { sentAt: 20, receivedAt: 30, offset: null },
        ]);
        const silentClock = createServerClock(
            silent.localNow,
            silent.transport,
        );

        silentClock.recalibrate(iso(0), ORIGIN_MS - 250);
        await expect(silentClock.handshake(2)).resolves.toBe(false);
        expect(silentClock.offsetMs()).toBe(250);

        // Un transport qui rejette ou rend un instant mal formé est un
        // échantillon perdu, jamais un NaN dans la médiane.
        let attempt = 0;
        const flaky = createServerClock(
            localClock([0, 20, 100, 120, 200, 220]),
            () => {
                attempt += 1;

                if (attempt === 1) {
                    return Promise.reject(new Error('network'));
                }

                if (attempt === 2) {
                    return Promise.resolve('2026-09-23 14:05:13');
                }

                return Promise.resolve(iso(210 + 33));
            },
        );

        await expect(flaky.handshake(3)).resolves.toBe(true);
        expect(flaky.offsetMs()).toBe(33);
    });

    it("relève le décalage sur un serverNow plus récent sans jamais l'abaisser", async () => {
        const { localNow, transport } = scenario([
            { sentAt: 0, receivedAt: 20, offset: 45 },
            { sentAt: 3000, receivedAt: 3020, offset: 10 },
        ]);
        const clock = createServerClock(localNow, transport);
        let notified = 0;
        const stop = clock.subscribe(() => {
            notified += 1;
        });

        await clock.handshake(1);
        expect(clock.offsetMs()).toBe(45);
        expect(notified).toBe(1);

        // Émis à `arrivée + 80` selon le serveur : le décalage vaut au moins
        // 80, il est relevé.
        const arrival = ORIGIN_MS + 1000;

        clock.recalibrate(iso(1000 + 80), arrival);
        expect(clock.offsetMs()).toBe(80);
        expect(notified).toBe(2);

        // Un minorant plus faible (60), ou très en arrière (un message resté
        // longtemps en transit), n'abaisse jamais le décalage.
        clock.recalibrate(iso(1000 + 60), arrival);
        clock.recalibrate(iso(1000 - 500), arrival);
        expect(clock.offsetMs()).toBe(80);
        expect(notified).toBe(2);

        // Un instant illisible est ignoré.
        clock.recalibrate('not-an-instant', arrival);
        expect(clock.offsetMs()).toBe(80);

        // Seule une nouvelle poignée de main l'abaisse.
        await clock.handshake(1);
        expect(clock.offsetMs()).toBe(10);
        expect(notified).toBe(3);

        stop();
        clock.recalibrate(iso(1000 + 500), arrival);
        expect(clock.offsetMs()).toBe(500);
        expect(notified).toBe(3);
    });
});
