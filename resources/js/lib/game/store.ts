import { tierValueAt } from '@/lib/game/round-timeline';
import { GAME_WIRE_VERSION, parseIsoMs } from '@/lib/game/wire';
import type {
    ChoicesPayload,
    InputState,
    SubmissionResult,
} from '@/types/answers';
import type {
    GameEvent,
    GameEventName,
    GameStatePacket,
    IsoMs,
    RoundState,
    SeatView,
    SelfState,
    TierImageRef,
    WireEnvelope,
} from '@/types/game-wire';
import type { RoomSettingsState } from '@/types/room-settings';
import type { Leaderboard, Podium } from '@/types/scoring';

/**
 * Le magasin de jeu côté client (spec 60 § 11.8 et § 12.6, contrat C7
 * § 2.6) : magasin externe **idempotent** sur (`gameRef`, `sequenceIndex`,
 * `tierIndex`, événement), lu en `useSyncExternalStore` par les hooks de
 * `hooks/game/` (60) et de 50 (`use-lobby-state`).
 *
 * **C'est lui, jamais une navigation, qui fait passer `game/lobby` d'un état
 * à l'autre** (60 § 10.1, 90 § 2.1) : lobby, manche, révélation, pause,
 * podium, retour au lobby après « Rejouer ». Une visite Inertia démonterait
 * la souscription Echo, l'horloge resynchronisée et l'annonceur, et ferait
 * frapper un nouveau jeton d'onglet.
 *
 * **Le serveur décide, le magasin retient** (règle 1). Il part du paquet de
 * resynchronisation (`GameStatePacket`, prop `state` de la page), applique
 * les événements de la liste close (§ 11.3) qui le prolongent, et se
 * resynchronise — par la fonction que la page lui passe (`room.state` au
 * salon, `solo.state` en solo) — dès qu'il ne peut plus prolonger l'état
 * sans deviner :
 *
 * - à l'apprentissage d'un `sequenceIndex` inconnu ou d'un saut d'étape
 *   (§ 4.4 : un rattrapage n'émet que l'état courant) ;
 * - à `seat.superseded`, à toute réponse 409 `seat_superseded`, à un 403 ou
 *   404 persistant du chargeur d'images (signalés par l'appelant) ;
 * - à la reconnexion d'Echo, au retour de visibilité et en ligne, et à
 *   chaque confirmation des deux canaux du siège par le serveur — première
 *   souscription comprise, avant laquelle Reverb n'a rien remis (BUG-01) —
 *   (signalés par `use-game-state`) ;
 * - **à toute garde de palier dont il ne détient pas l'URL**
 *   (`Tᵢ − preload_lead_ms`, palier 1 de la manche suivante compris) : les
 *   gardes des manches connues se calculent sur la chronologie et l'avance
 *   de préchargement (déduite d'une `TierImageRef` : `Tᵢ − fetchNotBefore`) ;
 *   celle d'une manche inconnue se lit dans `nextTransitionAt` du paquet,
 *   quand cet instant n'est aucune étape ni aucune garde connue ;
 * - en multijoueur, pour un siège dont la saisie accepte encore le QCM
 *   (`open`, `text_exhausted` — ou saisie inconnue d'un siège membre), si
 *   `seat.choices` n'arrive pas dans les `heartbeatIntervalMs` qui suivent
 *   `tier.opened` du palier du QCM : c'est le **cas terminal** du contrat
 *   C11, où aucun `seat.choices` ne part (70 § 10.7). Le délai absorbe
 *   seulement l'ordre `tier.opened` puis `seat.choices` ; il ne décide rien ;
 * - **filet** (multijoueur) : si aucun message n'a porté un `serverNow`
 *   postérieur à la prochaine étape attendue (ouverture de palier, clôture,
 *   révélation, fin de révélation, échéance de pause — à défaut,
 *   `nextTransitionAt`) une fois passés `heartbeatIntervalMs` après elle ;
 * - en solo, qui ne reçoit aucun événement : à chaque `nextTransitionAt` et
 *   à chaque `fetchNotBefore` (§ 16.4) ; la page le fait aussi après chaque
 *   geste, dont la réponse est un paquet.
 *
 * Une resynchronisation à la fois : une demande reçue pendant qu'une autre
 * court en provoque **une** de plus. Les événements sont appliqués dès leur
 * réception ; tout paquet appliqué ensuite — relu, prop rechargée par une
 * visite, réponse d'un geste solo — est suivi du **rejeu** des événements
 * déjà appliqués qui lui sont postérieurs, qu'il ne comprend pas. Un
 * événement dont le `serverNow` ne dépasse pas celui du dernier paquet
 * appliqué est déjà compris dans ce paquet, et ignoré. Pour une même manche
 * `pending`, le `round.scheduled` au `serverNow` le plus grand l'emporte
 * (C7 § 4.2).
 *
 * **Aucun minuteur client ne décide** (règle 8) : les minuteurs de ce module
 * ne font que redemander l'état au serveur. Ils ne sont armés que tant que le
 * magasin a un abonné, pour que le double montage de `strictMode` et un
 * magasin jeté ne laissent rien courir.
 *
 * `room.archived` et `seat.kicked` — les deux seuls cas où la page du salon
 * cesse d'être la bonne —, le siège du joueur diffusé expulsé
 * (`seat.updated`, si le ciblé s'est perdu) et un 403 de resynchronisation
 * (plus de siège tenu) posent `exit` : la page quitte ses canaux et visite
 * `room.show`. Le magasin ne navigue jamais lui-même.
 *
 * Règle 3 : le magasin ne détient que ce que le serveur a envoyé — aucun
 * titre avant `round.revealed`, hors des quatre chaînes du QCM ciblé.
 */

/** Pourquoi une resynchronisation est demandée (journal, tests). */
export type ResyncReason =
    | 'unknown_round'
    | 'unknown_game'
    | 'step_jump'
    | 'malformed'
    | 'tier_guard'
    | 'next_transition'
    | 'watchdog'
    | 'choices_missing'
    | 'launched'
    | 'replayed'
    | 'superseded'
    | 'reconnected'
    | 'subscribed'
    | 'visible'
    | 'online'
    | 'frame_unavailable'
    | 'frame_expired'
    | 'solo_poll'
    | 'heartbeat_refused'
    | 'retry';

/** Réponse d'une resynchronisation, telle que la fonction de la page la rend. */
export type ResyncOutcome =
    | { kind: 'packet'; packet: GameStatePacket }
    /** 403 : le jeton ne tient plus de siège ici (expulsé, salon archivé). */
    | { kind: 'lost' }
    /** Réseau, 429, 5xx, corps illisible : réessayé plus tard. */
    | { kind: 'failed' };

/** Pourquoi la page doit quitter l'état de jeu pour `room.show`. */
export type GameExit = 'kicked' | 'archived' | 'lost';

/** Les propositions reçues par `seat.choices` (ciblé), pour une manche. */
export type OfferedChoices = { sequenceIndex: number; payload: ChoicesPayload };

