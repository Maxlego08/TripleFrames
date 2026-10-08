import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import {
    advancedFramesPerRoundChange,
    choicesAtPercent,
    crossBoundErrors,
    defaultAttemptsPerRound,
    defaultTierDurations,
    defaultTierPoints,
    framesPerRoundBound,
    framesPerRoundChange,
    framesPerRoundOptions,
    minRoundDuration,
    settingsChangeLines,
    settingsChangesFrom,
    waitingPays,
    warnings,
} from '@/lib/room-settings';
import type {
    RoomSettingsBoundsPayload,
    RoomSettingsWarningCode,
} from '@/types/room-settings';

/*
 * Parité des dérivations client avec le serveur (spec 50 § 4.3).
 *
 * Le jeu `tests/Fixtures/room/derivations.json` est écrit par
 * `php artisan room:derivations-fixture`, à la main, après un changement voulu
 * des bornes ; `RoomSettingsDerivationParityTest` (Pest) exige ses valeurs du
 * serveur, ce test les exige de `lib/room-settings.ts`. Ce test lit le jeu, il
 * ne l'écrit jamais. Toute borne et tout seuil viennent du jeu : aucun
 * littéral de jeu n'est écrit ici.
 */

type DerivationCase = {
    n: number;
    d: number;
    r: number;
    tierDurations: number[];
    tierPoints: number[];
    attemptsPerRound: number;
    warnings: RoomSettingsWarningCode[];
};

type WarningCase = {
    revealDuration: number;
    speedBonus: boolean;
    tierDurations: number[];
    tierPoints: number[];
    warnings: RoomSettingsWarningCode[];
};

type Derivations = {
    bounds: RoomSettingsBoundsPayload;
    speedBonusMaxPercent: Record<string, number>;
    cases: DerivationCase[];
    warningCases: WarningCase[];
};

const FIXTURE = JSON.parse(
    readFileSync(
        new URL('../../Fixtures/room/derivations.json', import.meta.url),
        'utf8',
    ),
) as Derivations;

const { bounds } = FIXTURE;
// `B_max(N)` de la prop `limits`, seul lu par `waiting_pays` (L50-10).
const limits = { speedBonusMaxPercent: FIXTURE.speedBonusMaxPercent };

