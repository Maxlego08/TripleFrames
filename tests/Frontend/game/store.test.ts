import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import {
    createGameStore,
    displayedRound,
    isMemberOfRound,
} from '@/lib/game/store';
import type { GameStore, ResyncOutcome, ResyncReason } from '@/lib/game/store';
import type {
    GameEvent,
    GameEventName,
    GameEventPayloads,
    GameStatePacket,
    RevealMovie,
    RoundState,
    RoundTimeline,
    SeatView,
    TierImageRef,
} from '@/types/game-wire';
import type { RoomSettingsState } from '@/types/room-settings';
import type { TierWindow } from '@/types/scoring';

/*
 * Magasin de jeu côté client (spec 60 § 11.8 et § 12.6, contrat C7 § 2.6).
 *
 * Magasin pur, sans DOM ni réseau (C18 § 2.4) : la resynchronisation est une
 * fonction qui relève ses motifs et ne rend rien tant que le test ne le
 * décide pas, les minuteurs et `Date` sont factices, et l'horloge serveur est
 * `Date.now()`. Les instants s'écrivent en millisecondes depuis une origine
 * arbitraire ; une manche de trois paliers de 10 s, préchargement de 2 s,
 * battement de 10 s — des données de test, jamais des constantes du client.
 */

/** Origine arbitraire : 23/09/2026 14:05:00.000 UTC. */
const ORIGIN_MS = Date.UTC(2026, 8, 23, 14, 5, 0, 0);
const GAME = '3f9a0c1d2e4b5a69';
const OTHER_GAME = '0a1b2c3d4e5f6071';
const SELF = 'SELFSEAT000A';
const RIVAL = 'RIVALSEAT00B';
const LEAD_MS = 2000;
const HEARTBEAT_MS = 10_000;
const TIERS: TierWindow[] = [
    { tierIndex: 1, startsAtOffsetMs: 0, durationMs: 10_000, points: 300 },
    { tierIndex: 2, startsAtOffsetMs: 10_000, durationMs: 10_000, points: 200 },
    { tierIndex: 3, startsAtOffsetMs: 20_000, durationMs: 10_000, points: 100 },
];
const DURATION_MS = 30_000;

/** Un `IsoMs` à `ms` millisecondes de l'origine. */
function iso(ms: number): string {
    return new Date(ORIGIN_MS + ms).toISOString();
}

/** Avance l'horloge factice jusqu'à `ms`, minuteurs compris. */
async function advanceTo(ms: number): Promise<void> {
    await vi.advanceTimersByTimeAsync(ORIGIN_MS + ms - Date.now());
}

function offsetOf(tierIndex: number): number {
    return TIERS[tierIndex - 1].startsAtOffsetMs;
}

/** La référence d'image du palier `tierIndex` d'une manche qui part à `startsAt`. */
function image(startsAt: number, tierIndex: number): TierImageRef {
    return {
        tierIndex,
        url: `/f/${String(tierIndex).repeat(32)}?expires=1&signature=${startsAt}`,
        fetchNotBefore: iso(startsAt + offsetOf(tierIndex) - LEAD_MS),
    };
}

function timeline(
    sequenceIndex: number,
    startsAt: number,
    choicesAtTierIndex: number | null = 3,
): RoundTimeline {
    return {
        sequenceIndex,
        roundNumber: sequenceIndex,
        roundsCount: 10,
        startsAt: iso(startsAt),
        durationMs: DURATION_MS,
        tiers: TIERS,
        choicesAtTierIndex,
    };
}

function round(
    sequenceIndex: number,
    startsAt: number,
    phase: RoundState['phase'],
    extra: Partial<RoundState> = {},
): RoundState {
    return {
        ...timeline(sequenceIndex, startsAt),
        phase,
        currentTierIndex: null,
        images: [],
        locked: [],
        endedAt: null,
        revealStartsAt: null,
        revealEndsAt: null,
        reveal: null,
        ...extra,
    };
}

function seat(publicId: string, extra: Partial<SeatView> = {}): SeatView {
    return {
        publicId,
        nickname: publicId === SELF ? 'Moi' : 'Rival',
        masked: false,
        avatar: { kind: null, url: null, altKey: 'avatar', initials: 'M' },
        isHost: publicId === SELF,
        connection: 'connected',
        kicked: false,
        firstRoundNumber: null,
        ...extra,
    };
}

/** Un paquet de partie en cours (Normal, N = 3), à compléter. */
function packet(
    atMs: number,
    overrides: Partial<GameStatePacket> = {},
): GameStatePacket {
    return {
        v: 1,
        serverNow: iso(atMs),
        gameRef: GAME,
        mode: 'multiplayer',
        channels: { room: 'room.clef', seat: `seat.${SELF}` },
        status: 'running',
        roundsCount: 10,
        roundsCompleted: 0,
        framesPerRound: 3,
        inputDifficulty: 'normal',
        maxAnswerLength: 60,
        seats: [
            seat(SELF, { firstRoundNumber: 1 }),
            seat(RIVAL, { firstRoundNumber: 1 }),
        ],
        pause: null,
        round: null,
        self: {
            publicId: SELF,
            seatActive: true,
            isHost: true,
            member: true,
            participates: true,
            input: {
                inputState: 'open',
                attemptsLeft: 5,
                choices: null,
                locked: null,
            },
            ownScore: 0,
        },
        leaderboard: { scoreless: false, roundNumber: null, rows: [] },
        podium: null,
        nextTransitionAt: null,
        ...overrides,
    };
}

