import { http, router, usePage } from '@inertiajs/react';
import {
    useEffect,
    useEffectEvent,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { ConnectionState } from '@/components/game/connection-banner';
import { useGameChannel } from '@/hooks/game/use-game-channel';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { connectionStateOf, subscribeReconnected } from '@/lib/game/echo';
import { createFrameLoader } from '@/lib/game/frame-loader';
import type { FrameLoader, FrameView } from '@/lib/game/frame-loader';
import {
    handshakeServerClock,
    recalibrateServerClock,
    serverNow,
    subscribeServerClock,
} from '@/lib/game/server-clock';
import {
    createGameStore,
    holdSeatToken,
    isMemberOfRound,
    roundKeyOf,
    seatTokenHeaders,
} from '@/lib/game/store';
import type {
    GameExit,
    GameStore,
    GameStoreState,
    ResyncOutcome,
    ResyncReason,
} from '@/lib/game/store';
import type { GameStatePacket, RoundState } from '@/types/game-wire';
import type { RoomSettingsState } from '@/types/room-settings';

/**
 * L'état de jeu d'une page `game/*` (spec 60 § 11.8 et § 12, contrat C7
 * § 2.6) : le magasin de `lib/game/store.ts`, nourri par le paquet initial,
 * les canaux Echo et les resynchronisations ; l'état de connexion du bandeau
 * (`ConnectionBanner`, 90 § 7.6) ; les images de jeu (`frame-loader`).
 *
 * La page `game/lobby` (50) le monte avec `room.state`, la page `game/solo`
 * (L60-16) avec `solo.state` : **le magasin reçoit de la page sa fonction de
 * resynchronisation**, jamais une URL en dur. Il ne navigue jamais : à
 * `seat.kicked`, `room.archived` ou à un 403 de resynchronisation, il pose
 * `exit`, quitte les canaux, et `onExit` de la page visite `room.show`.
 *
 * Ce que le hook branche, et pourquoi :
 *
 * - **Jeton d'onglet** (60 § 12.7, C7 § 4.9) : gardé en mémoire, présenté
 *   (`X-Seat-Token`) sur TOUTE requête — visites Inertia par un écouteur
 *   `router.on('before')`, requêtes `useHttp()` et resynchronisations — pour
 *   qu'un rechargement partiel (changement de langue, réglages rechargés
 *   par 50) ne supplante jamais son propre onglet. Toute réponse 409
 *   `seat_superseded` déclenche une resynchronisation, qui rend
 *   `seatActive: false` : la page passe en lecture seule (`seatNotice`).
 * - **Horloge** : poignée de main au montage, à chaque reconnexion d'Echo et
 *   à chaque retour de visibilité (`clockSamples` de la prop `realtime`) ;
 *   recalage sur chaque `serverNow` reçu.
 * - **Resynchronisation** à la reconnexion d'Echo, au retour de visibilité
 *   et au retour en ligne ; les autres déclencheurs vivent dans le magasin.
 * - **Connexion** : `offline` si le navigateur l'est, `reconnecting` si le
 *   temps réel est perdu ; au retour à `connected`, annonce
 *   `common.connection.restored` par `announce()` (90 § 7.4) — le bandeau,
 *   lui, ne rend rien à `connected`.
 * - **Images** : chaque référence reçue d'une manche dont le siège est
 *   membre est confiée au chargeur, les blobs des manches quittées sont
 *   révoqués, une manche close ne garde que ses paliers ouverts ; un 404
 *   persistant ou un 403 du chargeur relit l'état.
 *
 * Idempotent sous React Compiler et en mode strict : le magasin et le
 * chargeur naissent une fois par montage (`useState`), sans effet tant
 * qu'ils ne sont pas observés ; chaque branchement est un effet ou une
 * souscription `useSyncExternalStore` qui se défait au démontage.
 */

export type UseGameStateOptions = {
    /** Prop `state` : le paquet construit par le serveur avec la page. */
    state: GameStatePacket;
    /** Prop `seatToken` : le jeton d'onglet rendu par `ClaimSeatTab`. */
    seatToken: string;
    /** La resynchronisation de la page (`fetchGameState(room.state …)`). */
    resync: (reason: ResyncReason) => Promise<ResyncOutcome>;
    /** Prop `settings` du lobby (50), nulle ailleurs. */
    settings?: RoomSettingsState | null;
    /** La page quitte l'état de jeu : visite de `room.show` (50). */
    onExit?: (exit: GameExit) => void;
};

/** Les images de jeu, vues de l'écran ; `version` change à chaque chargement. */
export type GameFrames = {
    version: number;
    view: (
        gameRef: string,
        sequenceIndex: number,
        tierIndex: number,
    ) => FrameView;
};

export type GameStateView = {
    state: GameStoreState;
    store: GameStore;
    connection: ConnectionState;
    /**
     * Message de lecture seule, déjà traduit, pour `ReadOnlyNotice` : onglet
     * supplanté (`game.seat.superseded`) ou expulsion (`game.seat.kicked`) ;
     * nul sinon.
     */
    seatNotice: string | null;
    frames: GameFrames;
};

type GameRuntime = { store: GameStore; loader: FrameLoader };

/** Phases d'une manche close, dont seuls les paliers ouverts restent servis. */
const ENDED_PHASES: ReadonlySet<RoundState['phase']> = new Set<
    RoundState['phase']
>(['closed', 'revealing', 'cancelled']);

/** Le corps d'une erreur HTTP d'Inertia est-il un 409 `seat_superseded` ? */
function isSeatSuperseded(error: unknown): boolean {
    if (typeof error !== 'object' || error === null || !('response' in error)) {
        return false;
    }

    const response = (
        error as { response?: { status?: number; data?: unknown } }
    ).response;

    if (response?.status !== 409 || typeof response.data !== 'string') {
        return false;
    }

    try {
        const body: unknown = JSON.parse(response.data);

        return (
            typeof body === 'object' &&
            body !== null &&
            'code' in body &&
            body.code === 'seat_superseded'
        );
    } catch {
        return false;
    }
}

function subscribeOnline(listener: () => void): () => void {
    window.addEventListener('online', listener);
    window.addEventListener('offline', listener);

    return () => {
        window.removeEventListener('online', listener);
        window.removeEventListener('offline', listener);
    };
}

function isOnline(): boolean {
    return navigator.onLine;
}

function alwaysOnline(): boolean {
    return true;
}

export function useGameState(options: UseGameStateOptions): GameStateView {
    const { realtime } = usePage().props;
    const translator = useTranslations();
    const heartbeatIntervalMs = realtime.heartbeatIntervalMs;
    const clockSamples = realtime.clockSamples;

    // La fonction de la page, lue au moment de s'en servir : une page qui la
    // recrée à chaque rendu ne recrée pas le magasin.
    const resyncRef = useRef(options.resync);

    useEffect(() => {
        resyncRef.current = options.resync;
    });

    const [runtime] = useState<GameRuntime>(() => {
        const store = createGameStore({
            initial: options.state,
            settings: options.settings ?? null,
            resync: (reason) => resyncRef.current(reason),
            now: serverNow,
            heartbeatIntervalMs,
            recalibrate: (instant) => recalibrateServerClock(instant),
            subscribeClock: subscribeServerClock,
        });
        const loader = createFrameLoader({
            now: serverNow,
            onResyncNeeded: (reason) => store.requestResync(reason),
        });

        return { store, loader };
    });
    const { store, loader } = runtime;

    const state = useSyncExternalStore(store.subscribe, store.getState);

    // Une prop rechargée (visite partielle) remplace l'état courant ; le
    // paquet initial, déjà appliqué, est ignoré par le magasin.
    useEffect(() => store.applyPacket(options.state), [store, options.state]);
    useEffect(
        () => store.setSettings(options.settings ?? null),
        [store, options.settings],
    );

    // Jeton d'onglet sur toute requête, et 409 `seat_superseded` relu.
    useEffect(() => {
        const release = holdSeatToken(options.seatToken);
        const offBefore = router.on('before', (event) => {
            const visit = event.detail.visit;

            visit.headers = { ...visit.headers, ...seatTokenHeaders() };
        });
        const offRequest = http.onRequest((config) => ({
            ...config,
            headers: { ...config.headers, ...seatTokenHeaders() },
        }));
        const offError = http.onError((error) => {
            if (isSeatSuperseded(error)) {
                store.requestResync('superseded');
            }
        });

        return () => {
            offError();
            offRequest();
            offBefore();
            release();
        };
    }, [store, options.seatToken]);

    // Poignée de main d'horloge au montage ; reconnexion, visibilité, retour
    // en ligne : horloge et état relus.
    useEffect(() => {
        void handshakeServerClock(clockSamples);

        const offReconnected = subscribeReconnected(() => {
            void handshakeServerClock(clockSamples);
            store.requestResync('reconnected');
        });
        const onVisibility = (): void => {
            if (document.visibilityState === 'visible') {
                void handshakeServerClock(clockSamples);
                store.requestResync('visible');
            }
        };
        const onOnline = (): void => store.requestResync('online');

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onOnline);

        return () => {
            offReconnected();
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('online', onOnline);
        };
    }, [store, clockSamples]);

    // Canaux du siège, quittés à la sortie (`seat.kicked`, `room.archived`,
    // 403) et au démontage.
    const channels = state.exit === null ? state.channels : null;
    const realtimeStatus = useGameChannel(channels, realtime, store.receive);
    const online = useSyncExternalStore(
        subscribeOnline,
        isOnline,
        alwaysOnline,
    );
    const connection = connectionStateOf(realtimeStatus, online);

    const announceRestored = useEffectEvent((): void => {
        announce(translator.t('common.connection.restored'));
    });
    const previousConnection = useRef<ConnectionState>(connection);

    useEffect(() => {
        const before = previousConnection.current;

        previousConnection.current = connection;

        if (connection === 'connected' && before !== 'connected') {
            announceRestored();
        }
    }, [connection]);

    const leave = useEffectEvent((exit: GameExit): void => {
        options.onExit?.(exit);
    });

    useEffect(() => {
        if (state.exit !== null) {
            leave(state.exit);
        }
    }, [state.exit]);

    // Images : références reçues confiées au chargeur, manches quittées
    // révoquées ; au démontage, tout est révoqué.
    const gameRef = state.gameRef;
    const rounds = state.rounds;
    const seats = state.seats;
    const selfId = state.self.publicId;

    useEffect(() => {
        if (gameRef === null) {
            loader.retainOnly([]);

            return;
        }

        loader.retainOnly(
            rounds.map((round) => roundKeyOf(gameRef, round.sequenceIndex)),
        );

        for (const round of rounds) {
            // Un retardataire en attente ne voit aucune image de la manche
            // (60 § 13.7) : le service les lui refuse, même diffusées au
            // salon. Les demander coûterait `FRAME_MAX_ATTEMPTS` refus par
            // palier et le débit de ses premières vraies images.
            if (!isMemberOfRound(seats, selfId, round)) {
                continue;
            }

            const key = roundKeyOf(gameRef, round.sequenceIndex);

            // Une manche close ne garde que ses paliers ouverts, une manche
            // annulée aucun : le palier suivant d'une fin anticipée n'est
            // jamais demandé à sa garde.
            if (ENDED_PHASES.has(round.phase)) {
                loader.retainTiers(
                    key,
                    round.images.map((image) => image.tierIndex),
                );
            }

            for (const image of round.images) {
                loader.request(key, image);
            }
        }
    }, [loader, gameRef, rounds, seats, selfId]);

    useEffect(() => () => loader.retainOnly([]), [loader]);

    const framesVersion = useSyncExternalStore(
        loader.subscribe,
        loader.version,
    );
    const frames = useMemo<GameFrames>(
        () => ({
            version: framesVersion,
            view: (gameRef, sequenceIndex, tierIndex) =>
                loader.view(roundKeyOf(gameRef, sequenceIndex), tierIndex),
        }),
        [loader, framesVersion],
    );

    const seatNotice =
        state.exit === 'kicked'
            ? translator.t('game.seat.kicked')
            : state.self.seatActive
              ? null
              : translator.t('game.seat.superseded');

    return { state, store, connection, seatNotice, frames };
}
