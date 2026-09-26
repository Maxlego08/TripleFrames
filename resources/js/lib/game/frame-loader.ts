import { parseIsoMs } from '@/lib/game/wire';
import type { TierImageRef } from '@/types/game-wire';

/**
 * Le chargeur des images de jeu (spec 60 § 7.6, contrat C8) : il transforme
 * les `TierImageRef` que le serveur envoie en URL d'objet locales, prêtes à
 * peindre, pour `GameFrame` (contrat C16 § 2.5).
 *
 * - **Jamais avant `fetchNotBefore`** (`Tᵢ − preload_lead_ms`), mesuré sur
 *   l'horloge serveur (`serverNow()`, § 2.4) et revérifié au réveil : une
 *   horloge corrigée à la baisse entre-temps ne fait pas partir la requête
 *   trop tôt. Le serveur refuse de toute façon avant la garde (C8) : la
 *   barrière est temporelle, pas le secret de l'URL.
 * - `fetch(url, { credentials: 'same-origin', cache: 'no-store' })` : le
 *   cookie du jeton d'invité part (le client ne le lit jamais, il est
 *   `HttpOnly`), pour la partie (3) du prédicat de service ; rien n'est mis
 *   en cache.
 * - Octets → blob → URL d'objet → `decode()` sur une image hors DOM, puis
 *   seulement l'URL passe à l'écran : aucun aplat entre deux paliers. Un
 *   décodage refusé n'empêche pas l'affichage (le cadre dira l'échec).
 * - **Nouvelle tentative** (C7 § 8) : un 404 — reçu avant `Tᵢ` quand
 *   l'horloge du client avance — est retenté après
 *   `max(FRAME_RETRY_DELAY_MS, fetchNotBefore − serverNow())`, jusqu'à
 *   `FRAME_MAX_ATTEMPTS` essais ; un 404 persistant rend le palier
 *   indisponible (`game.frame.unavailable`) et demande **une**
 *   resynchronisation. Une erreur réseau ou serveur suit la même règle. Un
 *   403 (signature expirée) demande une resynchronisation, qui rend une URL
 *   fraîche : le palier reste en attente, jamais indisponible. Un 429 attend
 *   (`Retry-After`, au moins `FRAME_RETRY_DELAY_MS`) puis retente **une**
 *   fois.
 * - Les blobs d'une manche sont gardés tant qu'elle est retenue — paliers
 *   ouverts compris jusqu'à la fin de sa révélation, qui les remontre (D14
 *   du 23/09) —, puis **révoqués** au changement de manche
 *   ({@link FrameLoader.retainOnly}) : des blobs accumulés sur un mobile
 *   modeste sont une fuite mémoire. Une requête d'une manche quittée est
 *   interrompue, et son blob tardif révoqué aussitôt. Une manche close ne
 *   garde que ses paliers ouverts ({@link FrameLoader.retainTiers}).
 *
 * Un palier se désigne par la clé de sa manche (`roundKeyOf()` du magasin)
 * et son `tierIndex`, jamais par son URL : une URL fraîchement signée pour
 * un palier déjà chargé ne refait aucune requête.
 *
 * Les deux constantes sont des **constantes de transport**, déclarées dans
 * ce seul module (60 § 7.6, § 19.2) : elles ne touchent ni palier, ni score,
 * ni chrono, et ne sont donc pas des valeurs de jeu au sens de la règle 2.
 */

/** Délai minimal avant une nouvelle tentative, en millisecondes. */
export const FRAME_RETRY_DELAY_MS = 250;

/** Nombre maximal d'essais d'une même image (404, réseau, serveur). */
export const FRAME_MAX_ATTEMPTS = 8;

/** Secondes → millisecondes, pour `Retry-After` : une unité, pas une valeur. */
const MS_PER_SECOND = 1000;

/** Ce que l'écran rend d'un palier. */
export type FrameView = {
    /** URL d'objet prête à peindre, ou nulle. */
    src: string | null;
    /**
     * Vrai tant que l'image est attendue (garde non franchie, chargement,
     * URL à renouveler) : le cadre montre le chargement, pas l'indisponibilité
     * (E39-1). Faux avec `src` nul : rien de servable.
     */
    pending: boolean;
};

