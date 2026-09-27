import type { HttpExceptionResponse, PendingVisit } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    useEffect,
    useEffectEvent,
    useLayoutEffect,
    useRef,
    useState,
} from 'react';
import { useGameState } from '@/hooks/game/use-game-state';
import type { GameStateView } from '@/hooks/game/use-game-state';
import { useHeartbeat } from '@/hooks/game/use-heartbeat';
import { useTranslations } from '@/hooks/use-translations';
import { announce } from '@/lib/game/announcer';
import { fetchGameState } from '@/lib/game/store';
import { settingsChangeLines, settingsChangesFrom } from '@/lib/room-settings';
import type { SettingsChangeReport } from '@/lib/room-settings';
import { heartbeat, leave, show, state as roomState } from '@/routes/room';
import type { GameStatePacket } from '@/types/game-wire';
import type { RoomSettingsState } from '@/types/room-settings';

/**
 * L'état de la page du salon `game/lobby` (spec 50 § 8.1 et § 8.2) : le
 * magasin de 60, monté par {@see useGameState} avec la resynchronisation du
 * salon (`room.state`), et les réactions propres au lobby.
 *
 * **Aucune visite ne change l'état de la page** (§ 7.2, 90 § 2.1) :
 * `game.launched` fait passer le magasin à l'état de partie, `room.replayed`
 * le ramène au lobby, dans la même page. Seules deux sorties visitent
 * `room.show` : `room.archived` (« salon expiré ») et `seat.kicked` (la
 * page d'entrée, en état `kicked`) — et un 403 de resynchronisation, le
 * jeton ne tenant plus de siège ici.
 *
 * Le **battement de présence** de 60 (§ 13.1, `useHeartbeat`) part d'ici,
 * vers `room.heartbeat`, tant que la page reste celle du salon et que
 * l'onglet tient le siège — jamais d'un onglet supplanté, qui n'écrit plus
 * (§ 12.7), ni pendant « Quitter le salon ».
 *
 * Réactions du § 8.2, écrites ici et nulle part ailleurs :
 *
 * - **relecture `room.state` au retour au lobby après « Rejouer »**
 *   (`room.replayed`, qui ne porte aucun siège) : le magasin garde les
 *   sièges de la partie — `gameSeats()`, sans les sièges entrés pendant la
 *   partie, dès qu'un paquet a été relu en partie —, et le décompte comme
 *   le motif `room.lobby.need_players` seraient faux. Le paquet relu porte
 *   `lobbySeats()` ;
 * - **rechargement partiel `settings` et `presets`** — `router.reload()`,
 *   sous l'en-tête `X-Seat-Token` que pose `useGameState` sur toute visite —
 *   après chaque resynchronisation appliquée au lobby (reconnexion d'Echo,
 *   retour de visibilité ou en ligne, paquet qui ramène au lobby, relecture
 *   qui suit « Rejouer »). Le paquet `GameStatePacket` ne porte pas l'état
 *   des réglages, et un `settings.changed` ou un `room.replayed` manqué
 *   pendant une coupure n'est jamais rejoué : sans ce rechargement, le
 *   compteur de vivier, le blocage et les presets grisés resteraient
 *   périmés. **Jamais depuis un onglet supplanté** : son jeton d'onglet
 *   n'est plus l'actif, et la visite reprendrait la main (`ClaimSeatTab`) à
 *   l'onglet qui la tient ;
 * - **annonces** dans l'unique région `aria-live` (`announce()`, 90 § 7.4) :
 *   changement d'hôte (`room.lobby.host_changed`, ou
 *   `room.lobby.you_are_host` pour le nouvel hôte lui-même), réglages
 *   changés vus par un non-hôte au lobby (`room.lobby.settings_updated`),
 *   rapport de changements reçu par l'auteur (`room.lobby.changes_title`
 *   puis une ligne par champ) ;
 * - **rapport de changements** (§ 2.6, lot L50-5) : le flash
 *   `settingsChanges` que le serveur pose sur la réponse de TOUTE écriture
 *   de réglages de ce siège — champ, preset ou remède —, jamais diffusé au
 *   salon. Posé même vide (`[]`), il remplace le précédent ; la prochaine
 *   écriture (toute visite autre qu'une lecture) l'efface à son départ, et
 *   une écriture refusée n'en rapporte aucun ;
 * - **onglet supplanté** : {@see LobbyStateView.onHttpException}, rappel de
 *   chaque requête du lobby, intercepte la réponse 409 `seat_superseded` de
 *   `seat.active` — sans lui, Inertia ouvrirait sa fenêtre d'erreur brute
 *   (§ 12.5) — et relit l'état, qui rend `seatActive: false` : la page passe
 *   en lecture seule.
 *
 * Idempotent sous React Compiler et en mode strict : chaque réaction compare
 * l'état à celui qu'elle a déjà vu (références gardées d'un montage à
 * l'autre), jamais à un compteur local d'effets.
 */

