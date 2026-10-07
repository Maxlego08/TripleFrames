import Echo from 'laravel-echo';
import type { ConnectionStatus, EchoOptions } from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ConnectionState } from '@/components/game/connection-banner';
import type { GameEventName, RealtimeConfig } from '@/types/game-wire';

/**
 * Le client Echo des pages de jeu (spec 60 § 10.4 et § 10.5, contrat C7
 * § 2.6, A-27, n° 79).
 *
 * **Configuré à l'exécution, jamais au build.** Tout vient de la prop
 * partagée `realtime` (clé PUBLIQUE de l'application Reverb, hôte, port et
 * schéma visés) et de `window.location` quand ceux-ci sont nuls — jamais
 * d'une variable `VITE_REVERB_*` figée dans l'artefact : un build fait en CI
 * se promeut tel quel, sans connaître `<DOMAINE>` ni aucun port. En
 * production, Reverb est publié sur l'origine de la page (`wss`, directives
 * nginx de l'abonnement) : `host`, `port` et `scheme` nuls suffisent.
 *
 * **Instancié paresseusement**, à la première souscription, et déconnecté
 * quand plus aucune page ne suit de canal : une page sans canal (solo) n'ouvre
 * jamais de WebSocket. Le transport est restreint à `ws`/`wss` : sans cette
 * restriction, `pusher-js` se replierait sur un hôte de secours du service
 * Pusher, hors de nos serveurs.
 *
 * **Souscriptions à compteur.** Un canal est rejoint une fois, quel que soit
 * le nombre d'abonnés, et n'est quitté qu'au départ du dernier, au tour de
 * boucle suivant : le double montage de `strictMode` (souscrit → quitte →
 * resouscrit) ne fait donc ni doublon d'écoute ni aller-retour
 * d'autorisation `/broadcasting/auth` (chemin fixe du framework, hors
 * Wayfinder, sous `throttle:game-read`). Les écoutes emploient le nom
 * `broadcastAs` préfixé d'un point (`.round.scheduled`), hors de l'espace de
 * noms `App.Events` d'Echo.
 *
 * **Confirmation des abonnements** (BUG-01). Reverb ne remet un événement
 * qu'à un canal dont l'abonnement est confirmé
 * (`pusher:subscription_succeeded`), et ne rejoue jamais rien : ce qui part
 * entre l'instant du paquet de la page et cette confirmation est perdu —
 * un `seat.choices` émis à `T_N` pendant l'autorisation d'un onglet qui
 * vient d'être rechargé, par exemple. La connexion ouverte ne le dit pas :
 * elle précède les autorisations. L'abonné est donc prévenu à chaque
 * confirmation de **la paire** (présence du salon et privé du siège), à la
 * première souscription comme à la resouscription que `pusher-js` fait à
 * chaque reconnexion, et relit l'état (60 § 12.6).
 *
 * Le module ne décide rien du jeu : il relaie les charges à l'abonné (le
 * magasin, `lib/game/store.ts`) et publie l'état de la connexion. La
 * présence Reverb ne fait jamais foi (60 § 13.1) : seuls les battements HTTP
 * disent qui est là.
 */

/**
 * Les dix-huit événements diffusés au salon (canal de présence
 * `room.{roomKey}`), dans l'ordre de la liste close (60 § 11.3).
 * `EventPayloadTest` compare cette liste à `app/Events/Game/`.
 */
export const ROOM_EVENTS = [
    'seat.joined',
    'seat.updated',
    'host.changed',
    'settings.changed',
    'room.replayed',
    'game.launched',
    'room.archived',
    'round.scheduled',
    'tier.opened',
    'player.locked',
    'round.closed',
    'round.revealed',
    'round.cancelled',
    'game.paused',
    'game.resumed',
    'game.pause_requested',
    'game.pause_request_cancelled',
    'game.ended',
] as const satisfies readonly GameEventName[];

/**
 * Les trois événements ciblés au siège (canal privé `seat.{publicId}`).
 * `EventPayloadTest` compare cette liste à `app/Events/Game/`.
 */