/** La part d'une réponse HTTP que le chargeur lit. */
export interface FrameResponse {
    status: number;
    ok: boolean;
    headers: { get(name: string): string | null };
    blob(): Promise<Blob>;
}

export interface FrameLoaderOptions {
    /** L'instant serveur, en millisecondes (`serverNow()`). */
    now: () => number;
    /** Une requête d'image ; par défaut, `fetch` same-origin et sans cache. */
    fetchFrame?: (url: string, signal: AbortSignal) => Promise<FrameResponse>;
    createObjectUrl?: (blob: Blob) => string;
    revokeObjectUrl?: (url: string) => void;
    /** Décodage hors DOM d'une URL d'objet ; par défaut, `HTMLImageElement.decode()`. */
    decode?: (objectUrl: string) => Promise<void>;
    /** Un palier ne peut plus se charger sans un état frais du serveur. */
    onResyncNeeded?: (reason: 'frame_unavailable' | 'frame_expired') => void;
}

export interface FrameLoader {
    /**
     * Demande le palier `ref.tierIndex` de la manche `roundKey`. Idempotent :
     * un palier chargé ou en cours n'est pas redemandé ; une URL nouvelle
     * relance un palier en échec ou expiré.
     */
    request: (roundKey: string, ref: TierImageRef) => void;
    /**
     * Ce que l'écran montre au palier `tierIndex` : son image si elle est
     * prête ; sinon, en attente, la dernière image prête d'un palier
     * antérieur de la même manche (aucun aplat entre deux paliers) ; sinon
     * le chargement, ou l'indisponibilité si le palier a échoué.
     */
    view: (roundKey: string, tierIndex: number) => FrameView;
    /** Révoque tout ce qui n'appartient pas aux manches `roundKeys`. */
    retainOnly: (roundKeys: readonly string[]) => void;
    /**
     * Dans la manche `roundKey`, révoque et interrompt tout palier hors de
     * `tierIndexes` : une manche close ne garde que ses paliers ouverts
     * (60 § 7.2, D14 du 23/09), une manche annulée aucun. Sans cela, le
     * palier suivant d'une fin anticipée serait demandé à sa garde, refusé
     * `FRAME_MAX_ATTEMPTS` fois, puis relu par tout le salon.
     */
    retainTiers: (roundKey: string, tierIndexes: readonly number[]) => void;
    subscribe: (listener: () => void) => () => void;
    /** Numéro de version, incrémenté à chaque changement (`useSyncExternalStore`). */
    version: () => number;
}

type SlotStatus = 'waiting' | 'loading' | 'ready' | 'expired' | 'unavailable';

type Slot = {
    url: string;
    fetchNotBeforeMs: number;
    status: SlotStatus;
    objectUrl: string | null;
    attempts: number;
    rateLimited: boolean;
    timer: ReturnType<typeof setTimeout> | null;
    controller: AbortController | null;
};

function defaultFetchFrame(
    url: string,
    signal: AbortSignal,
): Promise<FrameResponse> {
    return fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        signal,
    });
}

function defaultDecode(objectUrl: string): Promise<void> {
    const image = new Image();

    image.decoding = 'async';
    image.src = objectUrl;

    return image.decode();
}

/** `Retry-After` en millisecondes (secondes entières), ou nul. */
function retryAfterMs(response: FrameResponse): number | null {
    const header = response.headers.get('Retry-After');

    if (header === null || !/^\d+$/.test(header.trim())) {
        return null;
    }

    return Number(header.trim()) * MS_PER_SECOND;
}