/** Le paquet d'un lobby : aucune partie, les deux canaux du salon. */
function lobbyPacket(atMs: number): GameStatePacket {
    return packet(atMs, {
        gameRef: null,
        status: null,
        roundsCount: null,
        roundsCompleted: null,
        framesPerRound: null,
        inputDifficulty: null,
        maxAnswerLength: null,
        seats: [seat(SELF), seat(RIVAL)],
        self: {
            publicId: SELF,
            seatActive: true,
            isHost: true,
            member: false,
            participates: false,
            input: null,
            ownScore: 0,
        },
    });
}

/** L'état des réglages du lobby (seul `maxAnswerLength` compte ici). */
function settingsState(maxAnswerLength: number): RoomSettingsState {
    return {
        settings: {
            themeKeys: [],
            roundsCount: 10,
            framesPerRound: 3,
            tierDurations: [10, 10, 10],
            tierPoints: [300, 200, 100],
            revealDuration: 8,
            inputDifficulty: 'normal',
            capacity: 8,
            allowLateJoin: false,
            speedBonus: true,
            noRepeatMovies: true,
            attemptsPerSecond: 2,
            attemptsPerRound: 5,
            maxAnswerLength,
            disconnectGraceSeconds: 60,
            advanced: false,
        },
        warnings: [],
        pool: {
            count: 40,
            framesPerRound: 3,
            roundsCount: 10,
            blocked: false,
            causes: [],
            remedies: [],
            nearestPlayableFramesPerRound: null,
            themesPruned: false,
        },
    };
}

/** Un film fictif, pour une révélation. */
const MOVIE: RevealMovie = {
    titles: {
        fr: { text: 'Le Phare aux lucioles', lang: 'fr' },
        en: { text: 'The Firefly Lighthouse', lang: 'en' },
    },
    originalTitle: 'Le Phare aux lucioles',
    originalTitleLatin: null,
    originalLanguage: 'fr',
    year: 1999,
};

/** Un événement tel qu'il arrive du fil : enveloppe, puis charge. */
function event<N extends GameEventName>(
    atMs: number,
    payload: GameEventPayloads[N],
    gameRef: string | null = GAME,
): GameEvent<N> {
    return { v: 1, serverNow: iso(atMs), gameRef, ...payload };
}

type Harness = {
    store: GameStore;
    resyncs: ResyncReason[];
    notifications: () => number;
    stop: () => void;
    answer: (outcome: ResyncOutcome) => Promise<void>;
};

/**
 * Un magasin observé (ses minuteurs ne courent que s'il l'est). Chaque
 * resynchronisation est relevée et reste en vol jusqu'à `answer()`.
 */
function harness(
    initial: GameStatePacket,
    settings: RoomSettingsState | null = null,
): Harness {
    const resyncs: ResyncReason[] = [];
    const waiting: Array<(outcome: ResyncOutcome) => void> = [];
    let notifications = 0;

    const store = createGameStore({
        initial,
        settings,
        resync: (reason) => {
            resyncs.push(reason);

            return new Promise<ResyncOutcome>((resolve) => {
                waiting.push(resolve);
            });
        },
        now: () => Date.now(),
        heartbeatIntervalMs: HEARTBEAT_MS,
    });
    const stop = store.subscribe(() => {
        notifications += 1;
    });

    return {
        store,
        resyncs,
        notifications: () => notifications,
        stop,
        answer: async (outcome) => {
            waiting.shift()?.(outcome);
            await vi.advanceTimersByTimeAsync(0);
        },
    };
}

