import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    createFrameLoader,
    FRAME_MAX_ATTEMPTS,
    FRAME_RETRY_DELAY_MS,
} from '@/lib/game/frame-loader';
import type { FrameResponse } from '@/lib/game/frame-loader';
import type { TierImageRef } from '@/types/game-wire';

/*
 * Chargeur des images de jeu (spec 60 § 7.6, contrat C8).
 *
 * Aucun réseau ni DOM (C18 § 2.4) : la requête, la fabrique d'URL d'objet et
 * le décodage sont injectés, les minuteurs et `Date` sont factices. L'horloge
 * serveur est `Date.now()` plus un décalage que le test corrige comme le
 * ferait une poignée de main. Aucune image réelle : des octets quelconques
 * dans un `Blob`.
 */

/** Origine arbitraire : 23/09/2026 14:05:00.000 UTC. */
const ORIGIN_MS = Date.UTC(2026, 8, 23, 14, 5, 0, 0);

/** Un `IsoMs` à `ms` millisecondes de l'origine. */
function iso(ms: number): string {
    return new Date(ORIGIN_MS + ms).toISOString();
}

/** Une référence d'image au palier `tierIndex`, servable dès `fetchNotBefore`. */
function ref(
    tierIndex: number,
    fetchNotBefore: number,
    token = 'a',
): TierImageRef {
    return {
        tierIndex,
        url: `/f/${token.repeat(31)}${tierIndex}?expires=1&signature=s`,
        fetchNotBefore: iso(fetchNotBefore),
    };
}

function response(status: number): FrameResponse {
    return {
        status,
        ok: status >= 200 && status < 300,
        headers: { get: () => null },
        blob: () => Promise.resolve(new Blob(['octets'])),
    };
}

/** Un chargeur éprouvable : chaque requête et chaque URL d'objet sont relevées. */
function harness(serve: (url: string, serverMs: number) => number) {
    let offsetMs = 0;
    let created = 0;
    const requests: Array<{
        url: string;
        serverMs: number;
        signal: AbortSignal;
    }> = [];
    const revoked: string[] = [];
    const resyncs: string[] = [];
    const serverNow = (): number => Date.now() + offsetMs;

    const loader = createFrameLoader({
        now: serverNow,
        fetchFrame: (url, signal) => {
            const serverMs = serverNow() - ORIGIN_MS;

            requests.push({ url, serverMs, signal });

            return Promise.resolve(response(serve(url, serverMs)));
        },
        createObjectUrl: () => {
            created += 1;

            return `blob:frame-${created}`;
        },
        revokeObjectUrl: (url) => {
            revoked.push(url);
        },
        decode: () => Promise.resolve(),
        onResyncNeeded: (reason) => {
            resyncs.push(reason);
        },
    });

    return {
        loader,
        requests,
        revoked,
        resyncs,
        setOffset: (next: number) => {
            offsetMs = next;
        },
    };
}