/** L'état que le magasin expose, remplacé (jamais muté) à chaque changement. */
export interface GameStoreState {
    /** `serverNow` du dernier message appliqué. */
    serverNow: IsoMs;
    /** Partie suivie ; nul au lobby (et solo pas encore lancé). */
    gameRef: string | null;
    mode: GameStatePacket['mode'];
    channels: GameStatePacket['channels'];
    status: GameStatePacket['status'];
    roundsCount: number | null;
    roundsCompleted: number | null;
    framesPerRound: number | null;
    inputDifficulty: GameStatePacket['inputDifficulty'];
    maxAnswerLength: number | null;
    seats: readonly SeatView[];
    pause: GameStatePacket['pause'];
    /**
     * Manches connues de la partie, dans l'ordre de jeu (`startsAt`) : la
     * manche en cours ou révélée, et la suivante déjà programmée. Leurs
     * `images` cumulent toutes les références reçues, la plus récente par
     * palier. {@link displayedRound} choisit celle qu'un écran montre.
     */
    rounds: readonly RoundState[];
    self: SelfState;
    /** Dernières propositions reçues par `seat.choices`, nulles sinon. */
    offeredChoices: OfferedChoices | null;
    leaderboard: Leaderboard;
    podium: Podium | null;
    nextTransitionAt: IsoMs | null;
    /** État des réglages du salon (`settings.changed`, `room.replayed`). */
    settings: RoomSettingsState | null;
    exit: GameExit | null;
    /** Une resynchronisation est en vol. */
    resyncing: boolean;
    /** Resynchronisations appliquées depuis le montage. */
    resyncCount: number;
}

export interface GameStoreOptions {
    /** Le paquet initial (prop `state` de la page). */
    initial: GameStatePacket;
    /** L'état des réglages initial (prop `settings` du lobby), s'il y en a un. */
    settings?: RoomSettingsState | null;
    /** La resynchronisation de la page : `room.state` au salon, `solo.state` en solo. */
    resync: (reason: ResyncReason) => Promise<ResyncOutcome>;
    /** L'instant serveur, en millisecondes (`serverNow()` de `server-clock.ts`). */
    now: () => number;
    /** Délai du déclencheur du QCM, du filet et de la relance (prop `realtime`). */
    heartbeatIntervalMs: number;
    /** Recalage de l'horloge sur chaque `serverNow` reçu. */
    recalibrate?: (serverNow: IsoMs) => void;
    /** Signal des corrections de l'horloge, pour recaler les minuteurs. */
    subscribeClock?: (listener: () => void) => () => void;
}

export interface GameStore {
    getState: () => GameStoreState;
    /** Abonnement (`useSyncExternalStore`) ; arme les minuteurs du magasin. */
    subscribe: (listener: () => void) => () => void;
    /** Un événement du fil : son nom `broadcastAs` et sa charge brute. */
    receive: (name: GameEventName, payload: unknown) => void;
    /** Un paquet `GameStatePacket` (prop, réponse d'un geste solo). */
    applyPacket: (packet: GameStatePacket) => void;
    /** Un nouvel état des réglages (prop rechargée par 50). */
    setSettings: (settings: RoomSettingsState | null) => void;
    /** La réponse de soumission de 70, à destinataire unique. */
    applySubmission: (sequenceIndex: number, result: SubmissionResult) => void;
    requestResync: (reason: ResyncReason) => void;
    /** Oublie minuteurs et abonnés ; plus rien n'est demandé au serveur. */
    dispose: () => void;
}

// --- Jeton d'onglet ---------------------------------------------------------

/** En-tête du jeton d'onglet, miroir de `EnsureActiveSeat::HEADER`. */
export const SEAT_TOKEN_HEADER = 'X-Seat-Token';

/** Le jeton d'onglet de la page, gardé EN MÉMOIRE seulement (C7 § 4.9). */
let seatToken: string | null = null;

/**
 * Tient `token` comme jeton d'onglet de la page (prop `seatToken`) et rend
 * sa libération. Jamais dans `localStorage` : un rechargement complet en
 * frappe un nouveau, c'est la règle du second onglet (60 § 12.7).
 */
export function holdSeatToken(token: string): () => void {
    seatToken = token;

    return () => {
        if (seatToken === token) {
            seatToken = null;
        }
    };
}

/**
 * L'en-tête à présenter sur TOUTE requête de la page — visites Inertia,
 * rechargements partiels, écritures, resynchronisations —, vide sans jeton.
 */
export function seatTokenHeaders(): Record<string, string> {
    return seatToken === null ? {} : { [SEAT_TOKEN_HEADER]: seatToken };
}

/**
 * Une resynchronisation par `GET` du paquet (`room.state`, `solo.state`) :
 * JSON, sans cache, avec les cookies du site et le jeton d'onglet.
 */
export async function fetchGameState(url: string): Promise<ResyncOutcome> {
    try {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', ...seatTokenHeaders() },
        });

        if (response.status === 403) {
            return { kind: 'lost' };
        }

        if (!response.ok) {
            return { kind: 'failed' };
        }

        const body: unknown = await response.json();

        return isGameStatePacket(body)
            ? { kind: 'packet', packet: body }
            : { kind: 'failed' };
    } catch {
        return { kind: 'failed' };
    }
}

// --- Sélecteurs ---------------------------------------------------------------

/**
 * Clé d'une manche, `${gameRef}:${sequenceIndex}` : la même que
 * `LiveRoundTimeline.key` (`round-timeline.ts`) et que les entrées du
 * chargeur d'images. Opaque, jamais un identifiant interne.
 */
export function roundKeyOf(gameRef: string, sequenceIndex: number): string {
    return `${gameRef}:${sequenceIndex}`;
}

/**
 * La manche qu'un écran montre à l'instant serveur `nowMs` : la dernière
 * manche connue déjà commencée (`startsAt ≤ nowMs`), sinon la prochaine
 * programmée (décompte). La révélation d'une manche tient donc jusqu'à
 * `min(revealEndsAt, startsAt de la manche suivante reçue)` (60 § 5.4) ;
 * au-delà de `revealEndsAt` sans manche suivante, la manche révélée n'est
 * plus montrée.
 */
export function displayedRound(
    state: GameStoreState,
    nowMs: number,
): RoundState | null {
    let current: RoundState | null = null;

    for (const round of state.rounds) {
        if (startsAtMs(round) <= nowMs) {
            current = round;
        }
    }

    if (current === null) {
        return state.rounds[0] ?? null;
    }

    if (
        current.phase === 'revealing' &&
        current.revealEndsAt !== null &&
        parseIsoMs(current.revealEndsAt) <= nowMs
    ) {
        return (
            state.rounds.find(
                (round) => round !== current && startsAtMs(round) > nowMs,
            ) ?? null
        );
    }

    return current;
}

/**
 * La valeur du palier que l'écran de manche affiche à l'instant serveur
 * `nowMs`, ou `null` quand elle est masquée (spec 60 § 2.5, D29 du 23/09 ;
 * exigence de 80 § 14 et § 1.4). **Seul décideur du masquage** : aucun
 * composant ne le réécrit.
 *
 * La valeur n'est affichée qu'en phase `running` : masquée hors partie, en
 * pause, pendant le décompte, dès la clôture (`round.closed`, ou un paquet
 * en phase `closed` — `endedAt` posé), en révélation, pour une manche
 * annulée et hors de `[0, D)`. Une valeur affichée après la clôture
 * promettrait des points qu'aucune soumission ne peut plus gagner.
 *
 * La manche montrée est celle de {@link displayedRound} ; sa valeur est
 * `tierValueAt()` de `round-timeline.ts` (80, C13 § 2.4), sur la même
 * fenêtre que `currentTier()` : image et valeur basculent au même instant,
 * celui de l'horloge resynchronisée, jamais l'arrivée d'un `tier.opened` qui
 * peut être en retard (90 § 7.3). Une manche encore `scheduled` dont `T₁`
 * est franchi est donc en cours à l'affichage : seul `tier.opened` manque à
 * sa confirmation, et le serveur, qui rattrape avant tout jugement, la
 * traite déjà comme ouverte.
 *
 * Au J2 s'ajoutera le masquage du mode sans score (`leaderboard.scoreless`,
 * L60-17). Ne décide rien : un affichage indicatif, le serveur retient seul
 * le palier d'une réponse, à son instant de réception.
 */
