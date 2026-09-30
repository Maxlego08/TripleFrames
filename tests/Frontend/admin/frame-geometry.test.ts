import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import {
    CROP_VIOLATIONS,
    cropViolation,
    defaultCrop,
    masterHeightFor,
} from '@/lib/frame-geometry';
import type { CropLimits, CropRect, CropViolation } from '@/lib/frame-geometry';

/*
 * Parité du miroir client avec `App\Support\Frames\FrameGeometry` (contrat
 * C9, spec 20 § 5.1 et § 5.2).
 *
 * Le jeu `tests/Fixtures/Frames/crop-cases.json` porte des verdicts écrits à
 * la main ; `CropRectangleTest` (Pest) les exige de `FrameGeometry`, ce test
 * les exige du miroir. Les deux côtés rendant les mêmes verdicts sur les
 * mêmes cadres, le miroir refuse ce que refuse le serveur, et lui seul.
 */

type ViolationCase = {
    rule: string;
    label: string;
    masterHeight: number;
    limits: CropLimits;
    crop: CropRect;
    expected: CropViolation | null;
};

type DefaultCase = {
    label: string;
    masterHeight: number;
    limits: CropLimits;
    expected: CropRect | null;
};

type MasterHeightCase = {
    label: string;
    source: { width: number; height: number };
    expected: number;
};

type CropCases = {
    violations: ViolationCase[];
    defaults: DefaultCase[];
    masterHeights: MasterHeightCase[];
};

const CASES = JSON.parse(
    readFileSync(
        new URL('../../Fixtures/Frames/crop-cases.json', import.meta.url),
        'utf8',
    ),
) as CropCases;

describe('frame-geometry', () => {
    it('le miroir client refuse les mêmes cadres que FrameGeometry', () => {
        expect(CASES.violations.length).toBeGreaterThan(0);

        for (const verdict of CASES.violations) {
            if (verdict.expected !== null) {
                expect(CROP_VIOLATIONS, verdict.label).toContain(
                    verdict.expected,
                );
            }

            expect(
                cropViolation(
                    verdict.crop,
                    verdict.masterHeight,
                    verdict.limits,
                ),
                `${verdict.rule} · ${verdict.label}`,
            ).toBe(verdict.expected);
        }

        // Chaque cause de refus est exercée au moins une fois.
        expect(
            new Set(CASES.violations.map((verdict) => verdict.expected)),
        ).toEqual(new Set([...CROP_VIOLATIONS, null]));

        for (const expected of CASES.defaults) {
            const crop = defaultCrop(expected.masterHeight, expected.limits);

            expect(crop, expected.label).toEqual(expected.expected);

            if (crop !== null) {
                expect(
                    cropViolation(crop, expected.masterHeight, expected.limits),
                    expected.label,
                ).toBeNull();
            }
        }

        for (const expected of CASES.masterHeights) {
            expect(
                masterHeightFor(expected.source.width, expected.source.height),
                expected.label,
            ).toBe(expected.expected);
        }
    });
});