export type UseLobbyStateOptions = {
    /** Code du salon (prop `room.code`), pour Wayfinder. */
    code: string;
    /** Prop `state`. */
    state: GameStatePacket;
    /** Prop `seatToken`. */
    seatToken: string;
    /** Prop `settings`, recalculée par le serveur à chaque rendu. */
    settings: RoomSettingsState;
};

export type LobbyPhase = 'lobby' | 'game';

export type LobbyStateView = GameStateView & {
    /**
     * `lobby` tant qu'aucune partie n'est suivie ; `game` du lancement au
     * « Rejouer », podium compris (`RoomStatus::Playing`, § 12.7).
     */
    phase: LobbyPhase;
    /** L'état des réglages le plus récent : diffusé, à défaut la prop. */
    settings: RoomSettingsState;
    /**
     * Onglet actif et siège toujours dans le salon, connexion d'Echo ou non.
     * Garde « Quitter le salon » (§ 11.1, § 11.4) : un geste HTTP de tout
     * joueur, que la perte du websocket ne suspend pas — seuls les contrôles
     * d'hôte le sont (§ 8.1).
     */
    active: boolean;
    /**
     * Ce siège peut écrire : onglet actif, connecté, toujours dans le salon.
     * Les contrôles d'hôte sont désactivés sinon (§ 8.1, état
     * « déconnexion ») ; le serveur relit tout sous verrou de toute façon.
     */
    canWrite: boolean;
    /**
     * Rappel `onHttpException` de toute requête du lobby (visite, écriture,
     * rechargement partiel) : `false` pour un 409 `seat_superseded`, rien
     * sinon — la réponse suit alors son cours ordinaire.
     */
    onHttpException: (response: HttpExceptionResponse) => boolean | void;
    /**
     * Rapport de changements de la dernière écriture de réglages de CE
     * siège (flash `settingsChanges`, § 2.6), `null` sans écriture ou depuis
     * que la suivante est partie.
     */
    settingsChanges: SettingsChangeReport | null;
};

/**
 * Le rapport de changements des écritures de réglages de ce siège (§ 2.6),
 * lu dans le flash `settingsChanges` et annoncé à sa réception s'il n'est
 * pas vide. Toute visite qui écrit (méthode autre que `get`) l'efface à son
 * départ : le flash de sa réponse arrive toujours après. Abonnement en
 * `useLayoutEffect`, posé pendant le commit, comme `useFlashNotice` : un
 * flash émis juste après l'échange de page trouve son écouteur. Retiré au
 * démontage (`strictMode` monte deux fois).
 */
