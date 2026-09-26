import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vite-plus/test';
import { isWellFormedRoomCode, normalizeRoomCode } from '@/lib/game/room-code';

/*
 * Parité du miroir client avec `App\Support\Room\RoomCode` (spec 50 § 6.3).
 *
 * Le jeu `tests/Fixtures/room/room-codes.json` porte des verdicts écrits à la
 * main ; `RoomCodeTest` (Pest) les exige de `RoomCode`, ce test les exige du
 * miroir. Les deux côtés rendant la même forme et le même verdict sur les
 * mêmes saisies, le champ de code de l'accueil refuse sans requête ce que la
 * liaison de route refuserait, et lui seul. Ce test lit le jeu, il ne
 * l'écrit jamais.
 */

type RoomCodeCase = {
    input: string;
    normalized: string;
    wellFormed: boolean;
};

const CASES = JSON.parse(
    readFileSync(
        new URL('../../Fixtures/room/room-codes.json', import.meta.url),
        'utf8',
    ),
) as RoomCodeCase[];

describe('room-code', () => {
    it('normalise et reconnaît chaque cas du jeu partagé comme le serveur', () => {
        expect(CASES.some((sample) => sample.wellFormed)).toBe(true);
        expect(CASES.some((sample) => !sample.wellFormed)).toBe(true);

        for (const sample of CASES) {
            const label = JSON.stringify(sample.input);

            expect(normalizeRoomCode(sample.input), label).toBe(
                sample.normalized,
            );
            expect(isWellFormedRoomCode(sample.input), label).toBe(
                sample.wellFormed,
            );

            // La forme canonique est un point fixe, au même verdict.
            expect(normalizeRoomCode(sample.normalized), label).toBe(
                sample.normalized,
            );
            expect(isWellFormedRoomCode(sample.normalized), label).toBe(
                sample.wellFormed,
            );
        }
    });
});
