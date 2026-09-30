import { describe, expect, it } from 'vite-plus/test';
import {
    announcementThresholds,
    currentTier,
    tierValueAt,
    toLiveTimeline,
} from '@/lib/game/round-timeline';
import type { LiveRoundTimeline } from '@/lib/game/round-timeline';
import type { RoundTimeline } from '@/types/game-wire';
import type { TierWindow } from '@/types/scoring';

/*
 * Chronologie cliente d'une manche (spec 90 § 7.3, contrat C16 § 2.6 et
 * § 4) : seule implémentation client du palier courant et de sa valeur
 * affichée (D29, R-35 ; `tierValueAt`, fonction de 80 § 14, lot L80-6) et
 * des seuils d'annonce relatifs à `D`.
 *
 * Module pur, aucun DOM (C18 § 2.4) : l'instant serveur est passé en
 * millisecondes depuis l'époque Unix, comme le rend `serverNow()`.
 */

/** Origine arbitraire des scénarios : 23/09/2026 14:05:03.000 UTC. */
const STARTS_AT_MS = Date.UTC(2026, 8, 23, 14, 5, 3, 0);

/** Fenêtres de paliers contiguës, depuis leurs durées et leurs valeurs. */
function tiersOf(durations: readonly number[], points: readonly number[]) {
    let offset = 0;

    return durations.map((durationMs, index): TierWindow => {
        const tier = {
            tierIndex: index + 1,
            startsAtOffsetMs: offset,
            durationMs,
            points: points[index],
        };

        offset += durationMs;

        return tier;
    });
}

/** Une charge `RoundTimeline` (C7) de durée `D` = somme des paliers. */
function roundOf(tiers: TierWindow[]): RoundTimeline {
    return {
        sequenceIndex: 4,
        roundNumber: 4,
        roundsCount: 10,
        startsAt: new Date(STARTS_AT_MS).toISOString(),
        durationMs: tiers.reduce((sum, tier) => sum + tier.durationMs, 0),
        tiers,
        choicesAtTierIndex: tiers.length,
    };
}

/** Réglage par défaut : `D` = 30 s, `N` = 3, barème 300 / 200 / 100. */
function defaultTimeline(): LiveRoundTimeline {
    return toLiveTimeline(
        '3f9a0c1d2e4b5a69',
        roundOf(tiersOf([10_000, 10_000, 10_000], [300, 200, 100])),
        false,
    );
}

/** Une chronologie de durée `D` seule, à un palier (seuils de temps seuls). */
function timelineOfDuration(durationMs: number): LiveRoundTimeline {
    return toLiveTimeline('g', roundOf(tiersOf([durationMs], [100])), false);
}

/** Les seuils de temps (hors paliers) d'une durée `D`, par nature. */
function timeThresholds(durationMs: number): Record<string, number> {
    return Object.fromEntries(
        announcementThresholds(timelineOfDuration(durationMs)).map(
            (threshold) => [threshold.kind, threshold.atMs],
        ),
    );
}