function useSettingsChanges(): SettingsChangeReport | null {
    const { t } = useTranslations();
    const [changes, setChanges] = useState<SettingsChangeReport | null>(null);
    const announceChanges = useEffectEvent(
        (report: SettingsChangeReport): void => {
            const lines = settingsChangeLines(report, t);

            if (lines.length === 0) {
                return;
            }

            announce(t('room.lobby.changes_title'));

            for (const line of lines) {
                announce(line);
            }
        },
    );

    useLayoutEffect(() => {
        const stopStart = router.on('start', (event) => {
            if (event.detail.visit.method !== 'get') {
                setChanges(null);
            }
        });
        const stopFlash = router.on('flash', (event) => {
            const report = settingsChangesFrom(event.detail.flash);

            if (report === null) {
                return;
            }

            setChanges(report);
            announceChanges(report);
        });

        return () => {
            stopFlash();
            stopStart();
        };
    }, []);

    return changes;
}

/** Props rechargées au lobby : ni le paquet ni le jeton d'onglet. */
const RELOADED_PROPS = ['settings', 'presets'];

/** Code du 409 de `seat.active`, miroir de `EnsureActiveSeat::SUPERSEDED`. */
const SEAT_SUPERSEDED = 'seat_superseded';

/** HTTP 409 Conflict. */
const HTTP_CONFLICT = 409;

/** La réponse est-elle le 409 `seat_superseded` de `seat.active` ? */
export function isSeatSupersededResponse(
    response: Pick<HttpExceptionResponse, 'status' | 'data'>,
): boolean {
    if (response.status !== HTTP_CONFLICT) {
        return false;
    }

    let body: unknown = response.data;

    if (typeof body === 'string') {
        try {
            body = JSON.parse(body);
        } catch {
            return false;
        }
    }

    return (
        typeof body === 'object' &&
        body !== null &&
        'code' in body &&
        body.code === SEAT_SUPERSEDED
    );
}

/**
 * « Quitter le salon » (`room.leave`, 50 § 11.4) est-il en cours ? Vrai du
 * départ de la visite (`start`, jamais `before`, qu'un autre écouteur peut
 * annuler) à sa fin (`finish` de la même visite). Un départ réussi quitte la
 * page : Inertia rend l'accueil par `flushSync` avant `finish`, et le lobby
 * est déjà démonté ; un départ refusé rend la main au battement.
 *
 * `router.on` est global : seule la visite POST vers le départ de CE salon
 * compte, reconnue à son chemin.
 */
function useLeaveInFlight(code: string): boolean {
    const [leaving, setLeaving] = useState(false);

    useEffect(() => {
        const leavePath = leave.url({ room: code });
        let pending: PendingVisit | null = null;

        const offStart = router.on('start', (event) => {
            const visit = event.detail.visit;

            if (visit.method === 'post' && visit.url.pathname === leavePath) {
                pending = visit;
                setLeaving(true);
            }
        });
        const offFinish = router.on('finish', (event) => {
            if (pending !== null && event.detail.visit === pending) {
                pending = null;
                setLeaving(false);
            }
        });

        // Retirés au démontage : `strictMode` monte deux fois.
        return () => {
            offFinish();
            offStart();
        };
    }, [code]);

    return leaving;
}