export const SEAT_EVENTS = [
    'seat.choices',
    'seat.superseded',
    'seat.kicked',
] as const satisfies readonly GameEventName[];

/** Noms Echo des deux canaux d'un siège, tels que le paquet les porte. */
export type GameChannels = { room: string; seat: string };

/** Un abonné aux événements : le nom `broadcastAs` et la charge brute. */
export type GameEventListener = (name: GameEventName, payload: unknown) => void;

/**
 * État du client temps réel, vu des pages de jeu :
 * - `idle` : aucune connexion demandée (aucun canal suivi, solo) ;
 * - `connecting` : première ouverture en cours ;
 * - `connected` : WebSocket ouvert ;
 * - `reconnecting` : connexion perdue après avoir été ouverte, `pusher-js`
 *   retente de lui-même ;
 * - `unavailable` : échec persistant, ou aucune clé d'application
 *   configurée (le jeu se poursuit sur les resynchronisations).
 */
export type RealtimeStatus =
    | 'idle'
    | 'connecting'
    | 'connected'
    | 'reconnecting'
    | 'unavailable';

/** Ce qu'Echo lit de l'emplacement de la page. */
export type PageLocation = Pick<Location, 'hostname' | 'port' | 'protocol'>;

/**
 * Les options Echo d'une configuration `realtime` : pure, sans effet, pour
 * être éprouvée sans navigateur. `null` quand aucune clé d'application n'est
 * configurée : aucune connexion n'est alors tentée.
 *
 * - hôte : `realtime.host`, sinon celui de la page ;
 * - schéma : `realtime.scheme`, sinon celui de la page ; `https` impose TLS ;
 * - port : `realtime.port`, sinon celui de la page ; un port implicite laisse
 *   `pusher-js` à ses défauts (80 en `ws`, 443 en `wss`).
 */
export function echoOptions(
    config: RealtimeConfig,
    location: PageLocation,
): EchoOptions<'reverb'> | null {
    if (config.key === '') {
        return null;
    }

    const scheme =
        config.scheme ?? (location.protocol === 'https:' ? 'https' : 'http');
    const port =
        config.port ?? (location.port === '' ? null : Number(location.port));

    return {
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host ?? location.hostname,
        ...(port === null || !Number.isInteger(port)
            ? {}
            : { wsPort: port, wssPort: port }),
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        enableStats: false,
        withoutInterceptors: true,
    };
}

/**
 * L'état Echo brut, ramené à l'état du client temps réel : une ouverture qui
 * suit une connexion déjà établie est une reconnexion.
 */
export function realtimeStatusOf(
    status: ConnectionStatus,
    everConnected: boolean,
): RealtimeStatus {
    switch (status) {
        case 'connected':
            return 'connected';
        case 'connecting':
            return everConnected ? 'reconnecting' : 'connecting';
        case 'failed':
            return 'unavailable';
        case 'disconnected':
        case 'reconnecting':
            return 'reconnecting';
    }
}

/**
 * L'état du bandeau de connexion (`ConnectionBanner`, 90 § 7.6) :
 * `offline` dès que le navigateur se dit hors ligne ; `reconnecting` quand
 * le temps réel est perdu ou indisponible ; `connected` sinon — solo
 * compris (aucun canal suivi), et pendant la toute première ouverture, pour
 * qu'aucun bandeau ne clignote au chargement (une ouverture qui échoue passe
 * `unavailable`, et le bandeau paraît).
 */
export function connectionStateOf(
    realtime: RealtimeStatus,
    online: boolean,
): ConnectionState {
    if (!online) {
        return 'offline';
    }

    switch (realtime) {
        case 'idle':
        case 'connecting':
        case 'connected':
            return 'connected';
        case 'reconnecting':
        case 'unavailable':
            return 'reconnecting';
    }
}