describe('chronologie cliente', () => {
    it("rend le palier dont la fenêtre contient l'instant serveur", () => {
        const timeline = defaultTimeline();

        expect(timeline.key).toBe('3f9a0c1d2e4b5a69:4');
        expect(timeline.startedAtMs).toBe(STARTS_AT_MS);
        expect(timeline.durationMs).toBe(30_000);
        expect(timeline.closed).toBe(false);

        const tierAt = (elapsedMs: number): number | null =>
            currentTier(timeline, STARTS_AT_MS + elapsedMs)?.tierIndex ?? null;

        // Fenêtres semi-ouvertes [Tᵢ, Tᵢ + dᵢ), sans grâce : la bascule a
        // lieu à Tᵢ exactement.
        expect(tierAt(0)).toBe(1);
        expect(tierAt(9_999)).toBe(1);
        expect(tierAt(10_000)).toBe(2);
        expect(tierAt(19_999)).toBe(2);
        expect(tierAt(20_000)).toBe(3);
        expect(tierAt(29_999)).toBe(3);

        // La fenêtre rendue est celle de la charge, valeur comprise.
        expect(currentTier(timeline, STARTS_AT_MS + 15_000)).toEqual({
            tierIndex: 2,
            startsAtOffsetMs: 10_000,
            durationMs: 10_000,
            points: 200,
        });

        // Paliers inégaux, reçus dans le désordre : l'ordre ne vient que de
        // `tierIndex`, la fenêtre que des décalages.
        const uneven = toLiveTimeline(
            'g',
            roundOf(tiersOf([6_000, 4_000], [200, 100]).toReversed()),
            false,
        );

        expect(uneven.tiers.map((tier) => tier.tierIndex)).toEqual([1, 2]);
        expect(currentTier(uneven, STARTS_AT_MS + 5_999)?.tierIndex).toBe(1);
        expect(currentTier(uneven, STARTS_AT_MS + 6_000)?.tierIndex).toBe(2);

        // La clôture ne change pas la fenêtre : masquer la valeur revient au
        // sélecteur de 60, l'image ouverte reste celle de son palier.
        const closed = toLiveTimeline(
            '3f9a0c1d2e4b5a69',
            roundOf(tiersOf([10_000, 10_000, 10_000], [300, 200, 100])),
            true,
        );

        expect(closed.closed).toBe(true);
        expect(currentTier(closed, STARTS_AT_MS + 12_000)?.tierIndex).toBe(2);
    });

    it('rend null avant le début et après D', () => {
        const timeline = defaultTimeline();

        expect(currentTier(timeline, STARTS_AT_MS - 1)).toBeNull();
        expect(currentTier(timeline, STARTS_AT_MS - 8_000)).toBeNull();
        expect(currentTier(timeline, STARTS_AT_MS + 30_000)).toBeNull();
        expect(currentTier(timeline, STARTS_AT_MS + 30_001)).toBeNull();
        expect(currentTier(timeline, STARTS_AT_MS + 38_000)).toBeNull();
        expect(currentTier(timeline, Number.NaN)).toBeNull();

        // Bornes incluse et exclue de [0, D).
        expect(currentTier(timeline, STARTS_AT_MS)).not.toBeNull();
        expect(currentTier(timeline, STARTS_AT_MS + 29_999)).not.toBeNull();
    });

    it('calcule en millisecondes entières les seuils de mi-manche, de dernier quart et de dernier dixième plafonné', () => {
        // Réglage par défaut (spec 90 § 7.4) : 15 s, 22,5 s, 27 s (3 s
        // restantes), paliers 2 et 3 à 10 s et 20 s.
        expect(announcementThresholds(defaultTimeline())).toEqual([
            { id: 'tier:2', atMs: 10_000, kind: 'tier', tierIndex: 2 },
            { id: 'halfway', atMs: 15_000, kind: 'halfway' },
            { id: 'tier:3', atMs: 20_000, kind: 'tier', tierIndex: 3 },
            { id: 'last_quarter', atMs: 22_500, kind: 'last_quarter' },
            { id: 'last_tenth', atMs: 27_000, kind: 'last_tenth' },
        ]);

        // Borne basse de D : le dernier dixième n'est pas plafonné.
        expect(timeThresholds(10_000)).toEqual({
            halfway: 5_000,
            last_quarter: 7_500,
            last_tenth: 9_000,
        });

        // Borne haute de D : un dixième ferait 12 s, plafonné à 5 s.
        expect(timeThresholds(120_000)).toEqual({
            halfway: 60_000,
            last_quarter: 90_000,
            last_tenth: 115_000,
        });

        // Autour du plafond : ⌊D/10⌋ l'atteint à 50 s, pas à 49,999 s.
        expect(timeThresholds(50_000).last_tenth).toBe(45_000);
        expect(timeThresholds(49_999).last_tenth).toBe(45_000);
        expect(timeThresholds(60_000).last_tenth).toBe(55_000);

        // D quelconque : planchers, jamais de demi-milliseconde.
        expect(timeThresholds(10_001)).toEqual({
            halfway: 5_001,
            last_quarter: 7_501,
            last_tenth: 9_001,
        });
        expect(timeThresholds(33_333)).toEqual({
            halfway: 16_667,
            last_quarter: 25_000,
            last_tenth: 30_000,
        });

        for (const durationMs of [10_000, 10_001, 33_333, 49_999, 120_000]) {
            for (const threshold of announcementThresholds(
                timelineOfDuration(durationMs),
            )) {
                expect(Number.isInteger(threshold.atMs)).toBe(true);
                expect(threshold.atMs).toBeGreaterThan(0);
                expect(threshold.atMs).toBeLessThan(durationMs);
            }
        }

        // Deux paliers égaux à la borne basse : mi-manche et palier 2
        // coïncident, la nouvelle image d'abord ; le palier 1 et la fin ne
        // sont jamais des seuils.
        expect(
            announcementThresholds(
                toLiveTimeline(
                    'g',
                    roundOf(tiersOf([5_000, 5_000], [200, 100])),
                    false,
                ),
            ),
        ).toEqual([
            { id: 'tier:2', atMs: 5_000, kind: 'tier', tierIndex: 2 },
            { id: 'halfway', atMs: 5_000, kind: 'halfway' },
            { id: 'last_quarter', atMs: 7_500, kind: 'last_quarter' },
            { id: 'last_tenth', atMs: 9_000, kind: 'last_tenth' },
        ]);
    });

    it('tierValueAt rend la valeur entière du palier courant, sans grâce ni bonus', () => {
        // Réglage par défaut (spec 90 § 7.3) : 300, 200 puis 100.
        const timeline = defaultTimeline();
        const valueAt = (elapsedMs: number): number | null =>
            tierValueAt(timeline.tiers, elapsedMs);

        // Sans bonus : à t = 0, la valeur du palier seule (le serveur y
        // créditerait 300 + 150), identique jusqu'à la dernière milliseconde
        // de la fenêtre — aucune valeur dégressive.
        expect(valueAt(0)).toBe(300);
        expect(valueAt(1)).toBe(300);
        expect(valueAt(9_999)).toBe(300);

        // Sans grâce : la bascule a lieu à Tᵢ exactement, alors que le
        // serveur crédite encore le palier précédent pendant tier_grace_ms.
        expect(valueAt(10_000)).toBe(200);
        expect(valueAt(10_001)).toBe(200);
        expect(valueAt(19_999)).toBe(200);
        expect(valueAt(20_000)).toBe(100);
        expect(valueAt(29_999)).toBe(100);

        // Hors de [0, D) : null, jamais 0 ni la valeur d'un palier voisin.
        expect(valueAt(-1)).toBeNull();
        expect(valueAt(30_000)).toBeNull();
        expect(valueAt(38_000)).toBeNull();
        expect(valueAt(Number.NaN)).toBeNull();
        expect(tierValueAt([], 0)).toBeNull();

        // Même instant de bascule que l'image : à tout instant serveur, la
        // valeur est celle du palier de currentTier(), ou null avec lui.
        for (let elapsedMs = -1_000; elapsedMs <= 31_000; elapsedMs += 250) {
            for (const nudgeMs of [-1, 0, 1]) {
                const serverNowMs = STARTS_AT_MS + elapsedMs + nudgeMs;
                const value = tierValueAt(
                    timeline.tiers,
                    serverNowMs - timeline.startedAtMs,
                );

                expect(value).toBe(
                    currentTier(timeline, serverNowMs)?.points ?? null,
                );
                expect(value === null || Number.isInteger(value)).toBe(true);
            }
        }

        // Barème personnalisé (onglet Avancé) à N = 5, durées inégales et
        // paliers reçus dans le désordre : un palier à 0 rend 0, pas null ;
        // l'ordre ne vient que des décalages.
        const custom = tiersOf(
            [5_000, 7_000, 6_000, 5_000, 12_000],
            [0, 1_000, 0, 250, 0],
        ).toReversed();

        expect(tierValueAt(custom, 0)).toBe(0);
        expect(tierValueAt(custom, 4_999)).toBe(0);
        expect(tierValueAt(custom, 5_000)).toBe(1_000);
        expect(tierValueAt(custom, 11_999)).toBe(1_000);
        expect(tierValueAt(custom, 12_000)).toBe(0);
        expect(tierValueAt(custom, 18_000)).toBe(250);
        expect(tierValueAt(custom, 23_000)).toBe(0);
        expect(tierValueAt(custom, 34_999)).toBe(0);
        expect(tierValueAt(custom, 35_000)).toBeNull();

        // La clôture ne change pas la valeur d'un instant : la masquer
        // revient au sélecteur de 60 (`visibleTierValue()`), jamais ici.
        const closed = toLiveTimeline(
            '3f9a0c1d2e4b5a69',
            roundOf(tiersOf([10_000, 10_000, 10_000], [300, 200, 100])),
            true,
        );

        expect(tierValueAt(closed.tiers, 12_000)).toBe(200);
    });
});
