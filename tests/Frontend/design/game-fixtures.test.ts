import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { buildGameFixture } from '@/lib/design/game-fixtures';
import type { GameFixtureInput } from '@/lib/design/game-fixtures';
import {
    AUTH_SCENARIO_KEYS,
    GAME_SCENARIO_KEYS,
    LIVE_SCENARIO_KEYS,
    PUBLIC_SCENARIO_KEYS,
    isFixtureScenarioKey,
    isLiveScenarioKey,
} from '@/lib/design/scenario-keys';
import { createGameStore, displayedRound } from '@/lib/game/store';
import type { GameStatePacket } from '@/types/game-wire';
import type { RoomSettingsState } from '@/types/room-settings';

/*
 * Les paquets fictifs du banc d'essai du design (spec 20 § 13.8, demande du
 * porteur du 08/10) : chacun passe le VRAI magasin de jeu sans erreur, et dit
 * l'état que son scénario annonce. Réglages : des données de test, de la
 * forme de `RoomSettingsPresenter::state()`.
 */

const SETTINGS: RoomSettingsState = {
    settings: {
        themeKeys: [],
        roundsCount: 10,
        framesPerRound: 3,
        tierDurations: [10, 10, 10],
        tierPoints: [300, 200, 100],
        revealDuration: 8,
        inputDifficulty: 'normal',
        capacity: 12,
        allowLateJoin: true,
        speedBonus: true,
        noRepeatMovies: true,
        attemptsPerSecond: 1,
        attemptsPerRound: 6,
        maxAnswerLength: 100,
        disconnectGraceSeconds: 30,
        advanced: false,
    },
    warnings: [],
    advancedActive: [],
    pool: {
        count: 60,
        framesPerRound: 3,
        roundsCount: 10,
        blocked: false,
        causes: [],
        remedies: [],
        nearestPlayableFramesPerRound: null,
        themesPruned: false,
    },
};

const NOW = Date.UTC(2026, 9, 8, 12, 0, 0);

function input(images: string[] = ['/img/1', '/img/2']): GameFixtureInput {
    return {
        nowMs: NOW,
        images,
        avatars: [
            {
                key: 'preset-01',
                url: '/avatars/preset-01.webp',
                labelKey: 'common.avatar.preset.preset-01',
            },
            {
                key: 'preset-02',
                url: '/avatars/preset-02.webp',
                labelKey: 'common.avatar.preset.preset-02',
            },
        ],
        settings: SETTINGS,
        locale: 'fr',
    };
}

function packetOf(key: (typeof GAME_SCENARIO_KEYS)[number]): GameStatePacket {
    const fixture = buildGameFixture(key, input());

    if (fixture.page === 'room_expired') {
        throw new Error(`${key} : aucun paquet`);
    }

    return fixture.packet;
}

afterEach(() => {
    vi.useRealTimers();
});

describe('registre client', () => {
    it('range chaque clé dans une seule sorte', () => {
        const all = [
            ...LIVE_SCENARIO_KEYS,
            ...GAME_SCENARIO_KEYS,
            ...AUTH_SCENARIO_KEYS,
            ...PUBLIC_SCENARIO_KEYS,
        ];

        expect(new Set(all).size).toBe(all.length);

        for (const key of all) {
            expect(isLiveScenarioKey(key) !== isFixtureScenarioKey(key)).toBe(
                true,
            );
        }
    });
});

