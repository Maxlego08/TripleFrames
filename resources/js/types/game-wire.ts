/**
 * Types du fil de jeu (spec 60 § 11, contrat C7 § 3) : enveloppe, charges
 * d'événement et paquet de resynchronisation, miroirs de ce que le serveur
 * envoie. Les types d'autres contrats sont **importés, jamais redéclarés**
 * (R-27).
 *
 * Le fichier se remplit en trois lots, pour qu'aucun lot ne déclare un type
 * dont le fichier importé n'existe pas encore (60 § 11.4) :
 * - L60-2 — les seuls types sans import d'une autre spec, ci-dessous ;
 * - L60-4 — `SeatView`, `RoundTimeline`, `RoundState`, `SelfState`,
 *   `GameStatePacket`, qui importent `types/player`, `types/answers` et
 *   `types/scoring` ;
 * - L60-9 — les charges typées en `RoomSettingsState` et l'union
 *   `GameEventName`.
 *
 * Règle 3 : aucun de ces types ne porte, avant la révélation, un titre, un
 * alias, un identifiant interne, un niveau d'image ni l'index d'une bonne
 * proposition. `RevealMovie` ne voyage qu'à la révélation et au
 * récapitulatif.
 */

import type { ChoicesPayload, SeatInputView } from '@/types/answers';
import type { PlayerIdentity } from '@/types/player';
import type { InputDifficulty, RoomSettingsState } from '@/types/room-settings';
import type {
    Leaderboard,
    Podium,
    RoundFinder,
    TierWindow,
} from '@/types/scoring';

/**
 * Instant absolu sur le fil : `YYYY-MM-DDTHH:mm:ss.sssZ`, UTC, à la
 * milliseconde (05 : instants ISO-8601 UTC). Lu par `parseIsoMs()`
 * (`lib/game/wire.ts`). Durées et décalages, eux, sont des entiers en
 * millisecondes.
 */
export type IsoMs = string;

/**
 * En tête de chaque événement et de tout paquet de resynchronisation.
 * `v` suit `GAME_WIRE_VERSION` (`lib/game/wire.ts`), miroir de
 * `GameWire::VERSION` ; `gameRef` est nul pour un événement de lobby hors
 * partie.
 */
export interface WireEnvelope {
    v: 1;
    serverNow: IsoMs;
    gameRef: string | null;
}

/** Locales d'interface activées, miroir de `App\Enums\Locale::cases()`. */
export type LocaleCode = 'fr' | 'en';

/**
 * Référence d'image d'un palier. `url` est produite par le serveur (URL
 * signée de `frame.serve`), jamais reconstruite par Wayfinder ;
 * `fetchNotBefore` = `Tᵢ − preload_lead_ms` : le client ne la demande
 * jamais avant (60 § 7.6).
 */
export interface TierImageRef {
    tierIndex: number;
    url: string;
    fetchNotBefore: IsoMs;
}

/**
 * Un titre de la révélation et la langue dans laquelle il est écrit :
 * `lang` est la balise BCP 47 de la locale atteinte, ou, au rang 3,
 * `original_language` suffixé `-Latn` si la translittération est servie.
 * Le client la pose en attribut `lang` (05).
 */
export interface RevealTitle {
    text: string;
    lang: string;
}

/**
 * Le film révélé, composé par le seul `RevealMovieBuilder` du serveur : un
 * titre par locale activée, le titre original (et sa translittération), sa
 * langue et l'année. Aussi le « paquet de titres » du récapitulatif de fin
 * de partie (`TitlePacket` de `types/scoring.ts`, contrat C13).
 * `letterboxdUrl` : la fiche Letterboxd du film, nulle sans `tmdb_id`
 * (catalogue de démonstration ; D58 du 06/10).
 */
export interface RevealMovie {
    titles: Record<LocaleCode, RevealTitle>;
    originalTitle: string;
    originalTitleLatin: string | null;
    originalLanguage: string;
    year: number | null;
    letterboxdUrl: string | null;
}

// --- L60-4 : sièges, chronologie, paquet de resynchronisation ---------------

/**
 * Un siège tel que le salon le voit : l'identité affichée de 40
 * (`PlayerIdentity`, C5) prolongée de l'état de siège. Miroir de
 * `App\Support\Game\SeatViewPresenter`. En partie, pseudo et avatar sont
 * GELÉS au lancement (`game_player`) ; au lobby, ce sont ceux du siège.
 * `connection` est la présence vive (`player.connection_state`), jamais
 * l'issue figée ; `firstRoundNumber` est nul au lobby.
 */
