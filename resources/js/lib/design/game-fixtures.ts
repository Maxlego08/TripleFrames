/**
 * Les paquets de jeu FICTIFS du banc d'essai du design (spec 20 § 13.8,
 * demande du porteur du 08/10) : un `GameStatePacket` complet par scénario
 * `game.*`, typé par les vrais types du fil (`types/game-wire`,
 * `types/scoring`, `types/answers`, `types/player`, `types/room-settings`)
 * pour que `tsc` garantisse leur forme. Module pur : ni React, ni réseau, ni
 * Wayfinder — Vitest les passe au vrai magasin (`applyPacket`).
 *
 * Données de test, jamais des constantes de jeu : les paliers, valeurs,
 * nombre de manches, tentatives et longueur de réponse sont LUS dans les
 * réglages par défaut que le serveur envoie (`settings`) ; seuls les pseudos,
 * les titres de films inventés et les instants relatifs sont écrits ici.
 *
 * **Dates relatives à l'instant présent** (`nowMs`, `serverNow` = maintenant)
 * : l'horloge locale de la page affiche un état cohérent, par exemple une
 * manche démarrée il y a quelques secondes. Le temps court ensuite comme en
 * vrai ; « Rejouer le scénario » recharge l'aperçu.
 *
 * Règle 3, même en fictif : les quatre propositions d'un QCM sont
 * indiscernables — aucun drapeau, aucun ordre qui désigne la cible.
 */

import type { AvatarData, AvatarPresetOption } from '@/types/player';
import type { ChoicesPayload, SeatInputView } from '@/types/answers';
import type {
    GameStatePacket,
    IsoMs,
    LocaleCode,
    RevealMovie,
    RoundState,
    SeatView,
    TierImageRef,
} from '@/types/game-wire';
import type { InputDifficulty, RoomSettingsState } from '@/types/room-settings';
import type {
    Leaderboard,
    LeaderboardRow,
    Podium,
    PodiumStanding,
    RecapEntry,
    RoundFinder,
    TierWindow,
} from '@/types/scoring';
import type { GameScenarioKey } from '@/lib/design/scenario-keys';

/** Ce que le scénario reçoit de l'hôte : instant, images, avatars, réglages. */
export type GameFixtureInput = {
    /** L'instant présent, en millisecondes (`Date.now()`). */
    nowMs: number;
    /**
     * URL d'images à prêter aux paliers, dans l'ordre ; jamais vide (l'hôte
     * substitue une URL qui répond 404 : le cadre dit alors « indisponible »).
     */
    images: readonly string[];
    /** Le catalogue des avatars prédéfinis (`AvatarPresetCatalog::options()`). */
    avatars: readonly AvatarPresetOption[];
    /** L'état des réglages par défaut, calculé par le serveur. */
    settings: RoomSettingsState;
    /** La langue de l'aperçu : celle des propositions du QCM. */
    locale: LocaleCode;
};

/** Ce que l'hôte monte : la page du salon, celle du solo, ou le salon expiré. */
export type GameFixture =
    | {
          page: 'lobby';
          code: string;
          packet: GameStatePacket;
          settings: RoomSettingsState;
      }
    | { page: 'solo'; packet: GameStatePacket }
    | { page: 'room_expired' };

/** Code de salon fictif, dans l'alphabet de `RoomCode`. */
export const DESIGN_ROOM_CODE = 'K7QZ4P';

/** Référence de la partie fictive. */
const GAME_REF = 'design-preview';

const SECOND = 1000;
const MINUTE = 60 * SECOND;

/** Un siège fictif : pseudo varié, longs compris, et sa part du classement. */
type SeatSeed = {
    publicId: string;
    nickname: string;
    score: number;
    correct: number;
    answerMs: number;
    connected: boolean;
};

/**
 * Les sièges fictifs. Le premier est « Vous » (`self.publicId`) ; le dernier
 * est déconnecté. Pseudos de 3 à 20 signes, en écriture latine.
 */
