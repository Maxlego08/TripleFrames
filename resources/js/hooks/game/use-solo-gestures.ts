import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import SoloRoundController from '@/actions/App/Http/Controllers/Game/SoloRoundController';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { readSoloGestureFailure } from '@/lib/game/solo-gestures';
import type {
    SoloGestureFailure,
    SoloGestureKind,
} from '@/lib/game/solo-gestures';
import {
    isGameStatePacket,
    roundKeyOf,
    seatTokenHeaders,
} from '@/lib/game/store';
import type { GameStore } from '@/lib/game/store';
import type { GameStatePacket } from '@/types/game-wire';

export type UseSoloGesturesOptions = {
    /** Le magasin de la page (`useGameState`), qui applique chaque paquet. */
    store: GameStore;
    /** `gameRef` de la partie suivie (`state.gameRef`) ; nul : rien ne part. */
    gameRef: string | null;
    /**
     * `sequenceIndex` de la manche que l'écran montre : l'échec d'un geste
     * n'appartient qu'à elle, et s'efface avec elle. Nul : rien ne part.
     */
    sequenceIndex: number | null;
};

/** Le dernier échec d'un geste, déjà traduit. */
export type SoloGestureError = { kind: SoloGestureKind; message: string };

export type SoloGestures = {
    /** Envoie un geste ; un seul à la fois, tous gestes confondus. */
    run: (kind: SoloGestureKind) => void;
    /** Le geste en vol, ou nul. */
    pending: SoloGestureKind | null;
    /** Dernier échec sur la manche montrée, ou nul. */
    error: SoloGestureError | null;
};

/** Corps vide : la route lit le siège et sa partie sur `seat.active`. */
type SoloGestureBody = Record<string, never>;

/** Un échec, rattaché à la manche où il a eu lieu. */
type Failure = SoloGestureError & { key: string };

/** Les routes des trois gestes, par Wayfinder (60 § 10.1). */
const ACTIONS = {
    reveal: SoloRoundController.reveal,
    skip: SoloRoundController.skip,
    next: SoloRoundController.next,
} as const;

/**
 * Les gestes du joueur solo (spec 60 § 5.4 et § 16.5, D18 du 23/09) —
 * « Voir la réponse » (`solo.reveal`), « Passer la manche » (`solo.skip`) et
 * « Manche suivante » (`solo.next`) —, adressés par Wayfinder et envoyés par
 * `useHttp()` sous l'en-tête `X-Seat-Token` (`seatTokenHeaders()`, que
 * `useGameState` pose aussi sur toute requête). **Le serveur décide seul** :
 * il rattrape la partie, relit la phase sous verrou, et chaque geste vaut
 * battement.
 *
 * - **200** : le `GameStatePacket` à jour, appliqué au magasin
 *   (`store.applyPacket`) — c'est la relecture « après chaque geste » du
 *   § 16.4 ; un corps illisible fait relire l'état.
 * - **Échec** (`readSoloGestureFailure`) : rendu par la page et annoncé dans
 *   l'unique région vivante (`announce()`, C16 § 4), jamais la fenêtre
 *   d'erreur brute d'Inertia — `round_not_running` →
 *   `game.errors.round_not_running`, `not_revealing` →
 *   `game.errors.not_revealing` (l'écran était en retard : l'état est relu),
 *   onglet supplanté → `game.errors.seat_superseded` (la relecture est
 *   laissée à l'écouteur de `use-game-state`), 403 → relecture, qui fait
 *   quitter la page ; tout le reste → `common.connection.offline` hors
 *   ligne, `common.state.error` sinon.
 * - Un geste à la fois ; l'échec n'appartient qu'à la manche montrée (clé
 *   `roundKeyOf`), comme les verdicts de `useAnswerSubmission`.
 */
export function useSoloGestures(options: UseSoloGesturesOptions): SoloGestures {
    const { store, gameRef, sequenceIndex } = options;
    const { t } = useTranslations();
    const http = useHttp<SoloGestureBody, GameStatePacket>({});
    const inFlight = useRef(false);
    const [pending, setPending] = useState<SoloGestureKind | null>(null);
    const [failure, setFailure] = useState<Failure | null>(null);

    const key =
        gameRef === null || sequenceIndex === null
            ? null
            : roundKeyOf(gameRef, sequenceIndex);

    const messageFor = (kind: SoloGestureFailure): string | null => {
        switch (kind) {
            case 'round_not_running':
                return t('game.errors.round_not_running');
            case 'not_revealing':
                return t('game.errors.not_revealing');
            case 'superseded':
                return t('game.errors.seat_superseded');
            case 'lost':
            case 'cancelled':
                return null;
            case 'failed':
                return typeof navigator !== 'undefined' && !navigator.onLine
                    ? t('common.connection.offline')
                    : t('common.state.error');
        }
    };

    const run = (kind: SoloGestureKind): void => {
        if (key === null || inFlight.current) {
            return;
        }

        const current = key;

        inFlight.current = true;
        setPending(kind);
        setFailure(null);

        http.submit(ACTIONS[kind](), { headers: seatTokenHeaders() })
            .then((response: unknown) => {
                if (isGameStatePacket(response)) {
                    store.applyPacket(response);

                    return;
                }

                store.requestResync('solo_poll');
            })
            .catch((error: unknown) => {
                const reason = readSoloGestureFailure(error);

                // L'écran était en retard sur le serveur, ou le siège n'est
                // plus tenu : l'état est relu. L'onglet supplanté est relu
                // par l'écouteur d'erreur de `use-game-state`.
                if (
                    reason === 'round_not_running' ||
                    reason === 'not_revealing' ||
                    reason === 'lost'
                ) {
                    store.requestResync('solo_poll');
                }

                const message = messageFor(reason);

                if (message !== null) {
                    setFailure({ key: current, kind, message });
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
            failure !== null && failure.key === key
                ? { kind: failure.kind, message: failure.message }
                : null,
    };
}
