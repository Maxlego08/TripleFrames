import { describe, expect, it } from 'vite-plus/test';
import type { HistoryRow } from '@/lib/account/play-history';
import {
    formatInteger,
    formatRank,
    formatSuccessRate,
    originLine,
    roundsLine,
} from '@/lib/account/play-history';

/*
 * Mise en forme des compteurs et des lignes de « Mes parties » (spec 40
 * § 13.4, L40-13) : module pur, `Intl` dans la langue du joueur.
 */

function row(overrides: Partial<HistoryRow> = {}): HistoryRow {
    return {
        publicId: 'ABCDEFGHJKMN',
        endedAt: '2026-10-01T12:00:00.000Z',
        mode: 'multiplayer',
        roomCode: 'K7Q2XP',
        status: 'completed',
        roundsCompleted: 10,
        roundsCount: 10,
        framesPerRound: 3,
        roundsPlayed: 10,
        correctAnswers: 6,
        finalScore: 1400,
        rank: 2,
        ...overrides,
    };
}

describe('formatSuccessRate', () => {
    it('rend un pourcentage entier dans la langue du joueur', () => {
        expect(formatSuccessRate(0.5, 'en')).toBe('50%');
        expect(formatSuccessRate(0.666, 'fr')).toMatch(/^67\s?%$/u);
    });

    it('ne dépasse jamais cent pour cent et ne descend jamais sous zéro', () => {
        expect(formatSuccessRate(1.4, 'en')).toBe('100%');
        expect(formatSuccessRate(-0.2, 'en')).toBe('0%');
    });

    it('rend nul sous le seuil, quand le serveur ne publie pas de taux', () => {
        expect(formatSuccessRate(null, 'fr')).toBeNull();
        expect(formatSuccessRate(Number.NaN, 'fr')).toBeNull();
    });
});

describe('formatInteger et formatRank', () => {
    it('groupe les milliers selon la locale', () => {
        expect(formatInteger(12500, 'en')).toBe('12,500');
        expect(formatInteger(12500, 'fr')).toMatch(/^12\s500$/u);
    });

    it('rend un tiret (nul) en solo ou sans rang', () => {
        expect(formatRank(row(), 'en')).toBe('2');
        expect(formatRank(row({ mode: 'solo', rank: null }), 'en')).toBeNull();
        expect(formatRank(row({ rank: null }), 'en')).toBeNull();
    });
});

describe('lignes de partie', () => {
    it('distingue une partie interrompue', () => {
        expect(roundsLine(row(), 'en').key).toBe('account.history.list.rounds');
        expect(
            roundsLine(
                row({ status: 'interrupted', roundsCompleted: 4 }),
                'en',
            ),
        ).toEqual({
            key: 'account.history.list.interrupted',
            replacements: { completed: '4', total: '10' },
        });
    });

    it('nomme le salon, ou « Solo »', () => {
        expect(originLine(row())).toEqual({
            key: 'account.history.list.room',
            replacements: { code: 'K7Q2XP' },
        });
        expect(originLine(row({ mode: 'solo', roomCode: null })).key).toBe(
            'account.history.list.solo',
        );
    });
});