/** Une souscription à un canal, partagée par ses abonnés. */
type ChannelSubscription = {
    listeners: Set<GameEventListener>;
    /**
     * Abonnement confirmé par le serveur depuis la dernière ouverture de la
     * connexion. Faux dès qu'elle n'est plus ouverte : `pusher-js` oublie
     * alors ses abonnements et les refait à la reconnexion.
     */
    confirmed: boolean;
    /** Rappels des paires qui suivent ce canal, joués à chaque confirmation. */
    confirmations: Set<() => void>;
    leaveTimer: ReturnType<typeof setTimeout> | null;
};

let echo: Echo<'reverb'> | null = null;
let echoSignature: string | null = null;
let unbindStatus: (() => void) | null = null;
let disconnectTimer: ReturnType<typeof setTimeout> | null = null;
/** Vrai quand la dernière configuration demandée n'avait aucune clé. */
let unconfigured = false;
/** La connexion de l'instance courante a déjà été ouverte une fois. */
let everConnected = false;
let lastStatus: RealtimeStatus = 'idle';

const subscriptions = new Map<string, ChannelSubscription>();
const statusListeners = new Set<() => void>();
const reconnectedListeners = new Set<() => void>();

function notifyStatus(): void {
    const status = realtimeStatus();
    const reconnected =
        status === 'connected' && everConnected && lastStatus !== 'connected';

    if (status === 'connected') {
        everConnected = true;
    } else {
        for (const subscription of subscriptions.values()) {
            subscription.confirmed = false;
        }
    }

    lastStatus = status;

    for (const listener of statusListeners) {
        listener();
    }

    if (reconnected) {
        for (const listener of reconnectedListeners) {
            listener();
        }
    }
}

/** L'instance Echo de la configuration donnée, créée à la demande. */
function ensureEcho(config: RealtimeConfig): Echo<'reverb'> | null {
    const options = echoOptions(config, window.location);

    if (options === null) {
        unconfigured = true;
        notifyStatus();

        return null;
    }

    unconfigured = false;

    const signature = JSON.stringify(options);

    if (echo !== null && echoSignature === signature) {
        return echo;
    }

    teardownEcho();

    echo = new Echo({ ...options, Pusher });
    echoSignature = signature;
    unbindStatus = echo.connector.onConnectionChange(notifyStatus);
    notifyStatus();

    return echo;
}

function teardownEcho(): void {
    unbindStatus?.();
    unbindStatus = null;
    echo?.disconnect();
    echo = null;
    echoSignature = null;
    everConnected = false;

    for (const subscription of subscriptions.values()) {
        if (subscription.leaveTimer !== null) {
            clearTimeout(subscription.leaveTimer);
        }
    }

    subscriptions.clear();
}

function cancelDisconnect(): void {
    if (disconnectTimer !== null) {
        clearTimeout(disconnectTimer);
        disconnectTimer = null;
    }
}

/** Rejoint `name` (une fois), y ajoute l'abonné et rend la souscription. */
function attach(
    client: Echo<'reverb'>,
    kind: 'presence' | 'private',
    name: string,
    events: readonly GameEventName[],
    listener: GameEventListener,
): ChannelSubscription {
    let subscription = subscriptions.get(name);

    if (subscription === undefined) {
        const created: ChannelSubscription = {
            listeners: new Set(),
            confirmed: false,
            confirmations: new Set(),
            leaveTimer: null,
        };
        const channel =
            kind === 'presence' ? client.join(name) : client.private(name);

        for (const event of events) {
            channel.listen(`.${event}`, (payload: unknown) => {
                for (const each of created.listeners) {
                    each(event, payload);
                }
            });
        }

        // Première souscription et chaque resouscription d'une reconnexion.
        channel.subscribed(() => {
            created.confirmed = true;

            for (const each of created.confirmations) {
                each();
            }
        });

        subscriptions.set(name, created);
        subscription = created;
    }

    if (subscription.leaveTimer !== null) {
        clearTimeout(subscription.leaveTimer);
        subscription.leaveTimer = null;
    }

    subscription.listeners.add(listener);

    return subscription;
}

