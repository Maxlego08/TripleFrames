import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import GamePauseController from '@/actions/App/Http/Controllers/Game/GamePauseController';
import SoloPauseController from '@/actions/App/Http/Controllers/Game/SoloPauseController';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { readPauseGestureFailure } from '@/lib/game/pause-gesture';
import type {
    PauseGestureFailure,
    PauseGestureKind,
} from '@/lib/game/pause-gesture';
import { isGameStatePacket, seatTokenHeaders } from '@/lib/game/store';
import type { GameStore } from '@/lib/game/store';
import type { GameStatePacket } from '@/types/game-wire';

export type UsePauseGestureOptions = {
    /** Le magasin de la page (`useGameState`). */
    store: GameStore;
    /** Code du salon pour le multijoueur ; nul en solo. */
    roomCode: string | null;
    /**
     * Clé de l'état que l'échec décrit (partie et statut) : un échec ne
     * survit pas à un changement d'état. Nulle : rien ne part.
     */
    stateKey: string | null;
};

export type PauseGesture = {
    /** Envoie un geste ; un seul à la fois. */
    run: (kind: PauseGestureKind) => void;
    /** Le geste en vol, ou nul. */
    pending: PauseGestureKind | null;
    /** Dernier échec, déjà traduit, sur l'état montré ; nul sinon. */
    error: string | null;
};

/** Corps vide : la route lit le siège et sa partie sur `seat.active`. */
type PauseGestureBody = Record<string, never>;

type Failure = { key: string; message: string };

/** Les messages des refus, par code (clés `game.pause.errors.*`). */
const REFUSALS = {
    not_running: 'game.pause.errors.not_running',
    no_round_left: 'game.pause.errors.no_round_left',
    budget_exhausted: 'game.pause.errors.budget_exhausted',
    draining: 'game.pause.errors.draining',
} as const;

/**
 * Les gestes de la pause manuelle (D64 du 07/10, spec 60 § 14) — « Pause »,
 * « Annuler la pause », « Reprendre » —, adressés par Wayfinder
 * (`room.game.pause|pause.cancel|resume` au salon, `solo.pause|pause.cancel|
 * resume` en solo) et envoyés par `useHttp()` sous l'en-tête `X-Seat-Token`.
 * **Le serveur décide seul** : autorité relue sous le verrou du salon, phase
 * relue après rattrapage, budget, drainage.
 *
 * - **Salon, 204** : rien ici — `game.pause_requested`, `game.paused`,
 *   `game.pause_request_cancelled` ou `game.resumed` (puis
 *   `round.scheduled`) portent l'état à tous les sièges.
 * - **Solo, 200** : le `GameStatePacket` à jour, appliqué au magasin.
 * - **Échec** (`readPauseGestureFailure`) : rendu sous le bouton et annoncé
 *   dans l'unique région vivante (`announce()`, C16 § 4) ; l'état est relu,
 *   l'écran étant en retard sur le serveur.
 */
export function usePauseGesture(options: UsePauseGestureOptions): PauseGesture {
    const { store, roomCode, stateKey } = options;
    const { t } = useTranslations();
    const http = useHttp<PauseGestureBody, GameStatePacket | null>({});
    const inFlight = useRef(false);
    const [pending, setPending] = useState<PauseGestureKind | null>(null);
    const [failure, setFailure] = useState<Failure | null>(null);

    const messageFor = (reason: PauseGestureFailure): string | null => {
        switch (reason) {
            case 'not_running':
            case 'no_round_left':
            case 'budget_exhausted':
            case 'draining':
                return t(REFUSALS[reason]);
            case 'superseded':
                return t('game.errors.seat_superseded');
            case 'cancelled':
                return null;
            case 'failed':
                return typeof navigator !== 'undefined' && !navigator.onLine
                    ? t('common.connection.offline')
                    : t('game.pause.errors.failed');
        }
    };

    const route = (kind: PauseGestureKind) => {
        if (roomCode === null) {
            switch (kind) {
                case 'pause':
                    return SoloPauseController.pause();
                case 'cancel':
                    return SoloPauseController.cancel();
                case 'resume':
                    return SoloPauseController.resume();
            }
        }

        switch (kind) {
            case 'pause':
                return GamePauseController.pause({ room: roomCode });
            case 'cancel':
                return GamePauseController.cancel({ room: roomCode });
            case 'resume':
                return GamePauseController.resume({ room: roomCode });
        }
    };

    const run = (kind: PauseGestureKind): void => {
        if (stateKey === null || inFlight.current) {
            return;
        }

        const current = stateKey;
        const solo = roomCode === null;

        inFlight.current = true;
        setPending(kind);
        setFailure(null);

        http.submit(route(kind), { headers: seatTokenHeaders() })
            .then((response: unknown) => {
                if (!solo) {
                    return;
                }

                if (isGameStatePacket(response)) {
                    store.applyPacket(response);

                    return;
                }

                store.requestResync('solo_poll');
            })
            .catch((error: unknown) => {
                const reason = readPauseGestureFailure(error);

                if (reason !== 'cancelled' && reason !== 'superseded') {
                    store.requestResync('pause_refused');
                }

                const message = messageFor(reason);

                if (message !== null) {
                    setFailure({ key: current, message });
                    announce(message);
                }
            })
            .finally(() => {
                inFlight.current = false;
                setPending(null);
            });
    };

    return {
        run,
        pending,
        error:
            failure !== null && failure.key === stateKey
                ? failure.message
                : null,
    };
}