const SEATS: readonly SeatSeed[] = [
    {
        publicId: 'DSGNSEAT0001',
        nickname: 'Maxence',
        score: 1240,
        correct: 6,
        answerMs: 61_200,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0002',
        nickname: 'Camille',
        score: 1515,
        correct: 7,
        answerMs: 54_300,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0003',
        nickname: 'Le Grand Cinéphile',
        score: 1240,
        correct: 5,
        answerMs: 48_900,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0004',
        nickname: 'Yuki',
        score: 980,
        correct: 5,
        answerMs: 70_100,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0005',
        nickname: 'Popcorn Overlord 300',
        score: 760,
        correct: 4,
        answerMs: 66_000,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0006',
        nickname: 'Zoé',
        score: 535,
        correct: 3,
        answerMs: 40_400,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0007',
        nickname: 'Bastien Lefèvre-Roux',
        score: 300,
        correct: 2,
        answerMs: 21_700,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0008',
        nickname: 'Théo',
        score: 120,
        correct: 1,
        answerMs: 9_800,
        connected: true,
    },
    {
        publicId: 'DSGNSEAT0009',
        nickname: 'Ana',
        score: 0,
        correct: 0,
        answerMs: 0,
        connected: false,
    },
];

const SELF_ID = SEATS[0].publicId;
const OTHER_HOST_ID = SEATS[1].publicId;

/** Un film inventé, ses titres FR/EN, son titre original et son année. */
type MovieSeed = {
    fr: string;
    en: string;
    original: string;
    originalLatin: string | null;
    language: string;
    year: number;
};

const MOVIES: readonly MovieSeed[] = [
    {
        fr: 'Le Voyage de Lumière',
        en: 'The Journey of Light',
        original: '光の旅',
        originalLatin: 'Hikari no Tabi',
        language: 'ja',
        year: 1997,
    },
    {
        fr: 'Les Ombres du port',
        en: 'Harbour Shadows',
        original: 'Harbour Shadows',
        originalLatin: null,
        language: 'en',
        year: 1984,
    },
    {
        fr: 'La Dernière Bobine',
        en: 'The Last Reel',
        original: 'La Dernière Bobine',
        originalLatin: null,
        language: 'fr',
        year: 2003,
    },
    {
        fr: 'Opération Tournesol : le retour de la brigade',
        en: 'Operation Sunflower: Return of the Brigade',
        original: 'Operation Sunflower: Return of the Brigade',
        originalLatin: null,
        language: 'en',
        year: 2019,
    },
    {
        fr: 'Un été à Kyoto',
        en: 'A Summer in Kyoto',
        original: '京都の夏',
        originalLatin: 'Kyōto no Natsu',
        language: 'ja',
        year: 2011,
    },
    {
        fr: 'Le Royaume des lanternes',
        en: 'Kingdom of Lanterns',
        original: 'Kingdom of Lanterns',
        originalLatin: null,
        language: 'en',
        year: 1992,
    },
    {
        fr: 'Minuit sur la Lune',
        en: 'Midnight on the Moon',
        original: 'Minuit sur la Lune',
        originalLatin: null,
        language: 'fr',
        year: 1976,
    },
    {
        fr: 'Les Petits Robots',
        en: 'Little Robots',
        original: 'Little Robots',
        originalLatin: null,
        language: 'en',
        year: 2008,
    },
];

/** Le film révélé de la manche `roundNumber`. */
function movieOf(roundNumber: number): RevealMovie {
    const seed = MOVIES[(roundNumber - 1) % MOVIES.length];

    return {
        titles: {
            fr: { text: seed.fr, lang: 'fr' },
            en: { text: seed.en, lang: 'en' },
        },
        originalTitle: seed.original,
        originalTitleLatin: seed.originalLatin,
        originalLanguage: seed.language,
        year: seed.year,
        letterboxdUrl: 'https://letterboxd.com/',
        tmdb: 100_000 + roundNumber,
    };
}

function iso(ms: number): IsoMs {
    return new Date(ms).toISOString();
}

