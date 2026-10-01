import type { AvatarPresetKey } from '@/types/player';
import type { TranslationKey } from '@/types/translations';

/**
 * Clés de traduction des avatars (spec 40 § 6.7 et § 7.4, contrat C5).
 *
 * Ces tables existent parce que `t()` est **typé** : une clé composée à
 * l'exécution (`common.avatar.preset.` suivi de la clé) ne compile pas
 * (C15 § 2.7), et c'est la garantie recherchée — ajouter une clé à
 * `AvatarPresetKey` sans sa ligne ici casse `tsc`, au lieu d'afficher une clé
 * brute au joueur.
 *
 * Le libellé d'un prédéfini (« Hibou ») ne sert que de nom accessible d'une
 * option du sélecteur (I5.9) ; partout ailleurs, l'image se décrit par sa clé
 * d'`alt`, ou se tait à côté d'un pseudo.
 */
export const AVATAR_PRESET_LABEL_KEYS: Record<AvatarPresetKey, TranslationKey> =
    {
        'preset-01': 'common.avatar.preset.preset-01',
        'preset-02': 'common.avatar.preset.preset-02',
        'preset-03': 'common.avatar.preset.preset-03',
        'preset-04': 'common.avatar.preset.preset-04',
        'preset-05': 'common.avatar.preset.preset-05',
        'preset-06': 'common.avatar.preset.preset-06',
        'preset-07': 'common.avatar.preset.preset-07',
        'preset-08': 'common.avatar.preset.preset-08',
        'preset-09': 'common.avatar.preset.preset-09',
        'preset-10': 'common.avatar.preset.preset-10',
        'preset-11': 'common.avatar.preset.preset-11',
        'preset-12': 'common.avatar.preset.preset-12',
        'preset-13': 'common.avatar.preset.preset-13',
        'preset-14': 'common.avatar.preset.preset-14',
        'preset-15': 'common.avatar.preset.preset-15',
        'preset-16': 'common.avatar.preset.preset-16',
        'preset-17': 'common.avatar.preset.preset-17',
        'preset-18': 'common.avatar.preset.preset-18',
        'preset-19': 'common.avatar.preset.preset-19',
        'preset-20': 'common.avatar.preset.preset-20',
        'preset-21': 'common.avatar.preset.preset-21',
        'preset-22': 'common.avatar.preset.preset-22',
        'preset-23': 'common.avatar.preset.preset-23',
        'preset-24': 'common.avatar.preset.preset-24',
    };

/**
 * Garde de type d'une clé reçue du serveur ou d'un contrôle (valeur d'un
 * `RadioGroup`, prop de page) : vraie pour une clé du catalogue client, à
 * l'octet près — jamais pour une propriété héritée d'`Object`.
 */
export function isAvatarPresetKey(key: string): key is AvatarPresetKey {
    return Object.hasOwn(AVATAR_PRESET_LABEL_KEYS, key);
}

/**
 * Table close des quatre clés d'`alt` d'`AvatarRef` (`ALT_KEY_PRESET`,
 * `ALT_KEY_PROVIDER`, `ALT_KEY_UPLOAD`, `ALT_KEY_INITIALS`), domaine `common`.
 */
const AVATAR_ALT_KEYS = [
    'common.avatar.alt.preset',
    'common.avatar.alt.provider',
    'common.avatar.alt.upload',
    'common.avatar.alt.initials',
] as const satisfies readonly TranslationKey[];

/** Repli d'une clé d'`alt` inconnue : l'avatar se décrit par ses initiales. */
const AVATAR_ALT_FALLBACK_KEY: TranslationKey = 'common.avatar.alt.initials';

/**
 * Clé de traduction typée de l'`alt` d'un avatar, depuis `AvatarData.altKey`
 * (chaîne du serveur) : la clé elle-même si elle appartient à la table close,
 * sinon la clé des initiales. L'appelant la traduit, sauf à côté d'un pseudo
 * affiché, où l'image est décorative (`alt=""`, I5.9).
 */
export function avatarAltKey(altKey: string): TranslationKey {
    return (
        AVATAR_ALT_KEYS.find((key) => key === altKey) ?? AVATAR_ALT_FALLBACK_KEY
    );
}