describe('store', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(ORIGIN_MS);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('ignore un événement déjà reçu pour le même gameRef, sequenceIndex et palier', async () => {
        vi.setSystemTime(ORIGIN_MS + 9000);

        const game = harness(
            packet(9000, {
                round: round(4, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1), image(0, 2)],
                }),
                nextTransitionAt: iso(10_000),
            }),
        );

        await advanceTo(10_004);

        const opened = event<'tier.opened'>(10_004, {
            sequenceIndex: 4,
            roundNumber: 4,
            tierIndex: 2,
            opensAt: iso(10_000),
            next: image(0, 3),
        });

        game.store.receive('tier.opened', opened);

        const applied = game.store.getState();
        const seen = game.notifications();

        expect(applied.rounds[0].currentTierIndex).toBe(2);
        expect(applied.rounds[0].images.map((each) => each.tierIndex)).toEqual([
            1, 2, 3,
        ]);

        // Redélivré tel quel, ou en copie : ni changement, ni notification.
        game.store.receive('tier.opened', opened);
        game.store.receive('tier.opened', structuredClone(opened));

        expect(game.store.getState()).toBe(applied);
        expect(game.notifications()).toBe(seen);

        // Un verrouillage redélivré ne compte qu'une fois.
        const locked = event<'player.locked'>(12_000, {
            sequenceIndex: 4,
            publicId: RIVAL,
            lockRank: 1,
        });

        game.store.receive('player.locked', locked);
        game.store.receive('player.locked', locked);

        expect(game.store.getState().rounds[0].locked).toEqual([
            { publicId: RIVAL, lockRank: 1 },
        ]);
        expect(game.resyncs).toEqual([]);

        // Même manche, même palier, autre partie : ce n'est pas le même
        // événement, et il n'est pas appliqué à celle-ci.
        game.store.receive('tier.opened', {
            ...opened,
            serverNow: iso(12_500),
            gameRef: OTHER_GAME,
        });

        expect(game.store.getState().rounds[0].currentTierIndex).toBe(2);
        expect(game.resyncs).toEqual(['unknown_game']);
    });

    it('garde le round.scheduled au serverNow le plus grand', async () => {
        vi.setSystemTime(ORIGIN_MS + 1000);

        const game = harness(
            packet(1000, {
                round: round(2, 60_000, 'scheduled', {
                    images: [image(60_000, 1)],
                }),
            }),
        );

        // Reprogrammée (« manche suivante ») : la plus récente l'emporte…
        game.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(3000, {
                round: timeline(2, 50_000),
                image: image(50_000, 1),
            }),
        );
        // …même quand une émission plus ancienne arrive après elle.
        game.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(2000, {
                round: timeline(2, 55_000),
                image: image(55_000, 1),
            }),
        );

        expect(game.store.getState().rounds).toHaveLength(1);
        expect(game.store.getState().rounds[0].startsAt).toBe(iso(50_000));
        expect(game.store.getState().rounds[0].images).toEqual([
            image(50_000, 1),
        ]);

        // Au lobby, avant `game.launched`, dans le désordre : rangées par
        // partie, puis appliquées dans l'ordre de leur `serverNow`.
        game.stop();

        const lobby = harness(lobbyPacket(1000));

        lobby.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(1500, {
                round: timeline(1, 9000),
                image: image(9000, 1),
            }),
        );
        lobby.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(1200, {
                round: timeline(1, 8000),
                image: image(8000, 1),
            }),
        );
        lobby.store.receive(
            'game.launched',
            event<'game.launched'>(1100, {
                mode: 'multiplayer',
                roundsCount: 10,
                framesPerRound: 3,
                inputDifficulty: 'normal',
                revealDurationMs: 8000,
                speedBonus: true,
                seats: [seat(SELF, { firstRoundNumber: 1 })],
            }),
        );

        expect(
            lobby.store.getState().rounds.map((each) => each.startsAt),
        ).toEqual([iso(9000)]);
        expect(lobby.resyncs).toEqual([]);
    });

    it("demande une resynchronisation à l'apprentissage d'un sequenceIndex inconnu", async () => {
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const game = harness(
            packet(12_000, {
                round: round(3, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2)],
                }),
                nextTransitionAt: iso(18_000),
            }),
        );

        // Une manche connue : appliqué, sans resynchronisation.
        game.store.receive(
            'player.locked',
            event<'player.locked'>(12_100, {
                sequenceIndex: 3,
                publicId: RIVAL,
                lockRank: 1,
            }),
        );
        expect(game.resyncs).toEqual([]);

        // Une manche dont le magasin ne sait rien : il ne devine pas, il
        // relit l'état.
        const before = game.store.getState().rounds;

        game.store.receive(
            'tier.opened',
            event<'tier.opened'>(12_500, {
                sequenceIndex: 5,
                roundNumber: 5,
                tierIndex: 1,
                opensAt: iso(12_500),
                next: null,
            }),
        );

        expect(game.resyncs).toEqual(['unknown_round']);
        expect(game.store.getState().rounds).toBe(before);
        expect(game.store.getState().resyncing).toBe(true);

        // Le paquet relu porte la manche 5 : elle devient la manche suivie.
        await game.answer({
            kind: 'packet',
            packet: packet(12_600, {
                round: round(5, 12_500, 'running', {
                    currentTierIndex: 1,
                    images: [image(12_500, 1)],
                }),
            }),
        });

        expect(
            game.store.getState().rounds.map((each) => each.sequenceIndex),
        ).toEqual([5]);
        expect(game.store.getState().resyncing).toBe(false);
        expect(game.store.getState().resyncCount).toBe(1);
    });

    it("demande une resynchronisation à la garde d'un palier dont il ne détient pas l'URL", async () => {
        // Paquet construit au milieu du palier 1 : l'URL du palier 2 n'y est
        // pas (garde non franchie) et aucun événement ne l'apportera —
        // `tier.opened` du palier 1 est déjà passé.
        vi.setSystemTime(ORIGIN_MS + 3000);

        const early = harness(
            packet(3000, {
                round: round(1, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1)],
                }),
                nextTransitionAt: iso(10_000 - LEAD_MS),
            }),
        );

        await advanceTo(10_000 - LEAD_MS - 1);
        expect(early.resyncs).toEqual([]);

        await advanceTo(10_000 - LEAD_MS);
        expect(early.resyncs).toEqual(['tier_guard']);
        early.stop();

        // Témoin : paquet construit dans la fenêtre du palier 2, puis l'URL
        // du palier 3 apportée par `tier.opened` — aucune garde sans URL.
        vi.setSystemTime(ORIGIN_MS + 8500);

        const held = harness(
            packet(8500, {
                round: round(1, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1), image(0, 2)],
                }),
                nextTransitionAt: iso(10_000),
            }),
        );

        await advanceTo(10_000);
        held.store.receive(
            'tier.opened',
            event<'tier.opened'>(10_000, {
                sequenceIndex: 1,
                roundNumber: 1,
                tierIndex: 2,
                opensAt: iso(10_000),
                next: image(0, 3),
            }),
        );
        await advanceTo(19_000);
        expect(held.resyncs).toEqual([]);
        held.stop();

        // Palier 1 de la manche suivante, inconnue du magasin (paquet relu
        // pendant la révélation, après `round.scheduled`) : sa garde se lit
        // dans `nextTransitionAt`, qu'aucune étape connue n'explique.
        vi.setSystemTime(ORIGIN_MS + 40_000);

        const revealing = harness(
            packet(40_000, {
                round: round(1, 0, 'revealing', {
                    images: [image(0, 1), image(0, 2), image(0, 3)],
                    endedAt: iso(30_000),
                    revealStartsAt: iso(30_300),
                    revealEndsAt: iso(38_300 + 10_000),
                    reveal: { movie: MOVIE, finders: [] },
                }),
                nextTransitionAt: iso(48_300 - LEAD_MS),
            }),
        );

        await advanceTo(48_300 - LEAD_MS - 1);
        expect(revealing.resyncs).toEqual([]);

        await advanceTo(48_300 - LEAD_MS);
        expect(revealing.resyncs).toEqual(['next_transition']);
    });

    it("passe à l'état de partie à game.launched sans naviguer", async () => {
        const lobby = harness(lobbyPacket(1000), settingsState(64));
        const channels = lobby.store.getState().channels;

        expect(lobby.store.getState().gameRef).toBeNull();

        // `round.scheduled` précède `game.launched` : rangé, pas appliqué au
        // lobby.
        lobby.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(1100, {
                round: timeline(1, 6100),
                image: image(6100, 1),
            }),
        );
        expect(lobby.store.getState().gameRef).toBeNull();

        lobby.store.receive(
            'game.launched',
            event<'game.launched'>(1105, {
                mode: 'multiplayer',
                roundsCount: 10,
                framesPerRound: 3,
                inputDifficulty: 'normal',
                revealDurationMs: 8000,
                speedBonus: true,
                seats: [
                    seat(SELF, { firstRoundNumber: 1 }),
                    seat(RIVAL, { firstRoundNumber: 1, isHost: false }),
                ],
            }),
        );

        const state = lobby.store.getState();

        // Même page, mêmes canaux : l'état de partie vient du magasin.
        expect(state.gameRef).toBe(GAME);
        expect(state.channels).toBe(channels);
        expect(state.exit).toBeNull();
        expect(state.status).toBe('running');
        expect(state.roundsCount).toBe(10);
        expect(state.inputDifficulty).toBe('normal');
        expect(state.maxAnswerLength).toBe(64);
        expect(state.seats.map((each) => each.firstRoundNumber)).toEqual([
            1, 1,
        ]);
        expect(state.self.member).toBe(true);
        expect(
            state.rounds.map((each) => [each.sequenceIndex, each.phase]),
        ).toEqual([[1, 'scheduled']]);
        expect(state.rounds[0].images).toEqual([image(6100, 1)]);
        expect(lobby.resyncs).toEqual([]);
        lobby.stop();

        // Sans le `round.scheduled` de la manche 1 : une seule relecture.
        const bare = harness(lobbyPacket(1000));

        bare.store.receive(
            'game.launched',
            event<'game.launched'>(1105, {
                mode: 'multiplayer',
                roundsCount: 10,
                framesPerRound: 3,
                inputDifficulty: 'expert',
                revealDurationMs: 8000,
                speedBonus: false,
                seats: [seat(SELF, { firstRoundNumber: 1 })],
            }),
        );

        expect(bare.store.getState().gameRef).toBe(GAME);
        expect(bare.resyncs).toEqual(['launched']);
    });

    it('demande une resynchronisation quand seat.choices manque après tier.opened du palier du QCM', async () => {
        const exhausted = {
            inputState: 'text_exhausted' as const,
            attemptsLeft: 0,
            choices: null,
            locked: null,
        };
        const playing = (input: GameStatePacket['self']['input']): Harness =>
            harness(
                packet(19_000, {
                    round: round(2, 0, 'running', {
                        currentTierIndex: 2,
                        images: [image(0, 2), image(0, 3)],
                    }),
                    self: { ...packet(0).self, input },
                    nextTransitionAt: iso(20_000),
                }),
            );
        const openChoicesTier = event<'tier.opened'>(20_000, {
            sequenceIndex: 2,
            roundNumber: 2,
            tierIndex: 3,
            opensAt: iso(20_000),
            next: null,
        });

        // Cas terminal de C11 : `tier.opened` du palier du QCM, et rien.
        vi.setSystemTime(ORIGIN_MS + 19_000);

        const terminal = playing(exhausted);

        await advanceTo(20_000);
        terminal.store.receive('tier.opened', openChoicesTier);

        await advanceTo(20_000 + HEARTBEAT_MS - 1);
        expect(terminal.resyncs).toEqual([]);

        await advanceTo(20_000 + HEARTBEAT_MS);
        expect(terminal.resyncs).toEqual(['choices_missing']);
        terminal.stop();

        // Témoin : les propositions arrivent juste après `tier.opened`.
        vi.setSystemTime(ORIGIN_MS + 19_000);

        const offered = playing(exhausted);

        await advanceTo(20_000);
        offered.store.receive('tier.opened', openChoicesTier);
        offered.store.receive(
            'seat.choices',
            event<'seat.choices'>(20_050, {
                sequenceIndex: 2,
                choices: ['A', 'B', 'C', 'D'],
                useOriginalTitle: false,
                lang: 'fr',
            }),
        );
        await advanceTo(20_000 + HEARTBEAT_MS * 2 - 1);

        expect(offered.resyncs).toEqual([]);
        expect(offered.store.getState().self.input?.choices?.choices).toEqual([
            'A',
            'B',
            'C',
            'D',
        ]);
        offered.stop();

        // Témoin : un siège déjà verrouillé n'attend aucune proposition.
        vi.setSystemTime(ORIGIN_MS + 19_000);

        const locked = playing({ ...exhausted, inputState: 'locked' });

        await advanceTo(20_000);
        locked.store.receive('tier.opened', openChoicesTier);
        await advanceTo(20_000 + HEARTBEAT_MS * 2 - 1);

        expect(locked.resyncs).toEqual([]);
    });

    // --- Ajouts (hors intitulés de la spec) ----------------------------------

    it('rejoue sur le paquet relu les événements reçus pendant la resynchronisation', async () => {
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const game = harness(
            packet(12_000, {
                round: round(3, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2)],
                }),
            }),
        );

        game.store.requestResync('visible');

        // Reçu pendant le vol, postérieur au paquet qui revient.
        game.store.receive(
            'player.locked',
            event<'player.locked'>(12_300, {
                sequenceIndex: 3,
                publicId: RIVAL,
                lockRank: 1,
            }),
        );

        await game.answer({
            kind: 'packet',
            packet: packet(12_200, {
                round: round(3, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2)],
                }),
            }),
        });

        expect(game.store.getState().rounds[0].locked).toEqual([
            { publicId: RIVAL, lockRank: 1 },
        ]);

        // Un événement antérieur au paquet y est déjà compris : ignoré.
        game.store.receive(
            'player.locked',
            event<'player.locked'>(12_100, {
                sequenceIndex: 3,
                publicId: SELF,
                lockRank: 2,
            }),
        );

        expect(game.store.getState().rounds[0].locked).toHaveLength(1);
    });

    it('pose exit à seat.kicked, room.archived ou un 403, et cesse alors toute relecture', async () => {
        const kicked = harness(lobbyPacket(1000));

        kicked.store.receive(
            'seat.kicked',
            event<'seat.kicked'>(1100, {}, null),
        );

        expect(kicked.store.getState().exit).toBe('kicked');

        kicked.store.requestResync('online');
        kicked.store.receive(
            'seat.superseded',
            event<'seat.superseded'>(1200, {}, null),
        );
        expect(kicked.resyncs).toEqual([]);

        const archived = harness(lobbyPacket(1000));

        archived.store.receive(
            'room.archived',
            event<'room.archived'>(1100, {}, null),
        );
        expect(archived.store.getState().exit).toBe('archived');

        const lost = harness(lobbyPacket(1000));

        lost.store.requestResync('reconnected');
        await lost.answer({ kind: 'lost' });
        expect(lost.store.getState().exit).toBe('lost');
    });

    it("repasse au lobby à room.replayed avec l'état des réglages reçu", () => {
        const game = harness(
            packet(1000, {
                round: round(1, 5000, 'scheduled', {
                    images: [image(5000, 1)],
                }),
            }),
            settingsState(60),
        );
        const replayed = settingsState(80);

        game.store.receive(
            'room.replayed',
            event<'room.replayed'>(2000, replayed),
        );

        const state = game.store.getState();

        expect(state.gameRef).toBeNull();
        expect(state.rounds).toEqual([]);
        expect(state.podium).toBeNull();
        expect(state.self.input).toBeNull();
        expect(
            state.seats.every((each) => each.firstRoundNumber === null),
        ).toBe(true);
        expect(state.settings?.settings.maxAnswerLength).toBe(80);
        expect(state.exit).toBeNull();
    });

    it('en solo, se relit à chaque nextTransitionAt et à chaque fetchNotBefore', async () => {
        vi.setSystemTime(ORIGIN_MS + 3000);

        const solo = harness(
            packet(3000, {
                mode: 'solo',
                channels: null,
                round: round(1, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1)],
                }),
                nextTransitionAt: iso(8000),
            }),
        );

        await advanceTo(7999);
        expect(solo.resyncs).toEqual([]);

        // `nextTransitionAt` est ici la garde du palier 2, sans URL : une
        // seule relecture, quel que soit le déclencheur qui la nomme.
        await advanceTo(8000);
        expect(solo.resyncs).toHaveLength(1);

        // Le paquet relu à la garde porte l'URL du palier 2 et la prochaine
        // étape : le sondage suivant part à `T₂`.
        await solo.answer({
            kind: 'packet',
            packet: packet(8000, {
                mode: 'solo',
                channels: null,
                round: round(1, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1), image(0, 2)],
                }),
                nextTransitionAt: iso(10_000),
            }),
        });

        await advanceTo(9999);
        expect(solo.resyncs).toHaveLength(1);

        await advanceTo(10_000);
        expect(solo.resyncs).toHaveLength(2);
        expect(solo.resyncs[1]).toBe('solo_poll');
    });

    it("se relit si aucun événement n'a suivi la prochaine étape attendue", async () => {
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const silent = harness(
            packet(12_000, {
                round: round(3, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2), image(0, 3)],
                }),
                nextTransitionAt: iso(20_000),
            }),
        );

        // `tier.opened` du palier 3 était attendu à 20 s.
        await advanceTo(20_000 + HEARTBEAT_MS - 1);
        expect(silent.resyncs).toEqual([]);

        await advanceTo(20_000 + HEARTBEAT_MS);
        expect(silent.resyncs).toEqual(['watchdog']);
    });

    it("montre la révélation jusqu'au début de la manche suivante", () => {
        const game = harness(
            packet(40_000, {
                round: round(1, 0, 'revealing', {
                    endedAt: iso(30_000),
                    revealStartsAt: iso(30_300),
                    revealEndsAt: iso(48_300),
                }),
            }),
        );

        game.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(40_100, {
                round: timeline(2, 48_300),
                image: image(48_300, 1),
            }),
        );

        const state = game.store.getState();

        expect(displayedRound(state, ORIGIN_MS + 48_299)?.sequenceIndex).toBe(
            1,
        );
        expect(displayedRound(state, ORIGIN_MS + 48_300)?.sequenceIndex).toBe(
            2,
        );

        // Au premier palier de la suivante, la manche révélée est oubliée :
        // c'est le changement de manche qui fait révoquer ses images.
        game.store.receive(
            'tier.opened',
            event<'tier.opened'>(48_300, {
                sequenceIndex: 2,
                roundNumber: 2,
                tierIndex: 1,
                opensAt: iso(48_300),
                next: image(48_300, 2),
            }),
        );

        expect(
            game.store.getState().rounds.map((each) => each.sequenceIndex),
        ).toEqual([2]);
    });

    it("oublie la saisie de la manche précédente et recalcule l'appartenance au premier palier de la suivante", async () => {
        const revealed = (atMs: number) =>
            event<'round.revealed'>(atMs, {
                sequenceIndex: 1,
                roundNumber: 1,
                revealEndsAt: iso(38_300),
                movie: MOVIE,
                images: [image(0, 1), image(0, 2), image(0, 3)],
                finders: [],
                leaderboard: { scoreless: false, roundNumber: 1, rows: [] },
            });
        /** Manche 1 jusqu'à la révélation, puis manche 2 jusqu'à `T_N`. */
        const playRounds = async (game: Harness): Promise<void> => {
            await advanceTo(20_000);
            game.store.receive(
                'tier.opened',
                event<'tier.opened'>(20_000, {
                    sequenceIndex: 1,
                    roundNumber: 1,
                    tierIndex: 3,
                    opensAt: iso(20_000),
                    next: null,
                }),
            );
            await advanceTo(30_000);
            game.store.receive(
                'round.closed',
                event<'round.closed'>(30_000, {
                    sequenceIndex: 1,
                    roundNumber: 1,
                    endedAt: iso(30_000),
                    revealStartsAt: iso(30_300),
                    revealEndsAt: iso(38_300),
                }),
            );
            await advanceTo(30_300);
            game.store.receive('round.revealed', revealed(30_300));
            game.store.receive(
                'round.scheduled',
                event<'round.scheduled'>(30_310, {
                    round: timeline(2, 38_300),
                    image: image(38_300, 1),
                }),
            );

            for (const tierIndex of [1, 2, 3]) {
                const opensAt = 38_300 + offsetOf(tierIndex);

                await advanceTo(opensAt);
                game.store.receive(
                    'tier.opened',
                    event<'tier.opened'>(opensAt, {
                        sequenceIndex: 2,
                        roundNumber: 2,
                        tierIndex,
                        opensAt: iso(opensAt),
                        next:
                            tierIndex < 3 ? image(38_300, tierIndex + 1) : null,
                    }),
                );
            }
        };

        // Verrouillé à la manche 1 : aucune relecture entre deux manches.
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const found = harness(
            packet(12_000, {
                round: round(1, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2), image(0, 3)],
                    locked: [{ publicId: SELF, lockRank: 1 }],
                }),
                self: {
                    ...packet(0).self,
                    input: {
                        inputState: 'locked',
                        attemptsLeft: 4,
                        choices: null,
                        locked: {
                            lockRank: 1,
                            tierIndex: 2,
                            pointsTier: 200,
                            pointsBonus: 50,
                            pointsTotal: 250,
                        },
                    },
                },
            }),
        );

        await playRounds(found);

        // Au premier palier de la manche 2, la saisie de la manche 1 n'est
        // plus présentée ; au palier du QCM, sans `seat.choices` (cas
        // terminal), le siège — membre, saisie inconnue — se relit.
        const opened = found.store.getState();

        expect(opened.rounds.map((each) => each.sequenceIndex)).toEqual([2]);
        expect(opened.self.input).toBeNull();
        expect(opened.self.member).toBe(true);

        await advanceTo(58_300 + HEARTBEAT_MS - 1);
        expect(found.resyncs).toEqual([]);

        await advanceTo(58_300 + HEARTBEAT_MS);
        expect(found.resyncs).toEqual(['choices_missing']);
        found.stop();

        // Retardataire admis pour la manche 2 : en attente à la manche 1
        // (aucune image), membre dès son premier palier.
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const waiting = harness(
            packet(12_000, {
                round: round(1, 0, 'running', { currentTierIndex: 2 }),
                seats: [
                    seat(SELF, { firstRoundNumber: 2 }),
                    seat(RIVAL, { firstRoundNumber: 1 }),
                ],
                self: {
                    ...packet(0).self,
                    member: false,
                    participates: false,
                    input: null,
                },
            }),
        );

        await playRounds(waiting);

        const state = waiting.store.getState();

        // `member` venait du paquet (faux) ; il suit la manche ouverte.
        expect(state.self.member).toBe(true);
        expect(state.self.participates).toBe(true);
        expect(isMemberOfRound(state.seats, SELF, timeline(1, 0))).toBe(false);
        expect(isMemberOfRound(state.seats, SELF, timeline(2, 0))).toBe(true);
        expect(waiting.resyncs).toEqual([]);
        waiting.stop();

        // Siège qui attend la partie suivante, sans participation : absent
        // des sièges de la partie, il reçoit en partie sa propre vue de LOBBY
        // (`seat.updated` de présence, `firstRoundNumber` nul), que le magasin
        // ajoute aux sièges — il n'est membre d'aucune manche.
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const outsider = harness(
            packet(12_000, {
                round: round(1, 0, 'running', { currentTierIndex: 2 }),
                seats: [seat(RIVAL, { firstRoundNumber: 1, isHost: true })],
                self: {
                    ...packet(0).self,
                    isHost: false,
                    member: false,
                    participates: false,
                    input: null,
                },
            }),
        );

        await advanceTo(12_500);
        outsider.store.receive(
            'seat.updated',
            event<'seat.updated'>(12_500, {
                seat: seat(SELF, { isHost: false }),
            }),
        );
        await playRounds(outsider);

        const outside = outsider.store.getState();

        expect(outside.seats.map((each) => each.publicId)).toContain(SELF);
        expect(outside.self.member).toBe(false);
        expect(outside.self.participates).toBe(false);
        expect(isMemberOfRound(outside.seats, SELF, timeline(2, 0))).toBe(
            false,
        );
        outsider.stop();

        // Réponse de soumission arrivée avant `tier.opened` du palier 1 (le
        // joueur répond à `T₁`, l'événement tarde) : la saisie de CETTE
        // manche est gardée, sans rien de celle de la manche révélée.
        vi.setSystemTime(ORIGIN_MS + 30_400);

        const openFirstTier = event<'tier.opened'>(38_300, {
            sequenceIndex: 2,
            roundNumber: 2,
            tierIndex: 1,
            opensAt: iso(38_300),
            next: image(38_300, 2),
        });
        const quick = harness(
            packet(30_400, {
                round: round(1, 0, 'revealing', {
                    endedAt: iso(30_000),
                    revealStartsAt: iso(30_300),
                    revealEndsAt: iso(38_300),
                    reveal: { movie: MOVIE, finders: [] },
                }),
                self: {
                    ...packet(0).self,
                    input: {
                        inputState: 'locked',
                        attemptsLeft: 4,
                        choices: null,
                        locked: {
                            lockRank: 1,
                            tierIndex: 2,
                            pointsTier: 200,
                            pointsBonus: 50,
                            pointsTotal: 250,
                        },
                    },
                },
            }),
        );

        quick.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(30_500, {
                round: timeline(2, 38_300),
                image: image(38_300, 1),
            }),
        );
        await advanceTo(38_600);
        quick.store.applySubmission(2, {
            result: 'rejected',
            inputState: 'open',
            attemptsLeft: 4,
        });
        quick.store.receive('tier.opened', openFirstTier);

        expect(quick.store.getState().self.input).toEqual({
            inputState: 'open',
            attemptsLeft: 4,
            choices: null,
            locked: null,
        });
        expect(quick.resyncs).toEqual([]);
        quick.stop();

        // Saisie inconnue d'un siège déjà verrouillé (réponse de soumission
        // perdue, `player.locked` reçu) : aucune proposition attendue.
        vi.setSystemTime(ORIGIN_MS + 30_400);

        const unknown = harness(
            packet(30_400, {
                round: round(2, 38_300, 'scheduled', {
                    images: [image(38_300, 1)],
                }),
                self: { ...packet(0).self, input: null },
            }),
        );

        await advanceTo(38_300);
        unknown.store.receive('tier.opened', openFirstTier);
        await advanceTo(39_000);
        unknown.store.receive(
            'player.locked',
            event<'player.locked'>(39_000, {
                sequenceIndex: 2,
                publicId: SELF,
                lockRank: 1,
            }),
        );

        for (const tierIndex of [2, 3]) {
            const opensAt = 38_300 + offsetOf(tierIndex);

            await advanceTo(opensAt);
            unknown.store.receive(
                'tier.opened',
                event<'tier.opened'>(opensAt, {
                    sequenceIndex: 2,
                    roundNumber: 2,
                    tierIndex,
                    opensAt: iso(opensAt),
                    next: tierIndex < 3 ? image(38_300, tierIndex + 1) : null,
                }),
            );
        }

        await advanceTo(58_300 + HEARTBEAT_MS);
        expect(unknown.resyncs).toEqual([]);
    });

    it('rejoue sur un paquet de prop les événements qui lui sont postérieurs', () => {
        // Au lobby, la réponse d'une visite (réglages enregistrés) porte un
        // paquet construit AVANT un `seat.joined` déjà reçu.
        const lobby = harness(lobbyPacket(1000));
        const newcomer = seat('NEWSEAT0000C', { isHost: false });

        lobby.store.receive(
            'seat.joined',
            event<'seat.joined'>(1300, { seat: newcomer }, null),
        );
        lobby.store.applyPacket(lobbyPacket(1200));

        expect(
            lobby.store.getState().seats.map((each) => each.publicId),
        ).toEqual([SELF, RIVAL, 'NEWSEAT0000C']);

        // Un paquet postérieur le comprend : rien n'est rejoué en double.
        lobby.store.applyPacket({
            ...lobbyPacket(1400),
            seats: [seat(SELF), seat(RIVAL), newcomer],
        });

        expect(lobby.store.getState().seats).toHaveLength(3);

        // En partie : un verrouillage reçu après la construction du paquet.
        vi.setSystemTime(ORIGIN_MS + 12_000);

        const running = (atMs: number): GameStatePacket =>
            packet(atMs, {
                round: round(3, 0, 'running', {
                    currentTierIndex: 2,
                    images: [image(0, 2)],
                }),
            });
        const game = harness(running(12_000));

        game.store.receive(
            'player.locked',
            event<'player.locked'>(12_300, {
                sequenceIndex: 3,
                publicId: RIVAL,
                lockRank: 1,
            }),
        );
        game.store.applyPacket(running(12_200));

        expect(game.store.getState().rounds[0].locked).toEqual([
            { publicId: RIVAL, lockRank: 1 },
        ]);
        expect(game.resyncs).toEqual([]);
    });

    it("oublie la référence d'un palier que la fin anticipée a empêché de s'ouvrir", () => {
        vi.setSystemTime(ORIGIN_MS + 9000);

        const game = harness(
            packet(9000, {
                round: round(1, 0, 'running', {
                    currentTierIndex: 1,
                    images: [image(0, 1), image(0, 2)],
                }),
            }),
        );

        // Tous verrouillés à 9,5 s : le palier 2 (T₂ = 10 s) ne s'ouvrira
        // pas, et le serveur refuserait son image.
        game.store.receive(
            'round.closed',
            event<'round.closed'>(9500, {
                sequenceIndex: 1,
                roundNumber: 1,
                endedAt: iso(9500),
                revealStartsAt: iso(9800),
                revealEndsAt: iso(17_800),
            }),
        );

        expect(
            game.store
                .getState()
                .rounds[0].images.map((each) => each.tierIndex),
        ).toEqual([1]);

        // La révélation remontre les seuls paliers ouverts qu'elle nomme.
        game.store.receive(
            'round.revealed',
            event<'round.revealed'>(9800, {
                sequenceIndex: 1,
                roundNumber: 1,
                revealEndsAt: iso(17_800),
                movie: MOVIE,
                images: [image(0, 1)],
                finders: [],
                leaderboard: { scoreless: false, roundNumber: 1, rows: [] },
            }),
        );

        expect(game.store.getState().rounds[0].images).toEqual([image(0, 1)]);

        // Film suspendu pendant la révélation : ses URL sont omises (60
        // § 15.4), et celles que le magasin détenait ne sont plus demandées.
        vi.setSystemTime(ORIGIN_MS + 30_100);

        const suspended = harness(
            packet(30_100, {
                round: round(5, 0, 'closed', {
                    images: [image(0, 3)],
                    endedAt: iso(30_000),
                    revealStartsAt: iso(30_300),
                    revealEndsAt: iso(38_300),
                }),
            }),
        );

        suspended.store.receive(
            'round.revealed',
            event<'round.revealed'>(30_300, {
                sequenceIndex: 5,
                roundNumber: 5,
                revealEndsAt: iso(38_300),
                movie: MOVIE,
                images: [],
                finders: [],
                leaderboard: { scoreless: false, roundNumber: 5, rows: [] },
            }),
        );

        expect(suspended.store.getState().rounds[0].images).toEqual([]);
        suspended.stop();

        // Une manche annulée n'a plus aucune image à demander.
        game.store.receive(
            'round.scheduled',
            event<'round.scheduled'>(9900, {
                round: timeline(2, 17_800),
                image: image(17_800, 1),
            }),
        );
        game.store.receive(
            'round.cancelled',
            event<'round.cancelled'>(10_000, {
                sequenceIndex: 2,
                roundNumber: 2,
            }),
        );

        expect(
            game.store
                .getState()
                .rounds.find((each) => each.sequenceIndex === 2)?.images,
        ).toEqual([]);
    });
});
