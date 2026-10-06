import { describe, expect, it } from 'vite-plus/test';
import {
    lobbyAvatarOptions,
    lobbyAvatarValue,
    otherSeatsAvatarSignature,
} from '@/lib/game/lobby-avatars';
import type { LobbyAvatars } from '@/lib/game/lobby-avatars';
import type { SeatView } from '@/types/game-wire';

/*
 * Sélecteur d'avatar du lobby (D55 du 02/10 ; spec 50 § 8.1) : la prop
 * `avatars` de `game/lobby` lue en options et en valeur cochée. Module pur,
 * aucun DOM (C18 § 2.4) ; la forme de la prop est prouvée côté serveur par
 * `LobbyPageTest` et `SeatAvatarChangeTest`.
 */

const options: LobbyAvatars['options'] = [
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
    // Une clé que le serveur enverrait sans que le client la connaisse
    // (catalogue en avance d'un déploiement) : le type l'exclut, la garde
    // d'exécution aussi.
    JSON.parse(
        '{"key":"preset-99","url":"/avatars/preset-99.webp","labelKey":"common.avatar.preset.preset-99"}',
    ) as LobbyAvatars['options'][number],
];

describe('lobbyAvatarOptions', () => {
    it('marque prises les clés des autres sièges et traduit chaque libellé', () => {
        const result = lobbyAvatarOptions(
            { options, taken: ['preset-02'] },
            (key) => `«${key}»`,
        );

        expect(result).toEqual([
            {
                key: 'preset-01',
                url: '/avatars/preset-01.webp',
                label: '«common.avatar.preset.preset-01»',
                taken: false,
            },
            {
                key: 'preset-02',
                url: '/avatars/preset-02.webp',
                label: '«common.avatar.preset.preset-02»',
                taken: true,
            },
        ]);
    });

    it('écarte une clé inconnue du catalogue client', () => {
        const keys = lobbyAvatarOptions(
            { options, taken: [] },
            (key) => key,
        ).map((option) => option.key);

        expect(keys).not.toContain('preset-99');
    });
});

describe('lobbyAvatarValue', () => {
    it('coche la clé de prédéfini du siège', () => {
        expect(lobbyAvatarValue({ current: 'preset-02', account: null })).toBe(
            'preset-02',
        );
    });

    it('coche « Mon avatar » tant que l’image du compte est offerte', () => {
        expect(
            lobbyAvatarValue({
                current: 'account',
                account: { url: '/a/me.webp' },
            }),
        ).toBe('account');
    });

    it('ne coche rien quand le choix courant n’a pas de tuile', () => {
        expect(lobbyAvatarValue({ current: 'account', account: null })).toBe(
            null,
        );
        expect(lobbyAvatarValue({ current: '', account: null })).toBe(null);
        expect(lobbyAvatarValue({ current: 'preset-99', account: null })).toBe(
            null,
        );
    });
});

describe('otherSeatsAvatarSignature', () => {
    const seat = (
        publicId: string,
        avatar: Partial<SeatView['avatar']> = {},
        rest: Partial<Pick<SeatView, 'connection' | 'kicked'>> = {},
    ): Pick<SeatView, 'publicId' | 'connection' | 'kicked' | 'avatar'> => ({
        publicId,
        connection: 'connected',
        kicked: false,
        avatar: {
            kind: 'preset',
            url: `/avatars/${publicId}.webp`,
            altKey: 'common.avatar.alt.preset',
            initials: 'AB',
            ...avatar,
        },
        ...rest,
    });

    it("ignore son propre siège et l'ordre des sièges", () => {
        const seats = [seat('self'), seat('alice'), seat('bob')];
        const base = otherSeatsAvatarSignature(seats, 'self');

        expect(
            otherSeatsAvatarSignature(
                [
                    seat('bob'),
                    seat('self', { url: '/avatars/x.webp' }),
                    seat('alice'),
                ],
                'self',
            ),
        ).toBe(base);
    });

    it("change quand un autre siège change d'avatar, part ou est expulsé", () => {
        const base = otherSeatsAvatarSignature(
            [seat('self'), seat('alice')],
            'self',
        );

        for (const changed of [
            seat('alice', { url: '/avatars/fox.webp' }),
            seat('alice', { kind: 'upload' }),
            seat('alice', {}, { connection: 'left' }),
            seat('alice', {}, { kicked: true }),
        ]) {
            expect(
                otherSeatsAvatarSignature([seat('self'), changed], 'self'),
            ).not.toBe(base);
        }
    });
});