export function createFrameLoader(options: FrameLoaderOptions): FrameLoader {
    const { now } = options;
    const fetchFrame = options.fetchFrame ?? defaultFetchFrame;
    const createObjectUrl =
        options.createObjectUrl ?? ((blob: Blob) => URL.createObjectURL(blob));
    const revokeObjectUrl =
        options.revokeObjectUrl ?? ((url: string) => URL.revokeObjectURL(url));
    const decode = options.decode ?? defaultDecode;

    /** Manche → palier → emplacement. */
    const rounds = new Map<string, Map<number, Slot>>();
    const listeners = new Set<() => void>();
    let current = 0;

    function notify(): void {
        current += 1;

        for (const listener of listeners) {
            listener();
        }
    }

    function isLive(roundKey: string, tierIndex: number, slot: Slot): boolean {
        return rounds.get(roundKey)?.get(tierIndex) === slot;
    }

    function clear(slot: Slot): void {
        if (slot.timer !== null) {
            clearTimeout(slot.timer);
            slot.timer = null;
        }

        slot.controller?.abort();
        slot.controller = null;

        if (slot.objectUrl !== null) {
            revokeObjectUrl(slot.objectUrl);
            slot.objectUrl = null;
        }
    }

    function later(
        roundKey: string,
        tierIndex: number,
        slot: Slot,
        delayMs: number,
    ): void {
        slot.timer = setTimeout(
            () => {
                slot.timer = null;
                attempt(roundKey, tierIndex, slot);
            },
            Math.max(0, delayMs),
        );
    }

    function settle(slot: Slot, status: SlotStatus): void {
        slot.status = status;
        slot.controller = null;
        notify();
    }

    /** Un nouvel essai, ou l'abandon si les essais sont épuisés. */
    function retryOrGiveUp(
        roundKey: string,
        tierIndex: number,
        slot: Slot,
    ): void {
        if (slot.attempts >= FRAME_MAX_ATTEMPTS) {
            settle(slot, 'unavailable');
            options.onResyncNeeded?.('frame_unavailable');

            return;
        }

        slot.status = 'waiting';
        slot.controller = null;
        later(
            roundKey,
            tierIndex,
            slot,
            Math.max(FRAME_RETRY_DELAY_MS, slot.fetchNotBeforeMs - now()),
        );
    }

    function attempt(roundKey: string, tierIndex: number, slot: Slot): void {
        if (!isLive(roundKey, tierIndex, slot)) {
            return;
        }

        // Jamais avant la garde, revérifiée sur l'horloge du moment.
        const waitMs = slot.fetchNotBeforeMs - now();

        if (waitMs > 0) {
            later(roundKey, tierIndex, slot, waitMs);

            return;
        }

        const controller = new AbortController();
        const url = slot.url;

        slot.status = 'loading';
        slot.attempts += 1;
        slot.controller = controller;

        void fetchFrame(url, controller.signal)
            .then(async (response) => {
                if (!isLive(roundKey, tierIndex, slot)) {
                    return;
                }

                if (response.ok) {
                    const objectUrl = createObjectUrl(await response.blob());

                    await decode(objectUrl).catch(() => undefined);

                    if (!isLive(roundKey, tierIndex, slot)) {
                        revokeObjectUrl(objectUrl);

                        return;
                    }

                    slot.objectUrl = objectUrl;
                    settle(slot, 'ready');

                    return;
                }

                // Une URL plus fraîche est arrivée pendant le vol : c'est elle
                // qu'on essaie, sans attendre.
                if (slot.url !== url) {
                    slot.status = 'waiting';
                    slot.controller = null;
                    attempt(roundKey, tierIndex, slot);

                    return;
                }

                if (response.status === 403) {
                    settle(slot, 'expired');
                    options.onResyncNeeded?.('frame_expired');

                    return;
                }

                if (response.status === 429) {
                    if (slot.rateLimited) {
                        settle(slot, 'unavailable');

                        return;
                    }

                    slot.rateLimited = true;
                    slot.attempts -= 1;
                    slot.status = 'waiting';
                    slot.controller = null;
                    later(
                        roundKey,
                        tierIndex,
                        slot,
                        Math.max(
                            FRAME_RETRY_DELAY_MS,
                            retryAfterMs(response) ?? 0,
                        ),
                    );

                    return;
                }

                retryOrGiveUp(roundKey, tierIndex, slot);
            })
            .catch(() => {
                if (
                    controller.signal.aborted ||
                    !isLive(roundKey, tierIndex, slot)
                ) {
                    return;
                }

                if (slot.url !== url) {
                    slot.status = 'waiting';
                    slot.controller = null;
                    attempt(roundKey, tierIndex, slot);

                    return;
                }

                retryOrGiveUp(roundKey, tierIndex, slot);
            });
    }

    function start(roundKey: string, tierIndex: number, slot: Slot): void {
        notify();
        attempt(roundKey, tierIndex, slot);
    }

    return {
        request(roundKey: string, ref: TierImageRef): void {
            let tiers = rounds.get(roundKey);

            if (tiers === undefined) {
                tiers = new Map();
                rounds.set(roundKey, tiers);
            }

            const known = tiers.get(ref.tierIndex);

            if (known !== undefined) {
                const failed =
                    known.status === 'expired' ||
                    known.status === 'unavailable';

                if (known.status === 'ready' || known.url === ref.url) {
                    return;
                }

                if (!failed) {
                    // En attente ou en vol : l'URL la plus fraîche servira au
                    // prochain essai, sans interrompre celui qui court.
                    const nextNotBeforeMs = parseIsoMs(ref.fetchNotBefore);
                    const earlier = nextNotBeforeMs < known.fetchNotBeforeMs;

                    known.url = ref.url;
                    known.fetchNotBeforeMs = nextNotBeforeMs;

                    // Une garde avancée (« manche suivante », 60 § 5.4) avant
                    // tout essai : le réveil armé sur l'ancienne garde est
                    // réarmé sur la nouvelle, jamais avant elle. L'attente
                    // d'une nouvelle tentative ou d'un `Retry-After` n'est
                    // jamais raccourcie.
                    if (
                        earlier &&
                        known.status === 'waiting' &&
                        known.timer !== null &&
                        known.attempts === 0 &&
                        !known.rateLimited
                    ) {
                        clearTimeout(known.timer);
                        known.timer = null;
                        attempt(roundKey, ref.tierIndex, known);
                    }

                    return;
                }

                clear(known);
            }

            const slot: Slot = {
                url: ref.url,
                fetchNotBeforeMs: parseIsoMs(ref.fetchNotBefore),
                status: 'waiting',
                objectUrl: null,
                attempts: 0,
                rateLimited: false,
                timer: null,
                controller: null,
            };

            tiers.set(ref.tierIndex, slot);
            start(roundKey, ref.tierIndex, slot);
        },

        view(roundKey: string, tierIndex: number): FrameView {
            const tiers = rounds.get(roundKey);
            const slot = tiers?.get(tierIndex);

            if (slot?.status === 'ready') {
                return { src: slot.objectUrl, pending: false };
            }

            if (slot?.status === 'unavailable') {
                return { src: null, pending: false };
            }

            let previous: Slot | null = null;
            let previousTier = 0;

            for (const [index, each] of tiers?.entries() ?? []) {
                if (
                    index < tierIndex &&
                    index > previousTier &&
                    each.status === 'ready'
                ) {
                    previous = each;
                    previousTier = index;
                }
            }

            return previous === null
                ? { src: null, pending: true }
                : { src: previous.objectUrl, pending: false };
        },

        retainOnly(roundKeys: readonly string[]): void {
            let changed = false;

            for (const [roundKey, tiers] of rounds) {
                if (roundKeys.includes(roundKey)) {
                    continue;
                }

                for (const slot of tiers.values()) {
                    clear(slot);
                }

                rounds.delete(roundKey);
                changed = true;
            }

            if (changed) {
                notify();
            }
        },

        retainTiers(roundKey: string, tierIndexes: readonly number[]): void {
            const tiers = rounds.get(roundKey);

            if (tiers === undefined) {
                return;
            }

            let changed = false;

            for (const [tierIndex, slot] of tiers) {
                if (tierIndexes.includes(tierIndex)) {
                    continue;
                }

                clear(slot);
                tiers.delete(tierIndex);
                changed = true;
            }

            if (changed) {
                notify();
            }
        },

        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },

        version: () => current,
    };
}