describe('frame-loader', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(ORIGIN_MS);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('ne demande jamais une image avant fetchNotBefore', async () => {
        const { loader, requests, setOffset } = harness(() => 200);

        loader.request('g:1', ref(1, 1500));

        // Avant la garde : aucune requête, le cadre attend (jamais
        // « indisponible »).
        expect(requests).toHaveLength(0);
        expect(loader.view('g:1', 1)).toEqual({ src: null, pending: true });

        await vi.advanceTimersByTimeAsync(1499);
        expect(requests).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests.map((each) => each.serverMs)).toEqual([1500]);
        expect(loader.view('g:1', 1)).toEqual({
            src: 'blob:frame-1',
            pending: false,
        });

        // L'horloge serveur est corrigée à la baisse pendant l'attente : le
        // réveil programmé sur l'ancienne horloge ne fait pas partir la
        // requête, qui attend la garde sur l'horloge nouvelle.
        loader.request('g:1', ref(2, 5000));

        // En attendant le palier 2, l'écran garde l'image du palier 1 : aucun
        // aplat entre deux paliers.
        expect(loader.view('g:1', 2)).toEqual({
            src: 'blob:frame-1',
            pending: false,
        });

        await vi.advanceTimersByTimeAsync(1500);
        setOffset(-1000);
        await vi.advanceTimersByTimeAsync(2000);
        expect(requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(999);
        expect(requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests.map((each) => each.serverMs)).toEqual([1500, 5000]);

        // Une garde déjà franchie : la requête part aussitôt.
        loader.request('g:1', ref(3, 0));
        expect(requests.at(-1)?.serverMs).toBe(5000);
    });

    it("retente un 404 reçu avant l'ouverture puis abandonne après le dernier essai", async () => {
        // Horloge du client en avance de 600 ms : la garde du client tombe
        // avant celle du serveur, qui répond 404 jusqu'à la sienne.
        const early = harness((_url, serverMs) =>
            serverMs - 600 >= 1000 ? 200 : 404,
        );

        early.setOffset(600);
        early.loader.request('g:1', ref(1, 1000));
        await vi.advanceTimersByTimeAsync(400);
        expect(early.requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(FRAME_RETRY_DELAY_MS * 3);

        // Retenté toutes les `FRAME_RETRY_DELAY_MS`, jusqu'au 200.
        expect(early.requests.map((each) => each.serverMs)).toEqual([
            1000,
            1000 + FRAME_RETRY_DELAY_MS,
            1000 + 2 * FRAME_RETRY_DELAY_MS,
            1000 + 3 * FRAME_RETRY_DELAY_MS,
        ]);
        expect(early.loader.view('g:1', 1).src).toBe('blob:frame-1');
        expect(early.resyncs).toEqual([]);

        // Un 404 persistant : `FRAME_MAX_ATTEMPTS` essais, puis indisponible
        // et une seule demande de resynchronisation.
        const refused = harness(() => 404);

        refused.loader.request('g:2', ref(1, 0));
        await vi.advanceTimersByTimeAsync(
            FRAME_RETRY_DELAY_MS * FRAME_MAX_ATTEMPTS * 4,
        );

        expect(refused.requests).toHaveLength(FRAME_MAX_ATTEMPTS);
        expect(refused.loader.view('g:2', 1)).toEqual({
            src: null,
            pending: false,
        });
        expect(refused.resyncs).toEqual(['frame_unavailable']);

        // La même URL redonnée par la resynchronisation (refus persistant) ne
        // relance rien ; une URL nouvelle, si.
        refused.loader.request('g:2', ref(1, 0));
        await vi.advanceTimersByTimeAsync(10_000);
        expect(refused.requests).toHaveLength(FRAME_MAX_ATTEMPTS);

        refused.loader.request('g:2', ref(1, 0, 'b'));
        expect(refused.requests).toHaveLength(FRAME_MAX_ATTEMPTS + 1);
    });

    it("révoque les URL d'objet au changement de manche", async () => {
        const { loader, revoked, requests } = harness(() => 200);

        loader.request('g:1', ref(1, 0));
        loader.request('g:1', ref(2, 0));
        loader.request('g:2', ref(1, 0));
        await vi.advanceTimersByTimeAsync(0);

        expect(requests).toHaveLength(3);
        expect(loader.view('g:1', 2).src).toBe('blob:frame-2');

        // Pendant la révélation de la manche 1, ses images restent ; au
        // changement de manche, seules celles de la manche 2 survivent.
        loader.retainOnly(['g:1', 'g:2']);
        expect(revoked).toEqual([]);

        loader.retainOnly(['g:2']);
        expect(revoked.toSorted()).toEqual(['blob:frame-1', 'blob:frame-2']);
        expect(loader.view('g:1', 1)).toEqual({ src: null, pending: true });
        expect(loader.view('g:2', 1)).toEqual({
            src: 'blob:frame-3',
            pending: false,
        });

        // Une requête d'une manche quittée est interrompue ; un blob déjà
        // reçu mais encore en décodage est révoqué aussitôt, jamais montré.
        const signals: AbortSignal[] = [];
        const lateRevoked: string[] = [];
        let finishDecode: (() => void) | null = null;
        const slow = createFrameLoader({
            now: () => Date.now(),
            fetchFrame: (url, signal) => {
                signals.push(signal);

                return url.includes('slow')
                    ? new Promise<FrameResponse>(() => undefined)
                    : Promise.resolve(response(200));
            },
            createObjectUrl: () => 'blob:late',
            revokeObjectUrl: (url) => {
                lateRevoked.push(url);
            },
            decode: () =>
                new Promise<void>((resolve) => {
                    finishDecode = resolve;
                }),
        });

        slow.request('g:3', ref(1, 0));
        slow.request('g:3', { ...ref(2, 0), url: '/f/slow?expires=1' });
        await vi.advanceTimersByTimeAsync(0);
        slow.retainOnly([]);

        // Les deux requêtes de la manche quittée sont interrompues : celle
        // qui attend encore ses octets, et celle dont le blob se décode.
        expect(signals.map((signal) => signal.aborted)).toEqual([true, true]);

        const release = finishDecode as (() => void) | null;

        release?.();
        await vi.advanceTimersByTimeAsync(0);

        expect(lateRevoked).toEqual(['blob:late']);
        expect(slow.view('g:3', 1)).toEqual({ src: null, pending: true });
    });

    // --- Ajouts (hors intitulés de la spec) ----------------------------------

    it('une garde avancée par une réémission réarme la demande', async () => {
        const { loader, requests } = harness(() => 200);

        // `round.scheduled` au début de la révélation : garde à 6 s.
        loader.request('g:2', ref(1, 6000));
        await vi.advanceTimersByTimeAsync(500);

        // « Manche suivante » (60 § 5.4) : réémission, garde avancée à 1,5 s,
        // URL nouvelle. La demande part à la nouvelle garde, jamais avant.
        loader.request('g:2', ref(1, 1500, 'b'));
        await vi.advanceTimersByTimeAsync(999);
        expect(requests).toHaveLength(0);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests.map((each) => [each.serverMs, each.url])).toEqual([
            [1500, ref(1, 1500, 'b').url],
        ]);

        await vi.advanceTimersByTimeAsync(6000);
        expect(requests).toHaveLength(1);

        // Une garde reculée n'avance rien : la demande attend la nouvelle.
        loader.request('g:3', ref(1, 9000));
        loader.request('g:3', ref(1, 10_000, 'b'));
        await vi.advanceTimersByTimeAsync(ORIGIN_MS + 9999 - Date.now());
        expect(requests).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(requests.at(-1)?.serverMs).toBe(10_000);
    });

    it("ne garde d'une manche close que ses paliers ouverts", async () => {
        const { loader, requests, revoked } = harness(() => 200);

        loader.request('g:1', ref(1, 0));
        loader.request('g:1', ref(2, 8000));
        await vi.advanceTimersByTimeAsync(0);
        expect(requests).toHaveLength(1);

        // Fin anticipée à 5 s : le palier 2 ne s'ouvrira pas ; il n'est
        // jamais demandé à sa garde, et l'écran garde l'image du palier 1.
        await vi.advanceTimersByTimeAsync(5000);
        loader.retainTiers('g:1', [1]);
        await vi.advanceTimersByTimeAsync(10_000);

        expect(requests).toHaveLength(1);
        expect(revoked).toEqual([]);
        expect(loader.view('g:1', 1)).toEqual({
            src: 'blob:frame-1',
            pending: false,
        });

        // Manche annulée : plus rien à montrer, les blobs sont révoqués.
        loader.retainTiers('g:1', []);
        expect(revoked).toEqual(['blob:frame-1']);
        expect(loader.view('g:1', 1)).toEqual({ src: null, pending: true });
    });
});
