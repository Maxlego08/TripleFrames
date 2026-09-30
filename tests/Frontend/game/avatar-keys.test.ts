import { describe, expect, it } from 'vite-plus/test';
import {
    AVATAR_PRESET_LABEL_KEYS,
    avatarAltKey,
    isAvatarPresetKey,
} from '@/lib/game/avatar-keys';

/*
 * Clés de traduction des avatars (spec 40 § 7.4, contrat C5).
 *
 * La couverture du catalogue serveur — même union de clés, chaque clé nommée
 * par SON libellé — est prouvée par `AvatarPresetTest` sur la source ; ici,
 * le comportement des deux fonctions, module pur, aucun DOM (C18 § 2.4).
 */

describe('avatar-keys', () => {
    it('reconnaît chaque clé du catalogue client, et rien d’autre', () => {
        const keys = Object.keys(AVATAR_PRESET_LABEL_KEYS);

        expect(keys.length).toBeGreaterThan(0);

        for (const key of keys) {
            expect(isAvatarPresetKey(key)).toBe(true);
        }

        for (const key of [
            '',
            'preset-00',
            'preset-1',
            'PRESET-01',
            ' preset-01',
            'preset-01.webp',
            '/avatars/preset-01.webp',
            'toString',
            'constructor',
            '__proto__',
            'hasOwnProperty',
        ]) {
            expect(isAvatarPresetKey(key)).toBe(false);
        }
    });

    it('rend la clé d’alt reçue si elle est connue, sinon celle des initiales', () => {
        expect(avatarAltKey('common.avatar.alt.preset')).toBe(
            'common.avatar.alt.preset',
        );
        expect(avatarAltKey('common.avatar.alt.provider')).toBe(
            'common.avatar.alt.provider',
        );
        expect(avatarAltKey('common.avatar.alt.initials')).toBe(
            'common.avatar.alt.initials',
        );

        for (const unknown of [
            '',
            'avatar.alt.preset',
            'common.avatar.alt',
            'common.avatar.preset.preset-01',
            'common.avatar.alt.PRESET',
        ]) {
            expect(avatarAltKey(unknown)).toBe('common.avatar.alt.initials');
        }
    });
});
