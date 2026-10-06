import { describe, expect, it } from 'vite-plus/test';
import { cycleLobbyAvatar, lobbyAvatarData } from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';

/*
 * Les flèches ‹ › du siège dans la salle d'attente (D55 du 02/10, amendé le
 * 06/10) : l'avatar libre voisin, en boucle, « Mon avatar » d'abord s'il est
 * offert, les clés des autres sièges sautées.
 */

function avatars(overrides: Partial<LobbyAvatars> = {}): LobbyAvatars {
    return {
        options: (
            ['preset-01', 'preset-02', 'preset-03', 'preset-04'] as const
        ).map((key) => ({
            key,
            url: `/avatars/${key}.svg`,
            labelKey: `common.avatar.preset.${key}`,
        })),
        taken: [],
        current: 'preset-01',
        account: null,
        ...overrides,
    };
}

describe('cycleLobbyAvatar', () => {
    it('passe au suivant et au précédent, en boucle', () => {
        expect(cycleLobbyAvatar(avatars(), 1)).toBe('preset-02');
        expect(cycleLobbyAvatar(avatars(), -1)).toBe('preset-04');
        expect(cycleLobbyAvatar(avatars({ current: 'preset-04' }), 1)).toBe(
            'preset-01',
        );
    });

    it('saute les avatars tenus par un autre siège', () => {
        expect(
            cycleLobbyAvatar(avatars({ taken: ['preset-02', 'preset-03'] }), 1),
        ).toBe('preset-04');
    });

    it('propose « Mon avatar » en tête quand le compte en offre un', () => {
        const withAccount = avatars({ account: { url: '/avatar/me' } });

        expect(cycleLobbyAvatar(withAccount, -1)).toBe('account');
        expect(
            cycleLobbyAvatar({ ...withAccount, current: 'account' }, 1),
        ).toBe('preset-01');
    });

    it('ne propose rien quand aucun autre avatar n’est libre', () => {
        expect(
            cycleLobbyAvatar(
                avatars({ taken: ['preset-02', 'preset-03', 'preset-04'] }),
                1,
            ),
        ).toBeNull();
    });
});

describe('lobbyAvatarData', () => {
    const fallback = {
        kind: 'preset' as const,
        url: '/avatars/preset-01.svg',
        altKey: 'preset-01',
        initials: 'ZO',
    };

    it('affiche l’image du choix, initiales et alternative gardées', () => {
        expect(lobbyAvatarData('preset-03', avatars(), fallback)).toEqual({
            ...fallback,
            url: '/avatars/preset-03.svg',
        });
        expect(
            lobbyAvatarData(
                'account',
                avatars({ account: { url: '/avatar/me' } }),
                fallback,
            ),
        ).toEqual({ ...fallback, kind: 'upload', url: '/avatar/me' });
    });

    it('garde l’avatar du salon pour un choix sans image connue', () => {
        expect(lobbyAvatarData(null, avatars(), fallback)).toBe(fallback);
        expect(lobbyAvatarData('account', avatars(), fallback)).toBe(fallback);
    });
});
