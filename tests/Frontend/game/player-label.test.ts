import { describe, expect, it } from 'vite-plus/test';
import { playerLabel, seatOrdinals } from '@/lib/game/player-label';
import type { PlayerIdentity } from '@/types/player';

/*
 * Libellé d'un pseudo masqué et ordinal (spec 40 § 13.3, I5.10 ; D66 du
 * 07/10, L40-12). Module pur, aucun DOM : la traduction est un double qui
 * rend la clé et ses remplacements.
 */

const t = (key: string, replacements?: Record<string, string | number>) =>
    replacements === undefined
        ? key
        : `${key}(${Object.entries(replacements)
              .map(([name, value]) => `${name}=${value}`)
              .join(',')})`;

function identity(
    publicId: string,
    overrides: Partial<PlayerIdentity> = {},
): PlayerIdentity {
    return {
        publicId,
        nickname: `Pseudo ${publicId}`,
        masked: false,
        avatar: { kind: 'preset', url: null, altKey: 'x', initials: 'PS' },
        ...overrides,
    };
}

describe('player-label', () => {
    it('numérote les sièges dans l’ordre reçu, sans doublon', () => {
        const ordinals = seatOrdinals([
            identity('A'),
            identity('B'),
            identity('A'),
            identity('C'),
        ]);

        expect([...ordinals.entries()]).toEqual([
            ['A', 1],
            ['B', 2],
            ['C', 3],
        ]);
    });

    it('rend le pseudo d’un siège visible', () => {
        expect(playerLabel(identity('A'), new Map(), t)).toBe('Pseudo A');
    });

    it('rend « Joueur n » pour un pseudo masqué, jamais les initiales', () => {
        const seats = [identity('A'), identity('B'), identity('C')];
        const masked = identity('B', {
            nickname: null,
            masked: true,
            avatar: { kind: 'preset', url: null, altKey: 'x', initials: '?' },
        });

        expect(playerLabel(masked, seatOrdinals(seats), t)).toBe(
            'common.player.masked(ordinal=2)',
        );
    });

    it('garde le rang du salon quand le classement se réordonne', () => {
        const ordinals = seatOrdinals([identity('A'), identity('B')]);
        const masked = identity('A', { nickname: null, masked: true });
        const standings = [identity('B'), masked];

        expect(standings.map((line) => playerLabel(line, ordinals, t))).toEqual(
            ['Pseudo B', 'common.player.masked(ordinal=1)'],
        );
    });

    it('rend les initiales d’un pseudo effacé, non masqué', () => {
        const erased = identity('A', { nickname: null, masked: false });

        expect(playerLabel(erased, new Map(), t)).toBe('PS');
    });

    it('donne à un siège masqué inconnu le rang qui suit le dernier', () => {
        const ordinals = seatOrdinals([identity('A'), identity('B')]);
        const stranger = identity('Z', { nickname: null, masked: true });

        expect(playerLabel(stranger, ordinals, t)).toBe(
            'common.player.masked(ordinal=3)',
        );
    });
});
