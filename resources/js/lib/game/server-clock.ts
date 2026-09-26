import { parseIsoMs } from '@/lib/game/wire';
import { show as clockShow } from '@/routes/clock';
import type { IsoMs } from '@/types/game-wire';

/**
 * L'horloge serveur vue du navigateur (spec 60 § 2.4, contrat C7 § 2.6),
 * **pour l'affichage seulement** (L3, A-21).
 *
 * Elle mesure le décalage `offset` entre l'horloge du navigateur et celle du
 * serveur, pour que la chronologie d'une manche (`round-timeline.ts`, C16)
 * s'affiche sur l'instant serveur : `serverNow()` = `Date.now() + offset`.
 * Rien d'autre n'en dépend. Le client n'envoie jamais ce décalage, ni un
 * horodatage, ni un RTT (contrat C10 L3) ; il n'ouvre aucun palier, ne clôt
 * aucune manche et ne ferme aucune saisie sur son chrono (§ 2.6) : le serveur
 * juge chaque soumission à son propre instant de réception.
 *
 * 1. **Poignée de main** — au montage d'une page `game/*`, à chaque
 *    reconnexion d'Echo et à chaque retour de visibilité (branchés par les
 *    hooks de jeu) : `clockSamples` requêtes `GET clock.show`, **l'une après
 *    l'autre** ; pour chacune, `offset = serverNow − (t₀ + t₁) / 2` ; le
 *    décalage retenu est la **médiane**, arrondie à la milliseconde. Un
 *    échantillon perdu (réseau, 429, réponse illisible) est écarté ; sans
 *    aucun échantillon, le décalage courant est gardé. Une poignée de main
 *    plus récente supplante celle qui court encore.
 * 2. **Recalage** — sur chaque `serverNow` reçu (événement ou paquet) : un
 *    message ne pouvant arriver avant d'avoir été émis,
 *    `serverNow − arrivéeLocale` est un minorant du décalage ; s'il dépasse le
 *    décalage courant, celui-ci est **relevé** à cette valeur. Un événement ne
 *    l'abaisse jamais ; seule une nouvelle poignée de main le fait.
 * 3. `serverNow()`, lu par tous les modules de `lib/game/` et par
 *    `round-timeline.ts`.
 *
 * Tout est en millisecondes entières depuis l'époque Unix. Le cœur,
 * {@link createServerClock}, ne touche ni au réseau ni à `Date` : l'horloge
 * locale et le transport lui sont passés, ce qui le rend testable sans DOM
 * (C18 § 2.4). Le module expose en plus l'horloge de la page, branchée sur
 * `Date.now()` et sur `clock.show`.
 */

/** L'horloge locale, en millisecondes depuis l'époque Unix. */
export type LocalClock = () => number;

/**
 * Un aller-retour vers l'horloge serveur : l'instant reçu, ou `null` si
 * l'échantillon est perdu. Ne lève jamais d'elle-même ; une promesse rejetée
 * est traitée comme un échantillon perdu.
 */
export type ClockTransport = () => Promise<IsoMs | null>;

/** Une horloge serveur : décalage courant, lecture, poignée de main, recalage. */
export interface ServerClock {
    /** `localNow() + offset` : l'instant serveur estimé, pour l'affichage. */
    now(): number;
    /** Le décalage courant, en millisecondes entières (0 avant toute mesure). */
    offsetMs(): number;
    /**
     * Mesure `samples` échantillons et retient leur médiane. Rend `true` si
     * le décalage a été mesuré, `false` si aucun échantillon n'a abouti ou si
     * une poignée de main plus récente l'a supplantée.
     */
    handshake(samples: number): Promise<boolean>;
    /**
     * Relève le décalage sur l'enveloppe d'un message reçu à `arrivedAtMs`
     * (par défaut, maintenant) ; ne l'abaisse jamais. Un instant illisible
     * est ignoré.
     */
    recalibrate(serverNow: IsoMs, arrivedAtMs?: number): void;
    /** Abonnement aux changements de décalage (`useSyncExternalStore`). */
    subscribe(listener: () => void): () => void;
    /** Oublie le décalage et abandonne toute poignée de main en cours. */
    reset(): void;
}

/**
 * Décalage d'un échantillon : l'instant serveur moins le milieu de
 * l'aller-retour local.
 */
export function sampleOffsetMs(
    sentAtMs: number,
    serverNowMs: number,
    receivedAtMs: number,
): number {
    return serverNowMs - (sentAtMs + receivedAtMs) / 2;
}