export interface SeatView extends PlayerIdentity {
    isHost: boolean;
    connection: 'connected' | 'disconnected' | 'left';
    kicked: boolean;
    firstRoundNumber: number | null;
}

/**
 * La chronologie d'une manche, publique dès sa programmation : origine
 * `startsAt`, durée `D` et fenêtres des paliers (`TierWindow`, C13), en
 * millisecondes depuis `startsAt`. `choicesAtTierIndex` est le palier
 * d'apparition du QCM (T₁ en Facile, T_N en Normal), nul en Expert. Aucune
 * durée de film, aucun titre : `D`, `dᵢ` et les valeurs de palier sont des
 * réglages publics.
 */
export interface RoundTimeline {
    sequenceIndex: number;
    roundNumber: number;
    roundsCount: number;
    startsAt: IsoMs;
    durationMs: number;
    tiers: TierWindow[];
    choicesAtTierIndex: number | null;
}

/**
 * La manche portée par un paquet de resynchronisation (60 § 12.3), phase
 * dérivée côté serveur. `images` : au plus deux URL pendant la manche
 * (palier courant, palier suivant dans sa fenêtre de préchargement) ; en
 * révélation, les paliers ouverts. `reveal` n'est non nul qu'à partir de
 * `revealStartsAt` : avant, aucun titre ne voyage. `choicesUnavailable` :
 * le palier du QCM est ouvert sans propositions — cas terminal en Normal
 * (70 § 10.7, D54 du 02/10) —, booléen de manche identique pour tous.
 */
export interface RoundState extends RoundTimeline {
    phase: 'scheduled' | 'running' | 'closed' | 'revealing' | 'cancelled';
    currentTierIndex: number | null;
    images: TierImageRef[];
    locked: { publicId: string; lockRank: number }[];
    endedAt: IsoMs | null;
    revealStartsAt: IsoMs | null;
    revealEndsAt: IsoMs | null;
    reveal: { movie: RevealMovie; finders: RoundFinder[] } | null;
    choicesUnavailable: boolean;
}

/**
 * Ce que le seul siège demandeur sait de lui-même. `seatActive` : cet onglet
 * tient le siège (jeton d'onglet présenté = jeton actif) ; faux, l'onglet
 * est supplanté et passe en lecture seule. `member` : participation
 * éligible à la manche courante ; `participates` : une ligne de manche
 * existe. `input` est nul sans participation ; `ownScore` vaut 0 sans
 * partie.
 */
export interface SelfState {
    publicId: string;
    seatActive: boolean;
    isHost: boolean;
    member: boolean;
    participates: boolean;
    input: SeatInputView | null;
    ownScore: number;
}

/**
 * Le paquet de resynchronisation (60 § 12.1) : prop initiale `state` de
 * toute page `game/*`, réponse de `room.state` et de `solo.state`, à
 * destinataire unique. Sans partie (lobby, solo pas encore lancé) : aucune
 * manche, colonnes de partie nulles, classement vide ; `channels` n'est nul
 * qu'en solo. Le jeton d'onglet n'y figure jamais (prop `seatToken`).
 */
export interface GameStatePacket extends WireEnvelope {
    mode: 'multiplayer' | 'solo';
    channels: { room: string; seat: string } | null;
    status: 'running' | 'paused' | 'completed' | 'interrupted' | null;
    roundsCount: number | null;
    roundsCompleted: number | null;
    framesPerRound: number | null;
    inputDifficulty: 'easy' | 'normal' | 'expert' | null;
    /** `settings_snapshot.maxAnswerLength` ; nul sans partie (écart (r)). */
    maxAnswerLength: number | null;
    seats: SeatView[];
    pause: { pausedAt: IsoMs; interruptsAt: IsoMs } | null;
    round: RoundState | null;
    self: SelfState;
    leaderboard: Leaderboard;
    podium: Podium | null;
    nextTransitionAt: IsoMs | null;
}

/**
 * La prop partagée `realtime` (60 § 10.5), miroir de
 * `App\Support\Realtime\RealtimeClientConfig` : de quoi configurer Echo à
 * l'exécution, jamais depuis une variable figée au build. `key` est la clé
 * PUBLIQUE de l'application Reverb ; `host`, `port` et `scheme` nuls
 * signifient « prendre `window.location` ».
 */