/**
 * Retire l'abonné de `name` ; le dernier parti, le canal est quitté au tour
 * suivant, et la connexion fermée si plus aucun canal n'est suivi.
 */
function detach(name: string, listener: GameEventListener): void {
    const subscription = subscriptions.get(name);

    if (subscription === undefined) {
        return;
    }

    subscription.listeners.delete(listener);

    if (subscription.listeners.size > 0 || subscription.leaveTimer !== null) {
        return;
    }

    subscription.leaveTimer = setTimeout(() => {
        subscriptions.delete(name);
        echo?.leave(name);

        if (subscriptions.size === 0) {
            cancelDisconnect();
            disconnectTimer = setTimeout(() => {
                disconnectTimer = null;

                if (subscriptions.size === 0) {
                    teardownEcho();
                    notifyStatus();
                }
            }, 0);
        }
    }, 0);
}

/**
 * Suit les deux canaux d'un siège — présence du salon, privé du siège — et
 * relaie chaque événement de la liste close à `listener`. Rend le
 * désabonnement. Sans clé d'application configurée, rien n'est suivi et
 * l'état passe à `unavailable`.
 *
 * `onSubscribed` est appelé chaque fois que **les deux** abonnements sont
 * confirmés par le serveur : à la première souscription, à chaque
 * resouscription après une reconnexion, et au branchement d'un abonné sur
 * une paire déjà confirmée (un autre abonné la tenait : ce qui est parti
 * avant ne l'a pas atteint). Tout événement émis avant cet instant a pu être
 * perdu ; tout événement émis après est remis. L'abonné y relit l'état. Comme
 * `listener`, l'appelant le garde stable.
 */
export function subscribeGameChannels(
    config: RealtimeConfig,
    channels: GameChannels,
    listener: GameEventListener,
    onSubscribed: () => void,
): () => void {
    const client = ensureEcho(config);

    if (client === null) {
        return () => undefined;
    }

    cancelDisconnect();

    const room = attach(
        client,
        'presence',
        channels.room,
        ROOM_EVENTS,
        listener,
    );
    const seat = attach(
        client,
        'private',
        channels.seat,
        SEAT_EVENTS,
        listener,
    );

    let active = true;

    const confirmed = (): void => {
        if (active && room.confirmed && seat.confirmed) {
            onSubscribed();
        }
    };

    room.confirmations.add(confirmed);
    seat.confirmations.add(confirmed);

    if (room.confirmed && seat.confirmed) {
        queueMicrotask(confirmed);
    }

    return () => {
        if (!active) {
            return;
        }

        active = false;
        room.confirmations.delete(confirmed);
        seat.confirmations.delete(confirmed);
        detach(channels.room, listener);
        detach(channels.seat, listener);
    };
}

/** L'état courant du client temps réel. */
export function realtimeStatus(): RealtimeStatus {
    if (echo === null) {
        return unconfigured ? 'unavailable' : 'idle';
    }

    return realtimeStatusOf(echo.connectionStatus(), everConnected);
}

/** Abonnement aux changements d'état du client temps réel. */
export function subscribeRealtimeStatus(listener: () => void): () => void {
    statusListeners.add(listener);

    return () => {
        statusListeners.delete(listener);
    };
}

/**
 * Abonnement aux **reconnexions** : retour à `connected` d'une connexion qui
 * l'avait déjà été. La page s'y resynchronise et refait la poignée de main
 * d'horloge (60 § 2.4, § 12.6) — les événements émis pendant la coupure ne
 * sont jamais rejoués par Reverb. Cette relecture précède la resouscription
 * des canaux : celle qui rattrape tout ce qui a précédé est la suivante,
 * à la confirmation de la paire (`onSubscribed` de
 * {@link subscribeGameChannels}).
 */
export function subscribeReconnected(listener: () => void): () => void {
    reconnectedListeners.add(listener);

    return () => {
        reconnectedListeners.delete(listener);
    };
}
