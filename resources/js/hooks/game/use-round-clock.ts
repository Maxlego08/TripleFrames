import { useEffect, useState, useSyncExternalStore } from 'react';
import { currentTier, toLiveTimeline } from '@/lib/game/round-timeline';
import type { LiveRoundTimeline } from '@/lib/game/round-timeline';
import { serverNow, subscribeServerClock } from '@/lib/game/server-clock';
import { displayedRound } from '@/lib/game/store';
import type { GameStoreState } from '@/lib/game/store';
import { parseIsoMs } from '@/lib/game/wire';
import type { RoundState } from '@/types/game-wire';
import type { TierWindow } from '@/types/scoring';

/**
 * L'horloge d'affichage d'une manche (spec 60 § 2.4 à § 2.6, contrat C16
 * § 2.6) : quelle manche l'écran montre, son palier courant et son temps
 * restant, à l'instant serveur resynchronisé.
 *
 * Tout le calcul vient de `lib/game/round-timeline.ts` (90), **seule**
 * implémentation client du palier courant ; ce hook ne fait que le cadencer.
 * Il re-rend l'écran à la seconde de la manche (`startsAt + k × 1 s` : les
 * fenêtres de palier et `D` sont en secondes entières), à la fin d'une
 * révélation et au début de la manche suivante, et se recale à chaque
 * correction de l'horloge serveur.
 *
 * **Ne décide rien** (règle 8 reformulée) : la bascule affichée de l'image
 * et de la valeur suit `currentTier()` ; la clôture, elle, n'arrive que par
 * le serveur (`round.closed`, ou un paquet en phase `closed`) — `timeline.
 * closed` n'est jamais posé par ce minuteur. Aucune saisie ne se ferme ici.
 *
 * `useSyncExternalStore` sur un instantané **numérique stable** (l'instant
 * serveur pris au dernier réveil) : React Compiler ne mémorise jamais un
 * `serverNow()` lu pendant le rendu, et le double montage de `strictMode`
 * n'arme jamais deux minuteurs à la fois.
 */

export type RoundClockView = {
    /** Instant serveur du dernier réveil, en millisecondes. */
    nowMs: number;
    /** La manche que l'écran montre (`displayedRound()` du magasin). */
    round: RoundState | null;
    /**
     * Sa chronologie, pour `useRoundAnnouncements` ; nulle hors partie, pour
     * une manche annulée, ou quand aucune manche n'est montrée. `closed` est
     * vrai dès que le serveur a clos la manche.
     */
    timeline: LiveRoundTimeline | null;
    /** Le palier dont la fenêtre contient `nowMs`, ou nul. */
    tier: TierWindow | null;
    /** Millisecondes jusqu'à `D` (manche ouverte) ou `T₁` (décompte), sinon nul. */
    remainingMs: number | null;
};

/** Cadence d'affichage du chrono : la seconde, une unité et non une valeur de jeu. */
const MS_PER_SECOND = 1000;

/** L'instant serveur où l'écran d'une manche change d'aspect, après `nowMs`. */
function nextTickMs(state: GameStoreState, nowMs: number): number | null {
    const round = displayedRound(state, nowMs);

    // Pause manuelle (D64 du 07/10) : le temps restant de l'écran de pause
    // change à chaque seconde avant l'échéance — affichage seulement.
    if (
        round === null &&
        state.status === 'paused' &&
        state.pause?.kind === 'manual'
    ) {
        const deadline = parseIsoMs(state.pause.interruptsAt);
        const next =
            deadline -
            (Math.ceil((deadline - nowMs) / MS_PER_SECOND) - 1) * MS_PER_SECOND;

        return deadline > nowMs && next > nowMs ? next : null;
    }

    if (round === null) {
        return null;
    }

    const origin = parseIsoMs(round.startsAt);
    const candidates = [
        origin +
            (Math.floor((nowMs - origin) / MS_PER_SECOND) + 1) * MS_PER_SECOND,
    ];

    if (round.revealEndsAt !== null) {
        candidates.push(parseIsoMs(round.revealEndsAt));
    }

    for (const other of state.rounds) {
        candidates.push(parseIsoMs(other.startsAt));
    }

    const future = candidates.filter((instant) => instant > nowMs);

    return future.length === 0 ? null : Math.min(...future);
}

/** Minuteur d'affichage : un seul réveil programmé, recalé sur l'horloge. */
type RoundTicker = {
    track: (state: GameStoreState) => void;
    subscribe: (listener: () => void) => () => void;
    snapshot: () => number;
};

function createRoundTicker(): RoundTicker {
    let nowMs = serverNow();
    let tracked: GameStoreState | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;
    const listeners = new Set<() => void>();

    function tick(): void {
        nowMs = serverNow();

        for (const listener of listeners) {
            listener();
        }

        arm();
    }

    function arm(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        if (listeners.size === 0 || tracked === null) {
            return;
        }

        const next = nextTickMs(tracked, serverNow());

        if (next !== null) {
            timer = setTimeout(tick, Math.max(0, next - serverNow()));
        }
    }

    let unsubscribeClock: (() => void) | null = null;

    return {
        track(state: GameStoreState): void {
            tracked = state;
            tick();
        },

        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            if (listeners.size === 1) {
                unsubscribeClock = subscribeServerClock(tick);
            }

            arm();

            return () => {
                listeners.delete(listener);

                if (listeners.size === 0) {
                    unsubscribeClock?.();
                    unsubscribeClock = null;
                    arm();
                }
            };
        },

        snapshot: () => nowMs,
    };
}

export function useRoundClock(state: GameStoreState): RoundClockView {
    const [ticker] = useState(createRoundTicker);

    useEffect(() => ticker.track(state), [ticker, state]);

    const nowMs = useSyncExternalStore(ticker.subscribe, ticker.snapshot);
    const round = state.gameRef === null ? null : displayedRound(state, nowMs);

    if (round === null || state.gameRef === null) {
        return {
            nowMs,
            round: null,
            timeline: null,
            tier: null,
            remainingMs: null,
        };
    }

    const timeline =
        round.phase === 'cancelled'
            ? null
            : toLiveTimeline(
                  state.gameRef,
                  round,
                  round.phase === 'closed' || round.phase === 'revealing',
              );
    const origin = parseIsoMs(round.startsAt);
    const remainingMs =
        round.phase === 'scheduled'
            ? Math.max(0, origin - nowMs)
            : round.phase === 'running' && round.endedAt === null
              ? Math.max(0, origin + round.durationMs - nowMs)
              : null;

    return {
        nowMs,
        round,
        timeline,
        tier: timeline === null ? null : currentTier(timeline, nowMs),
        remainingMs,
    };
}