export function useLobbyState(options: UseLobbyStateOptions): LobbyStateView {
    const { code } = options;
    const { t } = useTranslations();

    const view = useGameState({
        state: options.state,
        seatToken: options.seatToken,
        settings: options.settings,
        resync: () => fetchGameState(roomState.url({ room: code })),
        onExit: () => router.visit(show({ room: code })),
    });
    const { state, store, connection } = view;

    const phase: LobbyPhase = state.gameRef === null ? 'lobby' : 'game';
    const settings = state.settings ?? options.settings;
    const active = state.exit === null && state.self.seatActive;
    const canWrite = active && connection === 'connected';

    const onHttpException = (
        response: HttpExceptionResponse,
    ): boolean | void => {
        if (isSeatSupersededResponse(response)) {
            store.requestResync('superseded');

            return false;
        }
    };

    // --- Battement de présence (60 § 13.1) ----------------------------------
    //
    // Du lobby au podium, tant que la page reste celle du salon : il nourrit
    // `room.last_activity_at` (échéances de 50 § 16), ramène le siège à
    // `connected` et reprend une partie en pause.
    //
    // - Un onglet supplanté n'écrit plus (§ 12.7) : il ne bat pas. Un siège
    //   dont il ne reste que lui sort des participants — il ne peut rien
    //   saisir, il ne bloque donc ni la fin anticipée ni le transfert d'hôte —,
    //   et le budget `game-write`, commun aux onglets du jeton, ne porte
    //   qu'un flux de battements (§ 19.1). Recharger reprend la main.
    // - Suspendu pendant « Quitter le salon » : un battement traité après le
    //   départ ramènerait le siège parti à `connected` (§ 13.1).
    //
    // Un 403 — plus de siège tenu ici — se lit par une resynchronisation,
    // dont le 403 pose `exit` et fait quitter le salon.
    const leaving = useLeaveInFlight(code);
    const heartbeatStatus = useHeartbeat(
        active && !leaving ? heartbeat.url({ room: code }) : null,
    );

    useEffect(() => {
        if (heartbeatStatus === 'refused') {
            store.requestResync('heartbeat_refused');
        }
    }, [heartbeatStatus, store]);

    // --- Rechargement partiel des réglages et des presets (§ 8.2) -----------

    const reloadSettings = useEffectEvent((): void => {
        router.reload({ only: RELOADED_PROPS, onHttpException });
    });
    const reloadable = phase === 'lobby' && active;
    const seen = useRef({
        resyncCount: state.resyncCount,
        gameRef: state.gameRef,
    });

    useEffect(() => {
        const before = seen.current;

        seen.current = {
            resyncCount: state.resyncCount,
            gameRef: state.gameRef,
        };

        const resynced = state.resyncCount !== before.resyncCount;
        const backToLobby = before.gameRef !== null && state.gameRef === null;

        if (backToLobby && !resynced) {
            // `room.replayed` : les sièges gardés sont ceux de la partie
            // (`gameSeats()` dès qu'un paquet a été relu en partie), sans les
            // entrées pendant la partie. Le paquet relu porte `lobbySeats()` ;
            // sa relecture appliquée recharge ensuite réglages et presets.
            store.requestResync('replayed');
        } else if (resynced && reloadable) {
            reloadSettings();
        }
    }, [state.resyncCount, state.gameRef, reloadable, store]);

    // --- Annonces : changement d'hôte, réglages changés (§ 8.1) -------------

    const hostPublicId =
        state.seats.find((seat) => seat.isHost)?.publicId ?? null;
    const announceHost = useEffectEvent((publicId: string): void => {
        if (publicId === state.self.publicId) {
            announce(t('room.lobby.you_are_host'));

            return;
        }

        const seat = state.seats.find((each) => each.publicId === publicId);

        if (seat !== undefined) {
            announce(
                t('room.lobby.host_changed', {
                    nickname: seat.nickname ?? seat.avatar.initials,
                }),
            );
        }
    });
    const previousHost = useRef(hostPublicId);

    useEffect(() => {
        const before = previousHost.current;

        previousHost.current = hostPublicId;

        if (hostPublicId !== null && hostPublicId !== before) {
            announceHost(hostPublicId);
        }
    }, [hostPublicId]);

    const settingsSignature = JSON.stringify(settings.settings);
    const announceSettings = useEffectEvent((): void => {
        if (phase === 'lobby' && !state.self.isHost) {
            announce(t('room.lobby.settings_updated'));
        }
    });
    const previousSettings = useRef(settingsSignature);

    useEffect(() => {
        const before = previousSettings.current;

        previousSettings.current = settingsSignature;

        if (settingsSignature !== before) {
            announceSettings();
        }
    }, [settingsSignature]);

    const settingsChanges = useSettingsChanges();

    return {
        ...view,
        phase,
        settings,
        active,
        canWrite,
        onHttpException,
        settingsChanges,
    };
}