export function visibleTierValue(
    state: GameStoreState,
    nowMs: number,
): number | null {
    if (state.gameRef === null || state.status !== 'running') {
        return null;
    }

    const round = displayedRound(state, nowMs);

    if (round === null || round.endedAt !== null) {
        return null;
    }

    const origin = startsAtMs(round);
    const running =
        round.phase === 'running' ||
        (round.phase === 'scheduled' && nowMs >= origin);

    return running ? tierValueAt(round.tiers, nowMs - origin) : null;
}

/**
 * Le siège `publicId` est-il membre de la manche `round` d'après les sièges
 * de la partie (non expulsé, `firstRoundNumber` non nul et atteint) ? Un siège
 * non membre — retardataire en attente — ne voit aucune image de la manche
 * (60 § 13.7) : le service d'image la lui refuse, ses références diffusées
 * au salon ne se demandent donc pas.
 */
export function isMemberOfRound(
    seats: readonly SeatView[],
    publicId: string,
    round: Pick<RoundState, 'roundNumber'>,
): boolean {
    return memberOf(seats, publicId, round.roundNumber);
}

// --- Implémentation -------------------------------------------------------------

/** Événements qui n'existent que dans une partie (`GAME_BOUND` de 60 § 11.2). */
const GAME_BOUND = new Set<GameEventName>([
    'game.launched',
    'round.scheduled',
    'tier.opened',
    'player.locked',
    'round.closed',
    'round.revealed',
    'round.cancelled',
    'game.paused',
    'game.resumed',
    'game.ended',
    'seat.choices',
]);

/** Saisies qui acceptent encore le QCM (`acceptsChoice()`, D20 du 23/09). */
const ACCEPTS_CHOICE: ReadonlySet<InputState> = new Set<InputState>([
    'open',
    'text_exhausted',
]);

const EMPTY_LEADERBOARD: Leaderboard = {
    scoreless: false,
    roundNumber: null,
    rows: [],
};

/** Suivi interne d'une manche : programmation retenue, dernier palier ouvert. */
type RoundMeta = { scheduledAtMs: number; openedTier: number };

type QueuedEvent = { name: GameEventName; event: GameEvent };

function startsAtMs(round: RoundState): number {
    return parseIsoMs(round.startsAt);
}

function isEnvelope(value: unknown): value is WireEnvelope {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const envelope = value as Partial<WireEnvelope>;

    return (
        envelope.v === GAME_WIRE_VERSION &&
        typeof envelope.serverNow === 'string' &&
        (envelope.gameRef === null || typeof envelope.gameRef === 'string')
    );
}

/**
 * Un corps JSON est-il un paquet `GameStatePacket` de la version du fil ?
 * Lu sur la réponse d'une resynchronisation et sur celle d'un geste solo
 * (`solo.reveal`, `solo.skip`, `solo.next`, 60 § 16.5), qui rend le paquet
 * à jour.
 */
export function isGameStatePacket(value: unknown): value is GameStatePacket {
    return (
        isEnvelope(value) &&
        'self' in value &&
        'seats' in value &&
        'mode' in value
    );
}

/** Références d'images fusionnées par palier, la plus récente l'emporte. */
function mergeImages(
    known: readonly TierImageRef[],
    incoming: readonly TierImageRef[],
): TierImageRef[] {
    const byTier = new Map<number, TierImageRef>();

    for (const image of [...known, ...incoming]) {
        byTier.set(image.tierIndex, image);
    }

    return [...byTier.values()].toSorted(
        (left, right) => left.tierIndex - right.tierIndex,
    );
}

/**
 * La manche, ses références réduites à ce que le serveur sert encore : aucune
 * pour une manche annulée ; pour une manche close, les seuls paliers ouverts
 * avant sa fin (`Tᵢ < endedAt`, 60 § 7.2) — la référence d'un palier que la
 * fin anticipée a empêché de s'ouvrir ne serait plus que refusée.
 */
function withServableImages(round: RoundState): RoundState {
    if (round.phase === 'cancelled') {
        return round.images.length === 0 ? round : { ...round, images: [] };
    }

    if (round.endedAt === null) {
        return round;
    }

    const endedMs = parseIsoMs(round.endedAt);
    const origin = startsAtMs(round);
    const images = round.images.filter((image) => {
        const tier = round.tiers.find(
            (each) => each.tierIndex === image.tierIndex,
        );

        return tier !== undefined && origin + tier.startsAtOffsetMs < endedMs;
    });

    return images.length === round.images.length ? round : { ...round, images };
}

function sortRounds(rounds: readonly RoundState[]): RoundState[] {
    return rounds.toSorted(
        (left, right) =>
            startsAtMs(left) - startsAtMs(right) ||
            left.sequenceIndex - right.sequenceIndex,
    );
}

/** Dernier palier ouvert selon un paquet. */
function openedTierOf(round: RoundState): number {
    switch (round.phase) {
        case 'scheduled':
            return 0;
        case 'running':
            return round.currentTierIndex ?? 0;
        default:
            return round.tiers.length;
    }
}

/**
 * Le siège est-il membre de la manche `roundNumber` d'après les sièges de la
 * partie ? Toute participation porte une manche d'entrée non nulle (1 au
 * lancement, celle d'un retardataire admis) : une vue à `firstRoundNumber`
 * nul est une vue de LOBBY — un siège qui attend la partie suivante reçoit
 * en partie son propre `seat.updated` de présence, que `upsertSeat()` ajoute
 * aux sièges —, jamais une participation (50 § 15.3).
 */
function memberOf(
    seats: readonly SeatView[],
    publicId: string,
    roundNumber: number,
): boolean {
    const seat = seats.find((each) => each.publicId === publicId);

    return (
        seat !== undefined &&
        !seat.kicked &&
        seat.firstRoundNumber !== null &&
        seat.firstRoundNumber <= roundNumber
    );
}

function stateFromPacket(
    packet: GameStatePacket,
    settings: RoomSettingsState | null,
): GameStoreState {
    const round = packet.round;
    const choices = packet.self.input?.choices ?? null;

    return {
        serverNow: packet.serverNow,
        gameRef: packet.gameRef,
        mode: packet.mode,
        channels: packet.channels,
        status: packet.status,
        roundsCount: packet.roundsCount,
        roundsCompleted: packet.roundsCompleted,
        framesPerRound: packet.framesPerRound,
        inputDifficulty: packet.inputDifficulty,
        maxAnswerLength: packet.maxAnswerLength,
        seats: packet.seats,
        pause: packet.pause,
        rounds: round === null ? [] : [round],
        self: packet.self,
        offeredChoices:
            round !== null && choices !== null
                ? { sequenceIndex: round.sequenceIndex, payload: choices }
                : null,
        leaderboard: packet.leaderboard,
        podium: packet.podium,
        nextTransitionAt: packet.nextTransitionAt,
        settings,
        exit: null,
        resyncing: false,
        resyncCount: 0,
    };
}