/** Les initiales d'un pseudo, comme le serveur les pose en repli. */
function initialsOf(nickname: string): string {
    return nickname
        .split(/[\s-]+/)
        .filter((part) => part !== '')
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

function avatarOf(
    index: number,
    nickname: string,
    avatars: readonly AvatarPresetOption[],
): AvatarData {
    const option =
        avatars.length === 0 ? null : avatars[index % avatars.length];

    return option === null
        ? {
              kind: null,
              url: null,
              altKey: 'common.avatar.alt.initials',
              initials: initialsOf(nickname),
          }
        : {
              kind: 'preset',
              url: option.url,
              altKey: 'common.avatar.alt.preset',
              initials: initialsOf(nickname),
          };
}

/** Options des sièges d'un scénario. */
type SeatsOptions = {
    /** Partie (manche d'entrée posée) ou lobby (`firstRoundNumber` nul). */
    inGame: boolean;
    /** Nombre de sièges, « Vous » compris. */
    count: number;
    hostId: string;
    /** Manche d'entrée de « Vous », pour un retardataire. */
    selfFirstRound?: number;
    /** Sièges déconnectés en plus du dernier. */
    disconnected?: readonly string[];
};

function seatsOf(input: GameFixtureInput, options: SeatsOptions): SeatView[] {
    const seeds = SEATS.slice(0, Math.max(1, options.count));
    const last = seeds.length - 1;

    return seeds.map((seed, index) => ({
        publicId: seed.publicId,
        nickname: seed.nickname,
        masked: false,
        avatar: avatarOf(index, seed.nickname, input.avatars),
        isHost: seed.publicId === options.hostId,
        connection:
            (index === last && index > 0 && !seed.connected) ||
            options.disconnected?.includes(seed.publicId) === true
                ? 'disconnected'
                : 'connected',
        kicked: false,
        firstRoundNumber: options.inGame
            ? seed.publicId === SELF_ID
                ? (options.selfFirstRound ?? 1)
                : 1
            : null,
    }));
}

/** Rangs de compétition (1, 2, 2, 4) sur des scores triés. */
function ranked<T>(
    items: readonly T[],
    score: (item: T) => number,
): { item: T; rank: number; shared: boolean }[] {
    const sorted = items.toSorted((left, right) => score(right) - score(left));

    return sorted.map((item) => {
        const first = sorted.findIndex((each) => score(each) === score(item));
        const shared =
            sorted.filter((each) => score(each) === score(item)).length > 1;

        return { item, rank: first + 1, shared };
    });
}

type LeaderboardOptions = {
    seats: readonly SeatView[];
    solo: boolean;
    scoreless: boolean;
    /** Manche révélée à l'origine du calcul ; nulle hors révélation. */
    revealedRound: number | null;
    roundsPlayed: number;
    /** Points de chaque siège sur la manche révélée. */
    deltas?: ReadonlyMap<string, number>;
};

function leaderboardOf(options: LeaderboardOptions): Leaderboard {
    const seeds = options.seats.map(
        (seat) =>
            SEATS.find((seed) => seed.publicId === seat.publicId) ?? SEATS[0],
    );
    const scoreOf = (seed: SeatSeed): number =>
        options.scoreless ? 0 : seed.score;
    const rows: LeaderboardRow[] = ranked(seeds, scoreOf).map(
        ({ item, rank, shared }) => ({
            publicId: item.publicId,
            rank: options.solo ? null : rank,
            rankShared: options.solo ? false : shared,
            score: scoreOf(item),
            correctAnswers: item.correct,
            roundsPlayed: options.roundsPlayed,
            totalAnswerTimeMs: item.answerMs,
            roundDelta: options.deltas?.get(item.publicId) ?? 0,
            status: 'playing',
            firstRoundNumber: 1,
        }),
    );

    return {
        scoreless: options.scoreless,
        roundNumber: options.revealedRound,
        rows,
    };
}

/** Les fenêtres des paliers, lues dans les réglages par défaut. */
function tiersOf(settings: RoomSettingsState): TierWindow[] {
    const { tierDurations, tierPoints } = settings.settings;
    let offset = 0;

    return tierDurations.map((seconds, index) => {
        const window: TierWindow = {
            tierIndex: index + 1,
            startsAtOffsetMs: offset,
            durationMs: seconds * SECOND,
            points: tierPoints[index] ?? 0,
        };

        offset += seconds * SECOND;

        return window;
    });
}

/** Le palier de choix du QCM selon la difficulté (T₁, T_N, aucun). */
function choicesTier(
    difficulty: InputDifficulty,
    tierCount: number,
): number | null {
    switch (difficulty) {
        case 'easy':
            return 1;
        case 'normal':
            return tierCount;
        case 'expert':
            return null;
    }
}

/** Les quatre propositions, dans la langue de l'aperçu, indiscernables. */
function choicesOf(roundNumber: number, locale: LocaleCode): ChoicesPayload {
    const pick = (offset: number): string => {
        const seed = MOVIES[(roundNumber - 1 + offset) % MOVIES.length];

        return locale === 'fr' ? seed.fr : seed.en;
    };

    return {
        choices: [pick(4), pick(0), pick(2), pick(5)],
        useOriginalTitle: false,
        lang: locale,
    };
}

type RoundOptions = {
    roundNumber: number;
    /** Instant de début de la manche, en millisecondes. */
    startsAtMs: number;
    phase: RoundState['phase'];
    difficulty: InputDifficulty;
    endedAtMs?: number | null;
    revealStartsAtMs?: number | null;
    revealEndsAtMs?: number | null;
    finders?: readonly RoundFinder[];
    locked?: RoundState['locked'];
    /** Paliers dont la référence d'image est portée (tous par défaut). */
    imageTiers?: readonly number[];
};

function roundOf(input: GameFixtureInput, options: RoundOptions): RoundState {
    const tiers = tiersOf(input.settings);
    const durationMs = tiers.reduce((sum, tier) => sum + tier.durationMs, 0);
    const elapsed = input.nowMs - options.startsAtMs;
    const current =
        options.phase === 'running'
            ? (tiers.findLast((tier) => tier.startsAtOffsetMs <= elapsed)
                  ?.tierIndex ?? 1)
            : null;
    const imageTiers =
        options.phase === 'cancelled'
            ? []
            : (options.imageTiers ?? tiers.map((tier) => tier.tierIndex));
    const images: TierImageRef[] = imageTiers.map((tierIndex) => ({
        tierIndex,
        url: input.images[(tierIndex - 1) % input.images.length] ?? '',
        fetchNotBefore: iso(input.nowMs - MINUTE),
    }));
    const revealing =
        options.phase === 'revealing'
            ? {
                  movie: movieOf(options.roundNumber),
                  frames: tiers.map((tier) => ({
                      tierIndex: tier.tierIndex,
                      framePublicId: `DSGNFRAME${String(tier.tierIndex).padStart(3, '0')}`,
                  })),
                  finders: [...(options.finders ?? [])],
              }
            : null;

    return {
        sequenceIndex: options.roundNumber,
        roundNumber: options.roundNumber,
        roundsCount: input.settings.settings.roundsCount,
        startsAt: iso(options.startsAtMs),
        durationMs,
        tiers,
        choicesAtTierIndex: choicesTier(options.difficulty, tiers.length),
        phase: options.phase,
        currentTierIndex: current,
        images,
        locked: [...(options.locked ?? [])],
        endedAt:
            options.endedAtMs === undefined || options.endedAtMs === null
                ? null
                : iso(options.endedAtMs),
        revealStartsAt:
            options.revealStartsAtMs === undefined ||
            options.revealStartsAtMs === null
                ? null
                : iso(options.revealStartsAtMs),
        revealEndsAt:
            options.revealEndsAtMs === undefined ||
            options.revealEndsAtMs === null
                ? null
                : iso(options.revealEndsAtMs),
        reveal: revealing,
        choicesUnavailable: false,
    };
}

/** Une bonne réponse fictive au palier `tierIndex`, `secondsIn` après Tᵢ. */
function finderOf(
    input: GameFixtureInput,
    publicId: string,
    lockRank: number,
    tierIndex: number,
    secondsIn: number,
): RoundFinder {
    const tiers = tiersOf(input.settings);
    const tier = tiers[tierIndex - 1] ?? tiers[0];
    const pointsBonus = Math.round(
        (tier.points * (tier.durationMs - secondsIn * SECOND)) /
            tier.durationMs /
            4,
    );

    return {
        publicId,
        lockRank,
        tierIndex: tier.tierIndex,
        answeredAtMs: tier.startsAtOffsetMs + secondsIn * SECOND,
        pointsTier: tier.points,
        pointsBonus,
        pointsTotal: tier.points + pointsBonus,
    };
}

/** La saisie ouverte du siège, tentatives lues dans les réglages. */
function openInput(
    input: GameFixtureInput,
    choices: ChoicesPayload | null,
    used = 1,
): SeatInputView {
    return {
        inputState: 'open',
        attemptsLeft: Math.max(
            0,
            input.settings.settings.attemptsPerRound - used,
        ),
        choices,
        locked: null,
    };
}

type PacketOptions = {
    mode?: GameStatePacket['mode'];
    status: GameStatePacket['status'];
    difficulty: InputDifficulty | null;
    seats: SeatView[];
    round: RoundState | null;
    selfInput: SeatInputView | null;
    isHost: boolean;
    member?: boolean;
    participates?: boolean;
    roundsCompleted: number | null;
    leaderboard: Leaderboard;
    podium?: Podium | null;
    pause?: GameStatePacket['pause'];
    pauseRequested?: boolean;
    inGame?: boolean;
};

function packetOf(
    input: GameFixtureInput,
    options: PacketOptions,
): GameStatePacket {
    const settings = input.settings.settings;
    const inGame = options.inGame ?? true;
    const selfScore =
        options.leaderboard.rows.find((row) => row.publicId === SELF_ID)
            ?.score ?? 0;

    return {
        v: 1,
        serverNow: iso(input.nowMs),
        gameRef: inGame ? GAME_REF : null,
        mode: options.mode ?? 'multiplayer',
        // Aucun canal : l'aperçu ne s'abonne à rien.
        channels: null,
        status: inGame ? options.status : null,
        roundsCount: inGame ? settings.roundsCount : null,
        roundsCompleted: inGame ? options.roundsCompleted : null,
        framesPerRound: inGame ? settings.framesPerRound : null,
        inputDifficulty: inGame ? options.difficulty : null,
        maxAnswerLength: inGame ? settings.maxAnswerLength : null,
        seats: options.seats,
        pause: options.pause ?? null,
        pauseRequested: options.pauseRequested ?? false,
        round: options.round,
        self: {
            publicId: SELF_ID,
            seatActive: true,
            isHost: options.isHost,
            member: options.member ?? inGame,
            participates: options.participates ?? options.selfInput !== null,
            input: options.selfInput,
            ownScore: inGame ? selfScore : 0,
        },
        leaderboard: options.leaderboard,
        podium: options.podium ?? null,
        nextTransitionAt: null,
    };
}

/** Le récapitulatif et les faits marquants d'une partie fictive. */
function podiumOf(
    input: GameFixtureInput,
    options: {
        seats: readonly SeatView[];
        solo: boolean;
        status: Podium['gameStatus'];
        roundsCompleted: number;
        scoreless: boolean;
    },
): Podium {
    const settings = input.settings.settings;
    const recap: RecapEntry[] = [];
    const unfound: number[] = [];

    for (let number = 1; number <= options.roundsCompleted; number++) {
        const nobody = number % 4 === 0;
        const finders = nobody
            ? []
            : options.seats
                  .filter((seat, index) => (index + number) % 3 !== 0)
                  .slice(0, options.solo ? 1 : 4)
                  .map((seat, index) =>
                      finderOf(
                          input,
                          seat.publicId,
                          index + 1,
                          Math.min(1 + index, settings.framesPerRound),
                          2 + index,
                      ),
                  );

        if (nobody) {
            unfound.push(number);
        }

        recap.push({
            roundNumber: number,
            outcome: 'completed',
            titles: movieOf(number),
            foundCount: finders.length,
            finders,
        });
    }

    if (options.status === 'interrupted') {
        recap.push({
            roundNumber: options.roundsCompleted + 1,
            outcome: 'cancelled',
            titles: null,
            foundCount: 0,
            finders: [],
        });
    }

    const standings: PodiumStanding[] = ranked(options.seats, (seat) =>
        options.scoreless
            ? 0
            : (SEATS.find((seed) => seed.publicId === seat.publicId)?.score ??
              0),
    ).map(({ item, rank, shared }) => {
        const seed =
            SEATS.find((each) => each.publicId === item.publicId) ?? SEATS[0];

        return {
            publicId: item.publicId,
            nickname: item.nickname,
            masked: item.masked,
            avatar: item.avatar,
            status: 'playing',
            firstRoundNumber: 1,
            rank: options.solo ? null : rank,
            rankShared: options.solo ? false : shared,
            finalScore: options.scoreless ? 0 : seed.score,
            correctAnswers: seed.correct,
            roundsPlayed: options.roundsCompleted,
            totalAnswerTimeMs: seed.answerMs,
        };
    });

    const best = recap.find(
        (entry) => entry.outcome === 'completed' && entry.finders.length > 0,
    );
    const bestFinder = best?.finders[0] ?? null;

    return {
        gameStatus: options.status,
        mode: options.solo ? 'solo' : 'multiplayer',
        roundsCompleted: options.roundsCompleted,
        roundsCount: settings.roundsCount,
        framesPerRound: settings.framesPerRound,
        scoreless: options.scoreless,
        endedAt: iso(input.nowMs - 5 * SECOND),
        standings,
        recap,
        highlights: {
            bestAnswer:
                best === undefined || bestFinder === null || options.scoreless
                    ? null
                    : {
                          publicId: bestFinder.publicId,
                          roundNumber: best.roundNumber,
                          tierIndex: bestFinder.tierIndex,
                          answeredAtMs: bestFinder.answeredAtMs,
                          pointsTotal: bestFinder.pointsTotal,
                      },
            fastestFind:
                best === undefined || bestFinder === null
                    ? null
                    : {
                          publicId: bestFinder.publicId,
                          roundNumber: best.roundNumber,
                          answeredAtMs: bestFinder.answeredAtMs,
                      },
            unfoundRoundNumbers: unfound,
        },
    };
}

/** Le pool bloqué du scénario « vivier bloqué » : un `N` plus bas suffit. */
function blockedSettings(settings: RoomSettingsState): RoomSettingsState {
    const n = settings.settings.framesPerRound;
    const m = settings.settings.roundsCount;
    const lower = Math.max(2, n - 1);
    const reduced = Math.max(1, Math.floor(m / 2));

    return {
        ...settings,
        pool: {
            count: reduced,
            framesPerRound: n,
            roundsCount: m,
            blocked: true,
            causes: ['framesPerRound', 'roundsCount'],
            remedies: [
                { kind: 'lower_frames_per_round', value: lower, count: m + 4 },
                { kind: 'reduce_rounds_count', value: reduced, count: reduced },
            ],
            nearestPlayableFramesPerRound: lower,
            themesPruned: false,
        },
    };
}

/** Le siège compte-t-il pour la capacité du salon ? */
function seatCount(input: GameFixtureInput, wanted: number): number {
    return Math.min(wanted, input.settings.settings.capacity, SEATS.length);
}

/**
 * Le paquet fictif d'un scénario `game.*`. `nowMs` fixe l'instant présent ;
 * chaque instant du paquet en dérive.
 */
export function buildGameFixture(
    key: GameScenarioKey,
    input: GameFixtureInput,
): GameFixture {
    const settings = input.settings.settings;
    const now = input.nowMs;
    const tiers = tiersOf(input.settings);
    const durationMs = tiers.reduce((sum, tier) => sum + tier.durationMs, 0);
    const lastTier = tiers[tiers.length - 1];
    const revealMs = settings.revealDuration * SECOND;
    const count = seatCount(input, SEATS.length);
    const gameSeats = seatsOf(input, {
        inGame: true,
        count,
        hostId: SELF_ID,
    });
    // Classement figé à la dernière manche révélée (la 3ᵉ), hors révélation.
    const frozen = leaderboardOf({
        seats: gameSeats,
        solo: false,
        scoreless: false,
        revealedRound: null,
        roundsPlayed: 3,
    });

    /** Une manche en cours de la 4ᵉ manche, démarrée `elapsedMs` plus tôt. */
    const running = (
        difficulty: InputDifficulty,
        elapsedMs: number,
        extra: Partial<RoundOptions> = {},
    ): RoundState =>
        roundOf(input, {
            roundNumber: 4,
            startsAtMs: now - elapsedMs,
            phase: 'running',
            difficulty,
            ...extra,
        });

    const lobby = (
        isHost: boolean,
        seats: SeatView[],
        roomSettings: RoomSettingsState,
    ): GameFixture => ({
        page: 'lobby',
        code: DESIGN_ROOM_CODE,
        settings: roomSettings,
        packet: packetOf(input, {
            inGame: false,
            status: null,
            difficulty: null,
            seats,
            round: null,
            selfInput: null,
            isHost,
            member: false,
            participates: false,
            roundsCompleted: null,
            leaderboard: { scoreless: false, roundNumber: null, rows: [] },
        }),
    });

    const inGame = (
        options: Omit<PacketOptions, 'seats' | 'leaderboard'> & {
            seats?: SeatView[];
            leaderboard?: Leaderboard;
        },
    ): GameFixture => ({
        page: 'lobby',
        code: DESIGN_ROOM_CODE,
        settings: input.settings,
        packet: packetOf(input, {
            seats: gameSeats,
            leaderboard: frozen,
            ...options,
        }),
    });

    switch (key) {
        case 'game.lobby_host':
            return lobby(
                true,
                seatsOf(input, { inGame: false, count, hostId: SELF_ID }),
                input.settings,
            );
        case 'game.lobby_guest':
            return lobby(
                false,
                seatsOf(input, { inGame: false, count, hostId: OTHER_HOST_ID }),
                input.settings,
            );
        case 'game.lobby_blocked':
            return lobby(
                true,
                seatsOf(input, {
                    inGame: false,
                    count: seatCount(input, 2),
                    hostId: SELF_ID,
                    disconnected: [OTHER_HOST_ID],
                }),
                blockedSettings(input.settings),
            );
        case 'game.room_expired':
            return { page: 'room_expired' };
        case 'game.late_joiner':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                seats: seatsOf(input, {
                    inGame: true,
                    count,
                    hostId: OTHER_HOST_ID,
                    selfFirstRound: 5,
                }),
                round: running('normal', 4 * SECOND),
                selfInput: null,
                isHost: false,
                member: false,
                participates: false,
                roundsCompleted: 3,
            });
        case 'game.countdown':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: roundOf(input, {
                    roundNumber: 4,
                    startsAtMs: now + 9 * SECOND,
                    phase: 'scheduled',
                    difficulty: 'normal',
                    imageTiers: [1],
                }),
                selfInput: null,
                isHost: true,
                participates: false,
                roundsCompleted: 3,
            });
        case 'game.round_normal':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: running('normal', 6 * SECOND, {
                    locked: [{ publicId: SEATS[1].publicId, lockRank: 1 }],
                }),
                selfInput: openInput(input, null),
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_normal_qcm':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: running(
                    'normal',
                    lastTier.startsAtOffsetMs + 2 * SECOND,
                    {
                        locked: [
                            { publicId: SEATS[1].publicId, lockRank: 1 },
                            { publicId: SEATS[3].publicId, lockRank: 2 },
                        ],
                    },
                ),
                selfInput: openInput(input, choicesOf(4, input.locale), 2),
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_text_exhausted':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: running('normal', 4 * SECOND),
                selfInput: {
                    inputState: 'text_exhausted',
                    attemptsLeft: 0,
                    choices: null,
                    locked: null,
                },
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_expert':
            return inGame({
                status: 'running',
                difficulty: 'expert',
                round: running('expert', 6 * SECOND),
                selfInput: openInput(input, null),
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_easy':
            return inGame({
                status: 'running',
                difficulty: 'easy',
                round: running('easy', 3 * SECOND),
                selfInput: openInput(input, choicesOf(4, input.locale), 0),
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_locked': {
            const lock = finderOf(input, SELF_ID, 2, 1, 4);

            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: running(
                    'normal',
                    (tiers[1]?.startsAtOffsetMs ?? 0) + 3 * SECOND,
                    {
                        locked: [
                            { publicId: SEATS[1].publicId, lockRank: 1 },
                            { publicId: SELF_ID, lockRank: 2 },
                            { publicId: SEATS[4].publicId, lockRank: 3 },
                        ],
                    },
                ),
                selfInput: {
                    inputState: 'locked',
                    attemptsLeft: Math.max(0, settings.attemptsPerRound - 1),
                    choices: null,
                    locked: {
                        lockRank: lock.lockRank,
                        tierIndex: lock.tierIndex,
                        pointsTier: lock.pointsTier,
                        pointsBonus: lock.pointsBonus,
                        pointsTotal: lock.pointsTotal,
                    },
                },
                isHost: true,
                roundsCompleted: 3,
            });
        }
        case 'game.round_closed':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: roundOf(input, {
                    roundNumber: 4,
                    startsAtMs: now - durationMs - SECOND,
                    phase: 'closed',
                    difficulty: 'normal',
                    endedAtMs: now - SECOND,
                    revealStartsAtMs: now + 10 * MINUTE,
                    revealEndsAtMs: now + 10 * MINUTE + revealMs,
                    locked: [{ publicId: SEATS[2].publicId, lockRank: 1 }],
                }),
                selfInput: openInput(input, null, settings.attemptsPerRound),
                isHost: true,
                roundsCompleted: 3,
            });
        case 'game.round_cancelled':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: roundOf(input, {
                    roundNumber: 4,
                    startsAtMs: now - 5 * SECOND,
                    phase: 'cancelled',
                    difficulty: 'normal',
                    endedAtMs: now - 2 * SECOND,
                }),
                selfInput: null,
                isHost: true,
                participates: false,
                roundsCompleted: 3,
            });
        case 'game.pause_requested':
            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: running('normal', 5 * SECOND),
                selfInput: openInput(input, null),
                isHost: true,
                pauseRequested: true,
                roundsCompleted: 3,
            });
        case 'game.pause_manual':
        case 'game.pause_empty':
            return inGame({
                status: 'paused',
                difficulty: 'normal',
                round: null,
                selfInput: null,
                isHost: true,
                participates: false,
                pause: {
                    pausedAt: iso(now - 20 * SECOND),
                    interruptsAt: iso(now + 10 * MINUTE),
                    kind: key === 'game.pause_manual' ? 'manual' : 'empty',
                },
                roundsCompleted: 3,
            });
        case 'game.reveal_found':
        case 'game.reveal_nobody': {
            const finders =
                key === 'game.reveal_found'
                    ? [
                          finderOf(input, SEATS[1].publicId, 1, 1, 3),
                          finderOf(input, SEATS[2].publicId, 2, 1, 7),
                          finderOf(input, SELF_ID, 3, 2, 2),
                          finderOf(
                              input,
                              SEATS[4].publicId,
                              4,
                              tiers.length,
                              5,
                          ),
                      ]
                    : [];
            const deltas = new Map(
                finders.map((finder) => [finder.publicId, finder.pointsTotal]),
            );

            return inGame({
                status: 'running',
                difficulty: 'normal',
                round: roundOf(input, {
                    roundNumber: 4,
                    startsAtMs: now - durationMs - 3 * SECOND,
                    phase: 'revealing',
                    difficulty: 'normal',
                    endedAtMs: now - 3 * SECOND,
                    revealStartsAtMs: now - 2 * SECOND,
                    revealEndsAtMs: now + 20 * MINUTE,
                    finders,
                    locked: finders.map((finder) => ({
                        publicId: finder.publicId,
                        lockRank: finder.lockRank,
                    })),
                }),
                selfInput: null,
                isHost: true,
                roundsCompleted: 4,
                leaderboard: leaderboardOf({
                    seats: gameSeats,
                    solo: false,
                    scoreless: false,
                    revealedRound: 4,
                    roundsPlayed: 4,
                    deltas,
                }),
            });
        }
        case 'game.podium_completed':
        case 'game.podium_interrupted':
        case 'game.podium_scoreless': {
            const interrupted = key === 'game.podium_interrupted';
            const scoreless = key === 'game.podium_scoreless';
            const completed = interrupted
                ? Math.max(1, Math.floor(settings.roundsCount / 2))
                : settings.roundsCount;

            return inGame({
                status: interrupted ? 'interrupted' : 'completed',
                difficulty: 'normal',
                round: null,
                selfInput: null,
                isHost: true,
                participates: false,
                roundsCompleted: completed,
                leaderboard: leaderboardOf({
                    seats: gameSeats,
                    solo: false,
                    scoreless,
                    revealedRound: completed,
                    roundsPlayed: completed,
                }),
                podium: podiumOf(input, {
                    seats: gameSeats,
                    solo: false,
                    status: interrupted ? 'interrupted' : 'completed',
                    roundsCompleted: completed,
                    scoreless,
                }),
            });
        }
        case 'game.solo_round':
        case 'game.solo_reveal':
        case 'game.solo_podium': {
            const soloSeats = seatsOf(input, {
                inGame: true,
                count: 1,
                hostId: SELF_ID,
            });
            const soloBoard = (revealed: number | null): Leaderboard =>
                leaderboardOf({
                    seats: soloSeats,
                    solo: true,
                    scoreless: false,
                    revealedRound: revealed,
                    roundsPlayed: 3,
                });
            const base = {
                mode: 'solo' as const,
                difficulty: 'normal' as const,
                seats: soloSeats,
                isHost: true,
            };

            if (key === 'game.solo_round') {
                return {
                    page: 'solo',
                    packet: packetOf(input, {
                        ...base,
                        status: 'running',
                        round: running('normal', 7 * SECOND),
                        selfInput: openInput(input, null),
                        roundsCompleted: 3,
                        leaderboard: soloBoard(null),
                    }),
                };
            }

            if (key === 'game.solo_reveal') {
                const finder = finderOf(input, SELF_ID, 1, 2, 3);

                return {
                    page: 'solo',
                    packet: packetOf(input, {
                        ...base,
                        status: 'running',
                        round: roundOf(input, {
                            roundNumber: 4,
                            startsAtMs: now - durationMs - 3 * SECOND,
                            phase: 'revealing',
                            difficulty: 'normal',
                            endedAtMs: now - 3 * SECOND,
                            revealStartsAtMs: now - 2 * SECOND,
                            revealEndsAtMs: now + 20 * MINUTE,
                            finders: [finder],
                            locked: [{ publicId: SELF_ID, lockRank: 1 }],
                        }),
                        selfInput: null,
                        roundsCompleted: 4,
                        leaderboard: soloBoard(4),
                    }),
                };
            }

            return {
                page: 'solo',
                packet: packetOf(input, {
                    ...base,
                    status: 'completed',
                    round: null,
                    selfInput: null,
                    participates: false,
                    roundsCompleted: settings.roundsCount,
                    leaderboard: soloBoard(settings.roundsCount),
                    podium: podiumOf(input, {
                        seats: soloSeats,
                        solo: true,
                        status: 'completed',
                        roundsCompleted: settings.roundsCount,
                        scoreless: false,
                    }),
                }),
            };
        }
    }
}