describe('paquets fictifs', () => {
    it.each(GAME_SCENARIO_KEYS.filter((key) => key !== 'game.room_expired'))(
        '%s passe le magasin de jeu sans erreur',
        (key) => {
            vi.useFakeTimers();
            vi.setSystemTime(NOW);

            const packet = packetOf(key);
            const store = createGameStore({
                initial: packet,
                settings: SETTINGS,
                resync: () => Promise.resolve({ kind: 'packet', packet }),
                now: () => Date.now(),
                heartbeatIntervalMs: 10_000,
            });

            // Un paquet de même forme, un instant plus tard : `applyPacket`.
            store.applyPacket({
                ...packet,
                serverNow: new Date(NOW + 1).toISOString(),
            });

            const state = store.getState();

            expect(state.seats.length).toBeGreaterThan(0);
            expect(state.self.publicId).toBe(packet.self.publicId);
            expect(state.channels).toBeNull();

            if (packet.round !== null) {
                expect(displayedRound(state, NOW)?.roundNumber).toBe(
                    packet.round.roundNumber,
                );
            }

            store.dispose();
        },
    );

    it('rend le salon expiré sans paquet', () => {
        expect(buildGameFixture('game.room_expired', input()).page).toBe(
            'room_expired',
        );
    });

    it('dit les états annoncés par les scénarios', () => {
        expect(packetOf('game.lobby_host').gameRef).toBeNull();
        expect(packetOf('game.lobby_host').self.isHost).toBe(true);
        expect(packetOf('game.lobby_guest').self.isHost).toBe(false);
        expect(packetOf('game.round_expert').round?.choicesAtTierIndex).toBe(
            null,
        );
        expect(packetOf('game.round_easy').round?.choicesAtTierIndex).toBe(1);
        expect(packetOf('game.round_normal_qcm').round?.currentTierIndex).toBe(
            3,
        );
        expect(
            packetOf('game.round_text_exhausted').self.input?.inputState,
        ).toBe('text_exhausted');
        expect(packetOf('game.round_locked').self.input?.inputState).toBe(
            'locked',
        );
        expect(packetOf('game.round_cancelled').round?.images).toEqual([]);
        expect(packetOf('game.pause_manual').pause?.kind).toBe('manual');
        expect(packetOf('game.pause_empty').pause?.kind).toBe('empty');
        expect(packetOf('game.pause_requested').pauseRequested).toBe(true);
        expect(packetOf('game.reveal_nobody').round?.reveal?.finders).toEqual(
            [],
        );
        expect(
            packetOf('game.reveal_found').round?.reveal?.finders.length,
        ).toBeGreaterThan(0);
        expect(packetOf('game.podium_interrupted').podium?.gameStatus).toBe(
            'interrupted',
        );
        expect(packetOf('game.podium_scoreless').podium?.scoreless).toBe(true);
        expect(packetOf('game.solo_round').mode).toBe('solo');
        expect(packetOf('game.late_joiner').self.member).toBe(false);

        const blocked = buildGameFixture('game.lobby_blocked', input());

        expect(blocked.page === 'lobby' && blocked.settings.pool.blocked).toBe(
            true,
        );
    });

    it('lit les paliers et les valeurs dans les réglages', () => {
        const round = packetOf('game.round_normal').round;

        expect(round?.tiers.map((tier) => tier.durationMs)).toEqual([
            10_000, 10_000, 10_000,
        ]);
        expect(round?.tiers.map((tier) => tier.points)).toEqual([
            300, 200, 100,
        ]);
        expect(round?.durationMs).toBe(30_000);
    });

    it('garde les quatre propositions indiscernables et sans doublon', () => {
        for (const key of [
            'game.round_easy',
            'game.round_normal_qcm',
        ] as const) {
            const choices = packetOf(key).self.input?.choices;

            expect(choices?.choices).toHaveLength(4);
            expect(new Set(choices?.choices).size).toBe(4);
            expect(Object.keys(choices ?? {}).toSorted()).toEqual([
                'choices',
                'lang',
                'useOriginalTitle',
            ]);
        }
    });

    it('prête les images reçues aux paliers et démarre la manche en cours dans le passé', () => {
        const round = packetOf('game.round_normal').round;

        expect(round?.images.map((image) => image.url)).toEqual([
            '/img/1',
            '/img/2',
            '/img/1',
        ]);
        expect(Date.parse(round?.startsAt ?? '')).toBeLessThan(NOW);
        expect(
            round?.images.every(
                (image) => Date.parse(image.fetchNotBefore) < NOW,
            ),
        ).toBe(true);
    });
});