export function createGameStore(options: GameStoreOptions): GameStore {
    const { now, heartbeatIntervalMs } = options;
    const listeners = new Set<() => void>();

    let state = stateFromPacket(options.initial, options.settings ?? null);
    let lastPacket: GameStatePacket = options.initial;
    let lastSettings: RoomSettingsState | null = options.settings ?? null;

    /** `serverNow` du dernier paquet : tout événement antérieur y est compris. */
    let baselineMs = parseIsoMs(options.initial.serverNow);
    /** `serverNow` de l'état des réglages courant. */
    let settingsBaselineMs = baselineMs;
    /** Plus grand `serverNow` appliqué, paquet ou événement (filet). */
    let lastMessageMs = baselineMs;
    /** Avance de préchargement de la partie, déduite d'une référence d'image. */
    let leadMs: number | null = null;

    const meta = new Map<number, RoundMeta>();
    const seenKeys = new Set<string>();
    const choicesReceived = new Set<number>();
    const guardsHandled = new Set<string>();
    const soloPollsDone = new Set<number>();
    /** Événements d'une partie pas encore lancée à nos yeux, par `gameRef`. */
    const pendingByGame = new Map<string, QueuedEvent[]>();

    let choicesDeadline: { sequenceIndex: number; atMs: number } | null = null;
    let nextTransitionHandled = false;
    let watchdogHandledMs: number | null = null;
    let retryAtMs: number | null = null;

    /**
     * Manche à laquelle appartient `state.self.input` : la vue de saisie ne
     * porte pas de `sequenceIndex`, et aucune relecture n'a lieu entre deux
     * manches. Nulle si la saisie est inconnue.
     */
    let inputSequenceIndex: number | null =
        options.initial.self.input !== null && options.initial.round !== null
            ? options.initial.round.sequenceIndex
            : null;

    let inFlight = false;
    let again: ResyncReason | null = null;
    /**
     * Événements appliqués depuis le dernier paquet : un paquet qui leur est
     * antérieur (prop rechargée pendant qu'ils arrivaient, relecture en vol)
     * ne les comprend pas, et ils sont rejoués sur lui.
     */
    let applied: QueuedEvent[] = [];

    let timer: ReturnType<typeof setTimeout> | null = null;
    let unsubscribeClock: (() => void) | null = null;
    let disposed = false;

    trackPacketRound(options.initial, baselineMs);

    // --- Notification -----------------------------------------------------

    function commit(next: GameStoreState): void {
        if (next === state) {
            return;
        }

        state = next;

        for (const listener of listeners) {
            listener();
        }
    }

    // --- Manches ------------------------------------------------------------

    function roundIndex(sequenceIndex: number): number {
        return state.rounds.findIndex(
            (round) => round.sequenceIndex === sequenceIndex,
        );
    }

    function replaceRound(round: RoundState): void {
        commit({
            ...state,
            rounds: sortRounds([
                ...state.rounds.filter(
                    (each) => each.sequenceIndex !== round.sequenceIndex,
                ),
                round,
            ]),
        });
    }

    /** Oublie les manches jouées avant `round`, qui vient de commencer. */
    function dropRoundsBefore(round: RoundState): void {
        const origin = startsAtMs(round);
        const kept = state.rounds.filter(
            (each) =>
                each.sequenceIndex === round.sequenceIndex ||
                startsAtMs(each) > origin,
        );

        if (kept.length !== state.rounds.length) {
            for (const each of state.rounds) {
                if (!kept.includes(each)) {
                    meta.delete(each.sequenceIndex);
                }
            }

            commit({ ...state, rounds: kept });
        }
    }

    function learnLead(round: RoundState): void {
        for (const image of round.images) {
            const tier = round.tiers.find(
                (each) => each.tierIndex === image.tierIndex,
            );

            if (tier !== undefined) {
                leadMs =
                    startsAtMs(round) +
                    tier.startsAtOffsetMs -
                    parseIsoMs(image.fetchNotBefore);

                return;
            }
        }
    }

    function trackPacketRound(packet: GameStatePacket, atMs: number): void {
        const round = packet.round;

        if (round === null) {
            return;
        }

        meta.set(round.sequenceIndex, {
            scheduledAtMs: atMs,
            openedTier: openedTierOf(round),
        });
        learnLead(round);

        if (packet.self.input?.choices != null) {
            choicesReceived.add(round.sequenceIndex);
        }
    }

    // --- Paquets ----------------------------------------------------------------

    function applyPacket(packet: GameStatePacket): void {
        if (disposed || packet === lastPacket) {
            return;
        }

        const atMs = parseIsoMs(packet.serverNow);

        if (atMs < baselineMs) {
            return;
        }

        options.recalibrate?.(packet.serverNow);
        lastPacket = packet;

        const gameChanged = packet.gameRef !== state.gameRef;
        const previousRounds = gameChanged ? [] : state.rounds;

        if (gameChanged) {
            meta.clear();
            seenKeys.clear();
            choicesReceived.clear();
            guardsHandled.clear();
            leadMs = null;
            choicesDeadline = null;
        }

        baselineMs = atMs;
        lastMessageMs = Math.max(lastMessageMs, atMs);
        nextTransitionHandled = false;
        soloPollsDone.clear();

        const next = stateFromPacket(packet, state.settings);
        const round = packet.round;

        inputSequenceIndex =
            packet.self.input !== null && round !== null
                ? round.sequenceIndex
                : null;

        if (round !== null) {
            const known = previousRounds.find(
                (each) => each.sequenceIndex === round.sequenceIndex,
            );
            const merged: RoundState = withServableImages({
                ...round,
                images: mergeImages(known?.images ?? [], round.images),
            });
            const origin = startsAtMs(round);
            // La manche suivante déjà programmée reste ; une révélation déjà
            // reçue reste jusqu'à son terme quand le paquet porte la manche
            // suivante dans les `preload_lead_ms` finales (§ 12.3).
            const kept = previousRounds.filter(
                (each) =>
                    each.sequenceIndex !== round.sequenceIndex &&
                    (startsAtMs(each) > origin ||
                        (round.phase === 'scheduled' &&
                            each.phase === 'revealing')),
            );

            next.rounds = sortRounds([merged, ...kept]);

            for (const [sequenceIndex] of meta) {
                if (
                    !next.rounds.some(
                        (each) => each.sequenceIndex === sequenceIndex,
                    )
                ) {
                    meta.delete(sequenceIndex);
                }
            }

            trackPacketRound({ ...packet, round: merged }, atMs);
        } else {
            meta.clear();
        }

        next.exit = state.exit;
        next.resyncing = state.resyncing;
        next.resyncCount = state.resyncCount;

        if (!gameChanged && next.offeredChoices === null && round !== null) {
            const offered = state.offeredChoices;

            if (offered?.sequenceIndex === round.sequenceIndex) {
                next.offeredChoices = offered;
            }
        }

        commit(next);

        // Des événements d'une partie que le lobby ne connaissait pas encore.
        if (packet.gameRef !== null) {
            const pending = pendingByGame.get(packet.gameRef) ?? [];

            pendingByGame.clear();

            for (const queued of pending) {
                apply(queued.name, queued.event, true);
            }
        }

        // Des événements déjà appliqués que ce paquet, plus ancien qu'eux, ne
        // comprend pas : rejoués sur lui, et gardés pour un paquet suivant
        // qui leur serait encore antérieur.
        applied = applied.filter(
            (queued) => parseIsoMs(queued.event.serverNow) > atMs,
        );

        try {
            for (const queued of applied) {
                apply(queued.name, queued.event, true);
            }
        } catch {
            requestResync('malformed');
        }

        schedule();
    }

    function setSettings(settings: RoomSettingsState | null): void {
        if (disposed || settings === lastSettings) {
            return;
        }

        lastSettings = settings;
        settingsBaselineMs = lastMessageMs;
        commit({ ...state, settings });
    }

    // --- Événements -------------------------------------------------------------

    function eventKey(name: GameEventName, event: GameEvent): string | null {
        const payload = event as GameEvent & Record<string, unknown>;
        const parts = [event.gameRef ?? '-', name];

        switch (name) {
            case 'tier.opened':
                parts.push(
                    String(payload.sequenceIndex),
                    String(payload.tierIndex),
                );
                break;
            case 'round.closed':
            case 'round.revealed':
            case 'round.cancelled':
            case 'seat.choices':
                parts.push(String(payload.sequenceIndex));
                break;
            case 'player.locked':
                parts.push(
                    String(payload.sequenceIndex),
                    String(payload.publicId),
                );
                break;
            case 'game.paused':
                parts.push(String(payload.pausedAt));
                break;
            case 'game.resumed':
                parts.push(String(payload.resumedAt));
                break;
            case 'game.launched':
            case 'game.ended':
                break;
            default:
                return null;
        }

        return parts.join('|');
    }

    function receive(name: GameEventName, payload: unknown): void {
        if (disposed || state.exit !== null || !isEnvelope(payload)) {
            return;
        }

        const event = payload as GameEvent;

        options.recalibrate?.(event.serverNow);

        try {
            apply(name, event, false);

            // Au journal, pour un paquet plus ancien qui arriverait ensuite.
            // `settings.changed` a sa propre ligne de base : aucun paquet ne
            // porte les réglages, son rejeu serait sans effet.
            if (
                name !== 'settings.changed' &&
                parseIsoMs(event.serverNow) > baselineMs
            ) {
                applied.push({ name, event });
            }
        } catch {
            // Une charge illisible est un défaut du serveur : l'état se relit.
            requestResync('malformed');
        }

        schedule();
    }

    function apply(
        name: GameEventName,
        event: GameEvent,
        replay: boolean,
    ): void {
        const atMs = parseIsoMs(event.serverNow);

        if (name === 'settings.changed') {
            if (atMs > settingsBaselineMs) {
                const changed = event as GameEvent<'settings.changed'>;

                settingsBaselineMs = atMs;
                commit({
                    ...state,
                    settings: {
                        settings: changed.settings,
                        warnings: changed.warnings,
                        pool: changed.pool,
                    },
                });
            }

            return;
        }

        // Déjà compris dans le dernier paquet.
        if (atMs <= baselineMs) {
            return;
        }

        if (GAME_BOUND.has(name) && name !== 'game.launched') {
            if (event.gameRef === null) {
                return;
            }

            if (state.gameRef === null) {
                // `round.scheduled` peut précéder `game.launched` ; tout
                // autre événement de partie dit un lancement manqué.
                if (name === 'round.scheduled') {
                    const queue = pendingByGame.get(event.gameRef) ?? [];

                    queue.push({ name, event });
                    pendingByGame.set(event.gameRef, queue);
                } else {
                    requestResync('unknown_game');
                }

                return;
            }

            if (event.gameRef !== state.gameRef) {
                requestResync('unknown_game');

                return;
            }
        }

        const key = eventKey(name, event);

        if (key !== null && !replay) {
            if (seenKeys.has(key)) {
                return;
            }

            seenKeys.add(key);
        }

        lastMessageMs = Math.max(lastMessageMs, atMs);

        const handle = handlers[name] as (
            event: GameEvent,
            atMs: number,
        ) => void;

        handle(event, atMs);
    }

    function stepJump(): void {
        requestResync('step_jump');
    }

    const handlers: {
        [N in GameEventName]: (event: GameEvent<N>, atMs: number) => void;
    } = {
        'seat.joined': (event) => upsertSeat(event.seat),

        'seat.updated': (event) => upsertSeat(event.seat),

        'host.changed': (event) => {
            commit({
                ...state,
                seats: state.seats.map((seat) => ({
                    ...seat,
                    isHost: seat.publicId === event.hostPublicId,
                })),
                self: {
                    ...state.self,
                    isHost: state.self.publicId === event.hostPublicId,
                },
            });
        },

        'settings.changed': () => undefined,

        'room.replayed': (event, atMs) => {
            settingsBaselineMs = Math.max(settingsBaselineMs, atMs);
            meta.clear();
            seenKeys.clear();
            choicesReceived.clear();
            guardsHandled.clear();
            pendingByGame.clear();
            leadMs = null;
            choicesDeadline = null;
            inputSequenceIndex = null;

            const settings: RoomSettingsState = {
                settings: event.settings,
                warnings: event.warnings,
                pool: event.pool,
            };

            commit({
                ...state,
                serverNow: event.serverNow,
                gameRef: null,
                status: null,
                roundsCount: null,
                roundsCompleted: null,
                framesPerRound: null,
                inputDifficulty: null,
                maxAnswerLength: null,
                seats: state.seats.map((seat) => ({
                    ...seat,
                    firstRoundNumber: null,
                })),
                pause: null,
                rounds: [],
                self: {
                    ...state.self,
                    member: false,
                    participates: false,
                    input: null,
                    ownScore: 0,
                },
                offeredChoices: null,
                leaderboard: EMPTY_LEADERBOARD,
                podium: null,
                nextTransitionAt: null,
                settings,
            });
        },

        'game.launched': (event) => launch(event),

        'room.archived': () => leave('archived'),

        'round.scheduled': (event, atMs) => {
            const index = roundIndex(event.round.sequenceIndex);
            const known = index === -1 ? null : state.rounds[index];
            const tracked = meta.get(event.round.sequenceIndex);

            if (known !== null) {
                // Réémis tant que la manche est `pending` ; jamais après T₁.
                if (
                    known.phase !== 'scheduled' ||
                    (tracked !== undefined && atMs <= tracked.scheduledAtMs)
                ) {
                    return;
                }
            }

            const round: RoundState = {
                ...event.round,
                phase: 'scheduled',
                currentTierIndex: null,
                images: mergeImages(known?.images ?? [], [event.image]),
                locked: [],
                endedAt: null,
                revealStartsAt: null,
                revealEndsAt: null,
                reveal: null,
            };

            meta.set(round.sequenceIndex, {
                scheduledAtMs: atMs,
                openedTier: 0,
            });
            for (const key of guardsHandled) {
                if (key.startsWith(`${round.sequenceIndex}:`)) {
                    guardsHandled.delete(key);
                }
            }
            learnLead(round);
            replaceRound(round);
        },

        'tier.opened': (event) => {
            const index = roundIndex(event.sequenceIndex);

            if (index === -1) {
                requestResync('unknown_round');

                return;
            }

            const round = state.rounds[index];
            const tracked = meta.get(event.sequenceIndex) ?? {
                scheduledAtMs: baselineMs,
                openedTier: openedTierOf(round),
            };

            if (round.phase !== 'scheduled' && round.phase !== 'running') {
                return;
            }

            if (event.tierIndex <= tracked.openedTier) {
                return;
            }

            const jumped = event.tierIndex > tracked.openedTier + 1;
            const next: RoundState = {
                ...round,
                phase: 'running',
                currentTierIndex: event.tierIndex,
                images:
                    event.next === null
                        ? round.images
                        : mergeImages(round.images, [event.next]),
            };

            meta.set(event.sequenceIndex, {
                ...tracked,
                openedTier: event.tierIndex,
            });
            replaceRound(next);

            if (event.tierIndex === 1) {
                dropRoundsBefore(next);
                enterRound(next);
            }

            if (
                state.mode === 'multiplayer' &&
                next.choicesAtTierIndex === event.tierIndex &&
                !choicesReceived.has(event.sequenceIndex)
            ) {
                choicesDeadline = {
                    sequenceIndex: event.sequenceIndex,
                    atMs: now() + heartbeatIntervalMs,
                };
            }

            if (jumped) {
                stepJump();
            }
        },

        'player.locked': (event) => {
            const index = roundIndex(event.sequenceIndex);

            if (index === -1) {
                requestResync('unknown_round');

                return;
            }

            const round = state.rounds[index];

            if (round.locked.some((each) => each.publicId === event.publicId)) {
                return;
            }

            replaceRound({
                ...round,
                locked: [
                    ...round.locked,
                    { publicId: event.publicId, lockRank: event.lockRank },
                ].toSorted((left, right) => left.lockRank - right.lockRank),
            });

            const input = state.self.input;

            if (
                event.publicId === state.self.publicId &&
                event.sequenceIndex === inputSequenceIndex &&
                input !== null &&
                input.inputState !== 'locked'
            ) {
                commit({
                    ...state,
                    self: {
                        ...state.self,
                        input: { ...input, inputState: 'locked' },
                    },
                });
            }
        },

        'round.closed': (event) => {
            const index = roundIndex(event.sequenceIndex);

            if (index === -1) {
                requestResync('unknown_round');

                return;
            }

            const round = state.rounds[index];

            if (round.phase !== 'scheduled' && round.phase !== 'running') {
                return;
            }

            // Un palier que la fin anticipée a empêché de s'ouvrir n'est plus
            // servi (`Tᵢ < endedAt`, 60 § 7.2) : sa référence est oubliée.
            replaceRound(
                withServableImages({
                    ...round,
                    phase: 'closed',
                    endedAt: event.endedAt,
                    revealStartsAt: event.revealStartsAt,
                    revealEndsAt: event.revealEndsAt,
                }),
            );

            if (round.phase === 'scheduled') {
                stepJump();
            }
        },

        'round.revealed': (event) => {
            const index = roundIndex(event.sequenceIndex);

            if (index === -1) {
                requestResync('unknown_round');

                return;
            }

            const round = state.rounds[index];

            if (round.phase === 'revealing' || round.phase === 'cancelled') {
                return;
            }

            // La liste de la révélation est close : les seuls paliers ouverts
            // (D14 du 23/09), aucun pour un film suspendu (§ 15.4).
            replaceRound(
                withServableImages({
                    ...round,
                    phase: 'revealing',
                    revealEndsAt: event.revealEndsAt,
                    images: mergeImages([], event.images),
                    reveal: { movie: event.movie, finders: event.finders },
                }),
            );
            commit({ ...state, leaderboard: event.leaderboard });

            if (round.phase !== 'closed') {
                stepJump();
            }
        },

        'round.cancelled': (event) => {
            const index = roundIndex(event.sequenceIndex);

            if (index === -1) {
                requestResync('unknown_round');

                return;
            }

            const round = state.rounds[index];

            if (round.phase === 'cancelled' || round.phase === 'revealing') {
                return;
            }

            // Une manche annulée ne sert plus aucune image (60 § 7.2) ; l'écran
            // n'en montre aucune (`game.round.cancelled`, 90).
            replaceRound({ ...round, phase: 'cancelled', images: [] });
        },

        'game.paused': (event) => {
            meta.clear();
            commit({
                ...state,
                status: 'paused',
                pause: {
                    pausedAt: event.pausedAt,
                    interruptsAt: event.interruptsAt,
                },
                rounds: [],
            });
        },

        'game.resumed': () => {
            commit({ ...state, status: 'running', pause: null });
        },

        'game.ended': (event) => {
            meta.clear();
            commit({
                ...state,
                status: event.podium.gameStatus,
                roundsCompleted: event.podium.roundsCompleted,
                pause: null,
                rounds: [],
                podium: event.podium,
                nextTransitionAt: null,
            });
        },

        'seat.choices': (event) => {
            if (roundIndex(event.sequenceIndex) === -1) {
                requestResync('unknown_round');

                return;
            }

            choicesReceived.add(event.sequenceIndex);

            if (choicesDeadline?.sequenceIndex === event.sequenceIndex) {
                choicesDeadline = null;
            }

            const payload: ChoicesPayload = {
                choices: event.choices,
                useOriginalTitle: event.useOriginalTitle,
                lang: event.lang,
            };
            const input = state.self.input;

            commit({
                ...state,
                offeredChoices: { sequenceIndex: event.sequenceIndex, payload },
                self:
                    input === null || inputSequenceIndex !== event.sequenceIndex
                        ? state.self
                        : {
                              ...state.self,
                              input: { ...input, choices: payload },
                          },
            });
        },

        'seat.superseded': () => requestResync('superseded'),

        'seat.kicked': () => leave('kicked'),
    };

    function upsertSeat(seat: SeatView): void {
        const exists = state.seats.some(
            (each) => each.publicId === seat.publicId,
        );

        // L'expulsion se lit aussi dans le siège diffusé au salon, si
        // `seat.kicked` (ciblé) s'est perdu.
        if (seat.publicId === state.self.publicId && seat.kicked) {
            leave('kicked');

            return;
        }

        commit({
            ...state,
            seats: exists
                ? state.seats.map((each) =>
                      each.publicId === seat.publicId ? seat : each,
                  )
                : [...state.seats, seat],
            self:
                seat.publicId === state.self.publicId
                    ? { ...state.self, isHost: seat.isHost }
                    : state.self,
        });
    }

    function launch(event: GameEvent<'game.launched'>): void {
        const gameRef = event.gameRef;

        if (gameRef === null || gameRef === state.gameRef) {
            return;
        }

        meta.clear();
        seenKeys.clear();
        choicesReceived.clear();
        guardsHandled.clear();
        leadMs = null;
        choicesDeadline = null;
        inputSequenceIndex = null;
        seenKeys.add(`${gameRef}|game.launched`);

        commit({
            ...state,
            serverNow: event.serverNow,
            gameRef,
            mode: event.mode,
            status: 'running',
            roundsCount: event.roundsCount,
            roundsCompleted: 0,
            framesPerRound: event.framesPerRound,
            inputDifficulty: event.inputDifficulty,
            // Non porté par `game.launched` : les réglages du salon au
            // lancement, figés dans le snapshot ; un confort, la borne reste
            // serveur (70 § 16).
            maxAnswerLength: state.settings?.settings.maxAnswerLength ?? null,
            seats: event.seats,
            pause: null,
            rounds: [],
            self: {
                ...state.self,
                isHost: event.seats.some(
                    (seat) =>
                        seat.publicId === state.self.publicId && seat.isHost,
                ),
                member: memberOf(event.seats, state.self.publicId, 1),
                participates: false,
                input: null,
                ownScore: 0,
            },
            offeredChoices: null,
            leaderboard: EMPTY_LEADERBOARD,
            podium: null,
            nextTransitionAt: null,
        });

        const pending = (pendingByGame.get(gameRef) ?? []).toSorted(
            (left, right) =>
                parseIsoMs(left.event.serverNow) -
                parseIsoMs(right.event.serverNow),
        );

        pendingByGame.clear();

        for (const queued of pending) {
            apply(queued.name, queued.event, true);
        }

        // Le paquet de `room.state`, construit après le commit du lancement,
        // porte la manche 1 programmée (60 § 11.8).
        if (!state.rounds.some((round) => round.roundNumber === 1)) {
            requestResync('launched');
        }
    }

    /**
     * Le siège entre dans `round`, dont le palier 1 vient de s'ouvrir, sans
     * relecture (le cas normal entre deux manches) : appartenance recalculée
     * sur les sièges (un retardataire devient membre à sa première manche),
     * ligne de manche présumée pour un membre non parti (`OpenTier(1)`,
     * 60 § 6.3), et saisie d'une manche précédente oubliée — inconnue, elle
     * reste éligible au déclencheur du QCM. Une saisie déjà reçue pour cette
     * manche (réponse de soumission arrivée avant `tier.opened`) est gardée.
     */
    function enterRound(round: RoundState): void {
        const publicId = state.self.publicId;
        const member = memberOf(state.seats, publicId, round.roundNumber);
        const seat = state.seats.find((each) => each.publicId === publicId);
        const kept = inputSequenceIndex === round.sequenceIndex;

        if (!kept) {
            inputSequenceIndex = null;
        }

        commit({
            ...state,
            self: {
                ...state.self,
                member,
                participates: kept
                    ? state.self.participates
                    : member && seat?.connection !== 'left',
                input: kept ? state.self.input : null,
            },
        });
    }

    function leave(exit: GameExit): void {
        if (state.exit !== null) {
            return;
        }

        stopTimer();
        commit({ ...state, exit });
    }

    function applySubmission(
        sequenceIndex: number,
        result: SubmissionResult,
    ): void {
        if (disposed || roundIndex(sequenceIndex) === -1) {
            return;
        }

        // Jamais la saisie d'une autre manche comme base (verrou, essais).
        const current =
            inputSequenceIndex === sequenceIndex ? state.self.input : null;
        const input = current ?? {
            inputState: result.inputState,
            attemptsLeft: 0,
            choices:
                state.offeredChoices?.sequenceIndex === sequenceIndex
                    ? state.offeredChoices.payload
                    : null,
            locked: null,
        };

        const next =
            result.result === 'accepted'
                ? {
                      ...input,
                      inputState: result.inputState,
                      locked: {
                          lockRank: result.lockRank,
                          tierIndex: result.tierIndex,
                          pointsTier: result.pointsTier,
                          pointsBonus: result.pointsBonus,
                          pointsTotal: result.pointsTotal,
                      },
                  }
                : result.result === 'rejected'
                  ? {
                        ...input,
                        inputState: result.inputState,
                        attemptsLeft: result.attemptsLeft,
                    }
                  : { ...input, inputState: result.inputState };

        inputSequenceIndex = sequenceIndex;
        commit({
            ...state,
            self: { ...state.self, participates: true, input: next },
        });
        schedule();
    }

    // --- Resynchronisation --------------------------------------------------------

    function requestResync(reason: ResyncReason): void {
        if (disposed || state.exit !== null) {
            return;
        }

        if (inFlight) {
            again ??= reason;

            return;
        }

        inFlight = true;
        retryAtMs = null;
        commit({ ...state, resyncing: true });

        let pending: Promise<ResyncOutcome>;

        try {
            pending = options.resync(reason);
        } catch {
            pending = Promise.resolve({ kind: 'failed' });
        }

        void pending
            .catch((): ResyncOutcome => ({ kind: 'failed' }))
            .then((outcome) => {
                inFlight = false;

                if (disposed) {
                    return;
                }

                if (outcome.kind === 'packet') {
                    // Les événements reçus pendant le vol et postérieurs au
                    // paquet y sont rejoués (journal `applied`).
                    applyPacket(outcome.packet);
                    commit({
                        ...state,
                        resyncing: inFlight,
                        resyncCount: state.resyncCount + 1,
                    });
                } else if (outcome.kind === 'lost') {
                    commit({ ...state, resyncing: false });
                    leave('lost');
                } else {
                    retryAtMs = now() + heartbeatIntervalMs;
                    commit({ ...state, resyncing: false });
                }

                const queued = again;

                again = null;

                if (queued !== null) {
                    requestResync(queued);
                }

                schedule();
            });
    }

    // --- Minuteurs ------------------------------------------------------------------

    /** Paliers des manches connues à surveiller : garde, fin, URL détenue. */
    function guardSlots(): Array<{
        key: string;
        guardMs: number;
        endMs: number;
        held: boolean;
    }> {
        if (leadMs === null) {
            return [];
        }

        const lead = leadMs;
        const slots: Array<{
            key: string;
            guardMs: number;
            endMs: number;
            held: boolean;
        }> = [];

        for (const round of state.rounds) {
            if (
                (round.phase !== 'scheduled' && round.phase !== 'running') ||
                round.endedAt !== null
            ) {
                continue;
            }

            const origin = startsAtMs(round);

            for (const tier of round.tiers) {
                const opensMs = origin + tier.startsAtOffsetMs;

                slots.push({
                    key: `${round.sequenceIndex}:${tier.tierIndex}`,
                    guardMs: opensMs - lead,
                    endMs: opensMs + tier.durationMs,
                    held: round.images.some(
                        (image) => image.tierIndex === tier.tierIndex,
                    ),
                });
            }
        }

        return slots;
    }

    /** Instants que la chronologie connue explique : étapes et gardes détenues. */
    function explained(instantMs: number): boolean {
        const known: number[] = [];

        for (const round of state.rounds) {
            const origin = startsAtMs(round);

            known.push(origin + round.durationMs);

            for (const tier of round.tiers) {
                known.push(origin + tier.startsAtOffsetMs);
            }

            for (const image of round.images) {
                known.push(parseIsoMs(image.fetchNotBefore));
            }

            for (const instant of [
                round.endedAt,
                round.revealStartsAt,
                round.revealEndsAt,
            ]) {
                if (instant !== null) {
                    known.push(parseIsoMs(instant));
                }
            }
        }

        if (state.pause !== null) {
            known.push(parseIsoMs(state.pause.interruptsAt));
        }

        return known.includes(instantMs);
    }

    /** Prochaine étape qui émet un événement au salon, d'après l'état connu. */
    function expectedEventMs(): number | null {
        const candidates: number[] = [];

        if (state.status === 'paused' && state.pause !== null) {
            candidates.push(parseIsoMs(state.pause.interruptsAt));
        }

        for (const round of state.rounds) {
            const origin = startsAtMs(round);
            const tracked = meta.get(round.sequenceIndex);

            if (round.phase === 'scheduled' || round.phase === 'running') {
                const opened = tracked?.openedTier ?? openedTierOf(round);
                const nextTier = round.tiers.find(
                    (tier) => tier.tierIndex === opened + 1,
                );

                candidates.push(
                    nextTier === undefined
                        ? origin + round.durationMs
                        : origin + nextTier.startsAtOffsetMs,
                );
            } else if (
                round.phase === 'closed' &&
                round.revealStartsAt !== null
            ) {
                candidates.push(parseIsoMs(round.revealStartsAt));
            } else if (
                round.phase === 'revealing' &&
                round.revealEndsAt !== null
            ) {
                candidates.push(parseIsoMs(round.revealEndsAt));
            }
        }

        const future = candidates.filter((instant) => instant > lastMessageMs);

        if (future.length > 0) {
            return Math.min(...future);
        }

        if (state.nextTransitionAt !== null) {
            const instant = parseIsoMs(state.nextTransitionAt);

            return instant > lastMessageMs ? instant : null;
        }

        return null;
    }

    function choicesEligible(sequenceIndex: number): boolean {
        if (
            state.mode !== 'multiplayer' ||
            choicesReceived.has(sequenceIndex)
        ) {
            return false;
        }

        const round = state.rounds.find(
            (each) => each.sequenceIndex === sequenceIndex,
        );
        const publicId = state.self.publicId;

        if (
            round === undefined ||
            round.locked.some((each) => each.publicId === publicId)
        ) {
            return false;
        }

        // Seule la saisie de CETTE manche compte ; inconnue, le siège est
        // éligible s'il est membre de la manche.
        const input =
            inputSequenceIndex === sequenceIndex ? state.self.input : null;

        return input === null
            ? memberOf(state.seats, publicId, round.roundNumber)
            : ACCEPTS_CHOICE.has(input.inputState);
    }

    /** Instants de sondage du solo : `nextTransitionAt` et les `fetchNotBefore`. */
    function soloPollInstants(): number[] {
        const instants: number[] = [];

        if (state.nextTransitionAt !== null && !nextTransitionHandled) {
            instants.push(parseIsoMs(state.nextTransitionAt));
        }

        for (const round of state.rounds) {
            for (const image of round.images) {
                const instant = parseIsoMs(image.fetchNotBefore);

                if (!soloPollsDone.has(instant)) {
                    instants.push(instant);
                }
            }
        }

        return instants;
    }

    /** Évalue tout ce qui est échu, puis réarme le minuteur. */
    function check(): void {
        timer = null;

        if (disposed || state.exit !== null) {
            return;
        }

        const nowMs = now();
        let reason: ResyncReason | null = null;

        if (retryAtMs !== null && retryAtMs <= nowMs) {
            retryAtMs = null;
            reason = 'retry';
        }

        if (state.gameRef !== null) {
            for (const slot of guardSlots()) {
                if (guardsHandled.has(slot.key) || slot.guardMs > nowMs) {
                    continue;
                }

                guardsHandled.add(slot.key);

                if (!slot.held && nowMs < slot.endMs) {
                    reason ??= 'tier_guard';
                }
            }

            if (state.mode === 'solo') {
                if (
                    state.nextTransitionAt !== null &&
                    !nextTransitionHandled &&
                    parseIsoMs(state.nextTransitionAt) <= nowMs
                ) {
                    nextTransitionHandled = true;
                    reason ??= 'solo_poll';
                }

                for (const round of state.rounds) {
                    for (const image of round.images) {
                        const instant = parseIsoMs(image.fetchNotBefore);

                        if (instant <= nowMs && !soloPollsDone.has(instant)) {
                            soloPollsDone.add(instant);

                            if (instant > baselineMs) {
                                reason ??= 'solo_poll';
                            }
                        }
                    }
                }
            } else {
                if (
                    state.nextTransitionAt !== null &&
                    !nextTransitionHandled &&
                    parseIsoMs(state.nextTransitionAt) <= nowMs
                ) {
                    nextTransitionHandled = true;

                    if (!explained(parseIsoMs(state.nextTransitionAt))) {
                        reason ??= 'next_transition';
                    }
                }

                const expected = expectedEventMs();

                if (
                    expected !== null &&
                    expected !== watchdogHandledMs &&
                    expected + heartbeatIntervalMs <= nowMs
                ) {
                    watchdogHandledMs = expected;
                    reason ??= 'watchdog';
                }

                if (choicesDeadline !== null && choicesDeadline.atMs <= nowMs) {
                    const { sequenceIndex } = choicesDeadline;

                    choicesDeadline = null;

                    if (choicesEligible(sequenceIndex)) {
                        reason ??= 'choices_missing';
                    }
                }
            }
        }

        if (reason !== null) {
            requestResync(reason);
        }

        schedule();
    }

    /** Le prochain instant où {@link check} a quelque chose à évaluer. */
    function nextWakeMs(): number | null {
        const candidates: number[] = [];

        if (retryAtMs !== null) {
            candidates.push(retryAtMs);
        }

        if (state.gameRef !== null) {
            for (const slot of guardSlots()) {
                if (!guardsHandled.has(slot.key)) {
                    candidates.push(slot.guardMs);
                }
            }

            if (state.mode === 'solo') {
                candidates.push(...soloPollInstants());
            } else {
                if (state.nextTransitionAt !== null && !nextTransitionHandled) {
                    candidates.push(parseIsoMs(state.nextTransitionAt));
                }

                const expected = expectedEventMs();

                if (expected !== null && expected !== watchdogHandledMs) {
                    candidates.push(expected + heartbeatIntervalMs);
                }

                if (choicesDeadline !== null) {
                    candidates.push(choicesDeadline.atMs);
                }
            }
        }

        return candidates.length === 0 ? null : Math.min(...candidates);
    }

    function stopTimer(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    /** Réarme le minuteur unique, seulement tant qu'un abonné observe. */
    function schedule(): void {
        stopTimer();

        if (disposed || listeners.size === 0 || state.exit !== null) {
            return;
        }

        const wakeMs = nextWakeMs();

        if (wakeMs === null) {
            return;
        }

        timer = setTimeout(check, Math.max(0, wakeMs - now()));
    }

    // --- Interface ------------------------------------------------------------------------

    return {
        getState: () => state,

        subscribe(listener: () => void): () => void {
            listeners.add(listener);

            if (listeners.size === 1) {
                unsubscribeClock = options.subscribeClock?.(schedule) ?? null;
            }

            schedule();

            return () => {
                listeners.delete(listener);

                if (listeners.size === 0) {
                    stopTimer();
                    unsubscribeClock?.();
                    unsubscribeClock = null;
                }
            };
        },

        receive,
        applyPacket,
        setSettings,
        applySubmission,
        requestResync,

        dispose(): void {
            disposed = true;
            stopTimer();
            unsubscribeClock?.();
            unsubscribeClock = null;
            listeners.clear();
            pendingByGame.clear();
        },
    };
}