describe('room-settings', () => {
    it('dérive comme le serveur chaque cas du jeu partagé', () => {
        expect(FIXTURE.cases.length).toBeGreaterThan(0);
        expect(FIXTURE.warningCases.length).toBeGreaterThan(0);

        for (const sample of FIXTURE.cases) {
            const label = `N = ${sample.n}, D = ${sample.d}, R = ${sample.r}`;

            expect(
                defaultTierDurations(bounds, sample.n, sample.d),
                label,
            ).toEqual(sample.tierDurations);
            expect(defaultTierPoints(bounds, sample.n), label).toEqual(
                sample.tierPoints,
            );
            expect(defaultAttemptsPerRound(bounds, sample.d), label).toBe(
                sample.attemptsPerRound,
            );
            expect(
                warnings(bounds, limits, {
                    revealDuration: sample.r,
                    tierDurations: sample.tierDurations,
                    tierPoints: sample.tierPoints,
                    // Les cas Simple partent des défauts : bonus actif.
                    speedBonus: true,
                }),
                label,
            ).toEqual(sample.warnings);

            // Une entrée que le serveur a acceptée ne viole aucune borne
            // croisée côté client, paliers dérivés compris.
            expect(
                crossBoundErrors(bounds, {
                    framesPerRound: sample.n,
                    roundDuration: sample.d,
                    tierDurations: sample.tierDurations,
                }),
                label,
            ).toEqual([]);
        }

        for (const sample of FIXTURE.warningCases) {
            const label = JSON.stringify(sample);

            expect(warnings(bounds, limits, sample), label).toEqual(
                sample.warnings,
            );
        }
    });

    it("remonte D au minimum du nouveau N et l'annonce", () => {
        const { min: fewest, max: most } = framesPerRoundBound(bounds);
        const lowest = minRoundDuration(bounds, fewest);
        const highest = minRoundDuration(bounds, most);
        const shortest = {
            tierDurations: defaultTierDurations(bounds, fewest, lowest),
        };

        // Le minimum effectif croît avec N : c'est ce qui rend le cas
        // atteignable (25 s à N = 5 aux bornes par défaut).
        expect(highest).toBeGreaterThan(lowest);

        // D sous le minimum du nouveau N : la borne croisée 1 le refuserait.
        expect(
            crossBoundErrors(bounds, {
                framesPerRound: most,
                roundDuration: lowest,
            }),
        ).toEqual([
            {
                field: 'roundDuration',
                code: 'round_duration',
                min: highest,
                max: bounds.byFramesPerRound[String(most)]?.roundDuration.max,
                frames: most,
            },
        ]);

        // Le client le remonte dans le même envoi, et l'annonce.
        expect(framesPerRoundChange(bounds, shortest, most)).toEqual({
            body: { framesPerRound: most, roundDuration: highest },
            announcement: {
                key: 'room.settings.roundDuration.raised',
                seconds: highest,
            },
        });

        // Le D remonté est accepté par le nouveau N, sans autre violation.
        expect(
            crossBoundErrors(bounds, {
                framesPerRound: most,
                roundDuration: highest,
                tierDurations: defaultTierDurations(bounds, most, highest),
            }),
        ).toEqual([]);

        // Chaque N plus grand dont le minimum dépasse D est remonté à son
        // propre minimum ; les autres ne le sont pas.
        for (const frames of framesPerRoundOptions(bounds)) {
            const minimum = minRoundDuration(bounds, frames);
            const change = framesPerRoundChange(bounds, shortest, frames);

            expect(change, `N = ${frames}`).toEqual(
                minimum > lowest
                    ? {
                          body: {
                              framesPerRound: frames,
                              roundDuration: minimum,
                          },
                          announcement: {
                              key: 'room.settings.roundDuration.raised',
                              seconds: minimum,
                          },
                      }
                    : { body: { framesPerRound: frames }, announcement: null },
            );
        }

        // D déjà au minimum du nouveau N, ou baisse de N : rien à remonter,
        // rien à annoncer — le serveur n'ajuste jamais D, le client non plus
        // sans raison.
        const atMinimum = {
            tierDurations: defaultTierDurations(bounds, most, highest),
        };

        expect(framesPerRoundChange(bounds, atMinimum, most)).toEqual({
            body: { framesPerRound: most },
            announcement: null,
        });
        expect(framesPerRoundChange(bounds, atMinimum, fewest)).toEqual({
            body: { framesPerRound: fewest },
            announcement: null,
        });
    });

    it('lève les avertissements aux seuils du serveur', () => {
        const { recommendedMinRevealDuration, longRoundWarningDuration } =
            bounds.warningThresholds;
        const frames = framesPerRoundBound(bounds).min;
        const points = defaultTierPoints(bounds, frames);
        // Une manche ordinaire : le minimum effectif, loin du seuil long.
        const ordinary = minRoundDuration(bounds, frames);
        const view = (revealDuration: number, duration: number) => ({
            revealDuration,
            tierDurations: defaultTierDurations(bounds, frames, duration),
            tierPoints: points,
            speedBonus: true,
        });

        // Révélation : sous le seuil recommandé seulement, jamais au seuil.
        expect(
            warnings(
                bounds,
                limits,
                view(recommendedMinRevealDuration - 1, ordinary),
            ),
        ).toEqual(['short_reveal']);
        expect(
            warnings(
                bounds,
                limits,
                view(recommendedMinRevealDuration, ordinary),
            ),
        ).toEqual([]);

        // Manche longue : au-delà du seuil seulement, jamais au seuil.
        expect(
            warnings(
                bounds,
                limits,
                view(recommendedMinRevealDuration, longRoundWarningDuration),
            ),
        ).toEqual([]);
        expect(
            warnings(
                bounds,
                limits,
                view(
                    recommendedMinRevealDuration,
                    longRoundWarningDuration + 1,
                ),
            ),
        ).toEqual(['long_round']);

        // Chaque seuil figure des deux côtés dans le jeu du serveur, et le
        // client rend le même verdict.
        const sides = (predicate: (sample: DerivationCase) => boolean) =>
            FIXTURE.cases.filter(predicate);

        expect(
            sides((sample) => sample.r === recommendedMinRevealDuration - 1),
        ).not.toEqual([]);
        expect(
            sides((sample) => sample.r === recommendedMinRevealDuration),
        ).not.toEqual([]);
        expect(
            sides((sample) => sample.d === longRoundWarningDuration),
        ).not.toEqual([]);
        expect(
            sides((sample) => sample.d === longRoundWarningDuration + 1),
        ).not.toEqual([]);

        for (const sample of FIXTURE.cases) {
            const codes = warnings(bounds, limits, {
                revealDuration: sample.r,
                tierDurations: sample.tierDurations,
                tierPoints: sample.tierPoints,
                speedBonus: true,
            });

            expect(codes.includes('short_reveal')).toBe(
                sample.r < recommendedMinRevealDuration,
            );
            expect(codes.includes('long_round')).toBe(
                sample.d > longRoundWarningDuration,
            );
        }

        // Barème : non strictement décroissant (égalité comprise) et
        // entièrement à zéro, cumulables — et les quatre ensemble, comme le
        // serveur les rend, dans son ordre.
        const verdicts = new Set(
            FIXTURE.warningCases.flatMap((sample) => sample.warnings),
        );

        expect([...verdicts].sort()).toEqual(
            [
                'all_tiers_zero',
                'long_round',
                'non_decreasing_points',
                'short_reveal',
                'waiting_pays',
            ].sort(),
        );
        expect(
            FIXTURE.warningCases.some(
                (sample) =>
                    sample.warnings.length === 4 &&
                    warnings(bounds, limits, sample).join() ===
                        sample.warnings.join(),
            ),
        ).toBe(true);
    });

    it('place les propositions du mode Normal à T_N, en secondes et en part de la manche', () => {
        for (const sample of FIXTURE.cases) {
            const { seconds, percent } = choicesAtPercent(sample);
            const opening = sample.tierDurations
                .slice(0, -1)
                .reduce((sum, duration) => sum + duration, 0);

            expect(seconds).toBe(opening);
            expect(percent).toBe(Math.round((100 * opening) / sample.d));
        }
    });

    it('lit le rapport de changements du flash, vide compris, et ignore ce qu’il ne sait pas nommer', () => {
        expect(settingsChangesFrom(null)).toBeNull();
        expect(settingsChangesFrom({})).toBeNull();
        expect(settingsChangesFrom({ toast: { message: 'x' } })).toBeNull();

        // Rapport vide : tableau PHP vide, donc `[]` en JSON.
        expect(settingsChangesFrom({ settingsChanges: [] })).toEqual({});

        expect(
            settingsChangesFrom({
                settingsChanges: {
                    tierDurations: 'equalized',
                    tierPoints: 'reset',
                    themeIds: 'pruned',
                    capacity: 'shrunk',
                },
            }),
        ).toEqual({ tierDurations: 'equalized', tierPoints: 'reset' });

        // Une ligne par champ, dans l'ordre reçu : le code du rapport, le
        // champ nommé par son libellé (`:attribute`).
        const t = (key: string, replacements?: Record<string, string>) =>
            replacements === undefined
                ? key
                : `${key}(${replacements.attribute})`;

        expect(
            settingsChangeLines(
                { tierDurations: 'equalized', capacity: 'clamped' },
                t,
            ),
        ).toEqual([
            'room.settings.change.equalized(room.settings.tierDurations.label)',
            'room.settings.change.clamped(room.settings.capacity.label)',
        ]);
        expect(settingsChangeLines({}, t)).toEqual([]);
    });

    it('en Avancé, poste les deux listes redimensionnées et annonce D remonté', () => {
        const { min: fewest, max: most } = framesPerRoundBound(bounds);
        const lowest = minRoundDuration(bounds, fewest);
        const view = {
            tierDurations: defaultTierDurations(bounds, fewest, lowest),
            tierPoints: defaultTierPoints(bounds, fewest),
        };
        const raised = advancedFramesPerRoundChange(bounds, view, most);

        expect(raised.body).toEqual({
            framesPerRound: most,
            tierDurations: defaultTierDurations(
                bounds,
                most,
                minRoundDuration(bounds, most),
            ),
            tierPoints: defaultTierPoints(bounds, most),
        });
        expect(raised.announcement).toEqual({
            key: 'room.settings.roundDuration.raised',
            seconds: minRoundDuration(bounds, most),
        });
        expect(raised.pointsReset).toBe(false);

        // Un barème personnalisé remplacé : annoncé d'avance, D gardé.
        const custom = {
            tierDurations: defaultTierDurations(
                bounds,
                most,
                minRoundDuration(bounds, most),
            ),
            tierPoints: defaultTierPoints(bounds, most).map(
                () => bounds.derivation.tierPointsUnit,
            ),
        };
        const lowered = advancedFramesPerRoundChange(bounds, custom, fewest);

        expect(lowered.announcement).toBeNull();
        expect(lowered.pointsReset).toBe(true);
        expect(lowered.body.tierDurations.length).toBe(fewest);
    });

    it("waitingPays suit le serveur sur chaque cas d'avertissement du jeu partagé", () => {
        for (const sample of FIXTURE.warningCases) {
            const percent =
                FIXTURE.speedBonusMaxPercent[String(sample.tierPoints.length)];

            if (percent === undefined) {
                throw new Error('B_max absent du jeu partagé.');
            }

            if (sample.warnings.includes('waiting_pays')) {
                expect(waitingPays(sample.tierPoints, percent)).toBe(true);
            }
        }

        for (const [n, percent] of Object.entries(
            FIXTURE.speedBonusMaxPercent,
        )) {
            expect(
                waitingPays(defaultTierPoints(bounds, Number(n)), percent),
            ).toBe(false);
        }
    });
});