/**
 * Médiane d'une liste non vide : la valeur du milieu, ou la moyenne des deux
 * valeurs du milieu pour un nombre pair d'échantillons.
 */
export function medianMs(values: readonly number[]): number {
    const sorted = [...values].sort((left, right) => left - right);
    const middle = Math.floor(sorted.length / 2);

    return sorted.length % 2 === 1
        ? sorted[middle]
        : (sorted[middle - 1] + sorted[middle]) / 2;
}

/** L'instant d'un échantillon, ou `null` s'il est perdu ou illisible. */
async function sampleServerNowMs(
    transport: ClockTransport,
): Promise<number | null> {
    try {
        const serverNow = await transport();

        return serverNow === null ? null : parseIsoMs(serverNow);
    } catch {
        return null;
    }
}

export function createServerClock(
    localNow: LocalClock,
    transport: ClockTransport,
): ServerClock {
    let offset = 0;
    let generation = 0;
    const listeners = new Set<() => void>();

    function apply(next: number): void {
        if (next === offset) {
            return;
        }

        offset = next;

        for (const listener of listeners) {
            listener();
        }
    }

    return {
        now: () => localNow() + offset,

        offsetMs: () => offset,

        async handshake(samples: number): Promise<boolean> {
            generation += 1;
            const current = generation;
            const offsets: number[] = [];

            for (let index = 0; index < samples; index += 1) {
                const sentAtMs = localNow();
                const serverNowMs = await sampleServerNowMs(transport);
                const receivedAtMs = localNow();

                if (current !== generation) {
                    return false;
                }

                if (serverNowMs !== null) {
                    offsets.push(
                        sampleOffsetMs(sentAtMs, serverNowMs, receivedAtMs),
                    );
                }
            }

            if (offsets.length === 0) {
                return false;
            }

            apply(Math.round(medianMs(offsets)));

            return true;
        },

        recalibrate(serverNow: IsoMs, arrivedAtMs: number = localNow()): void {
            let serverNowMs: number;

            try {
                serverNowMs = parseIsoMs(serverNow);
            } catch {
                return;
            }

            const lowerBound = serverNowMs - arrivedAtMs;

            if (lowerBound > offset) {
                apply(lowerBound);
            }
        },

        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },

        reset(): void {
            generation += 1;
            apply(0);
        },
    };
}

/** Corps attendu de `clock.show` : `{ serverNow: IsoMs }`. */
function isClockBody(body: unknown): body is { serverNow: IsoMs } {
    return (
        typeof body === 'object' &&
        body !== null &&
        'serverNow' in body &&
        typeof body.serverNow === 'string'
    );
}

/**
 * Un échantillon réel : `GET clock.show`, sans cache (un instant servi depuis
 * un cache fausserait le décalage d'autant), avec les cookies du site pour
 * que `throttle:game-read` compte par jeton.
 */
async function fetchClockServerNow(): Promise<IsoMs | null> {
    const route = clockShow();
    const response = await fetch(route.url, {
        method: route.method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
        return null;
    }

    const body: unknown = await response.json();

    return isClockBody(body) ? body.serverNow : null;
}

/** L'horloge de la page : `Date.now()` et `clock.show`. */
const pageClock = createServerClock(() => Date.now(), fetchClockServerNow);

/** L'instant serveur estimé, en millisecondes depuis l'époque Unix. */
export function serverNow(): number {
    return pageClock.now();
}

/** Le décalage courant de l'horloge de la page, en millisecondes entières. */
export function serverClockOffsetMs(): number {
    return pageClock.offsetMs();
}

/** Poignée de main de l'horloge de la page, sur `clockSamples` échantillons. */
export function handshakeServerClock(samples: number): Promise<boolean> {
    return pageClock.handshake(samples);
}

/** Recalage de l'horloge de la page sur le `serverNow` d'un message reçu. */
export function recalibrateServerClock(
    serverNowIso: IsoMs,
    arrivedAtMs?: number,
): void {
    pageClock.recalibrate(serverNowIso, arrivedAtMs);
}

/** Abonnement aux changements de décalage de l'horloge de la page. */
export function subscribeServerClock(listener: () => void): () => void {
    return pageClock.subscribe(listener);
}

/** Oublie le décalage de l'horloge de la page (démontage, tests). */
export function resetServerClock(): void {
    pageClock.reset();
}