export interface RealtimeConfig {
    key: string;
    host: string | null;
    port: number | null;
    scheme: 'http' | 'https' | null;
    heartbeatIntervalMs: number;
    clockSamples: number;
}

// --- L60-9 : liste close des événements et leurs charges --------------------

/**
 * Les dix-neuf noms `broadcastAs` de la liste close du J1 (60 § 11.3, écart
 * (i) du § 22 bis), miroir de `app/Events/Game/` : `EventPayloadTest` refuse
 * tout écart. Seize sont diffusés au salon, trois ciblés au siège
 * (`seat.choices`, `seat.superseded`, `seat.kicked`). Tout nouvel événement
 * amende 60 et entre ici.
 */
export type GameEventName =
    | 'seat.joined'
    | 'seat.updated'
    | 'host.changed'
    | 'settings.changed'
    | 'room.replayed'
    | 'game.launched'
    | 'room.archived'
    | 'round.scheduled'
    | 'tier.opened'
    | 'player.locked'
    | 'round.closed'
    | 'round.revealed'
    | 'round.cancelled'
    | 'game.paused'
    | 'game.resumed'
    | 'game.ended'
    | 'seat.choices'
    | 'seat.superseded'
    | 'seat.kicked';

/** Charge vide (`{}`) : l'événement ne porte que son enveloppe. */
export type EmptyPayload = Record<never, never>;

/**
 * La charge de chaque événement, **hors enveloppe** (60 § 11.3 et § 11.5).
 * Aucune ne porte, avant `revealStartsAt`, un titre ou un alias hors des
 * quatre chaînes du QCM ciblé, ni un identifiant interne, ni un niveau
 * d'image (§ 11.7). `settings.changed` et `room.replayed` portent l'état des
 * réglages de 50 (`RoomSettingsState`, contrat C0), en données.
 */
export interface GameEventPayloads {
    'seat.joined': { seat: SeatView };
    'seat.updated': { seat: SeatView };
    'host.changed': {
        hostPublicId: string;
        previousHostPublicId: string | null;
    };
    'settings.changed': RoomSettingsState;
    'room.replayed': RoomSettingsState;
    'game.launched': {
        mode: 'multiplayer';
        roundsCount: number;
        framesPerRound: number;
        inputDifficulty: InputDifficulty;
        revealDurationMs: number;
        speedBonus: boolean;
        seats: SeatView[];
    };
    'room.archived': EmptyPayload;
    /** La manche programmée et l'image de son palier 1. */
    'round.scheduled': { round: RoundTimeline; image: TierImageRef };
    /**
     * `opensAt` = `Tᵢ` théorique ; `next` = palier `i + 1`, nul au dernier ;
     * `choicesUnavailable` : vrai au seul palier du QCM d'une manche Normal
     * sans propositions (cas terminal, D54 du 02/10).
     */
    'tier.opened': {
        sequenceIndex: number;
        roundNumber: number;
        tierIndex: number;
        opensAt: IsoMs;
        next: TierImageRef | null;
        choicesUnavailable: boolean;
    };
    'player.locked': {
        sequenceIndex: number;
        publicId: string;
        lockRank: number;
    };
    'round.closed': {
        sequenceIndex: number;
        roundNumber: number;
        endedAt: IsoMs;
        revealStartsAt: IsoMs;
        revealEndsAt: IsoMs;
    };
    /** `images` : les seuls paliers OUVERTS, par `tierIndex` (D14 du 23/09). */
    'round.revealed': {
        sequenceIndex: number;
        roundNumber: number;
        revealEndsAt: IsoMs;
        movie: RevealMovie;
        images: TierImageRef[];
        finders: RoundFinder[];
        leaderboard: Leaderboard;
    };
    /** Ni motif, ni titre. */
    'round.cancelled': { sequenceIndex: number; roundNumber: number };
    'game.paused': { pausedAt: IsoMs; interruptsAt: IsoMs };
    'game.resumed': { resumedAt: IsoMs };
    'game.ended': { podium: Podium };
    /** Ciblé : les quatre chaînes du QCM de CE siège (contrat C11). */
    'seat.choices': { sequenceIndex: number } & ChoicesPayload;
    'seat.superseded': EmptyPayload;
    'seat.kicked': EmptyPayload;
}

/** Un événement tel qu'il arrive : l'enveloppe, puis sa charge. */
export type GameEvent<N extends GameEventName = GameEventName> = WireEnvelope &
    GameEventPayloads[N];
