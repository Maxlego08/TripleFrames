import type { Replacements } from '@/lib/i18n';
import type { PlayerIdentity } from '@/types/player';
import type { TranslationKey } from '@/types/translations';

/**
 * Le nom affiché d'un siège (spec 40 § 13.3, I5.10 ; 90 § 11, point 3 ;
 * D66 du 07/10).
 *
 * - **Pseudo masqué** (`masked: true`) : `common.player.masked`
 *   (« Joueur :ordinal »), l'ordinal étant le rang du siège dans la liste
 *   des sièges du salon, dans l'ordre reçu du serveur — l'ordre d'arrivée
 *   (`joined_at`, 50 § 8.1) —, et non dans le classement : il ne bouge pas
 *   quand le classement se réordonne. **Jamais les initiales de l'avatar**
 *   comme substitut : elles valent le caractère neutre pour un siège masqué.
 * - **Pseudo effacé par la rétention ou l'archivage** (`masked: false`,
 *   `nickname: null`) : le rendu d'avant, les initiales.
 * - Sinon, le pseudo.
 *
 * Module pur : ni React, ni dictionnaire global ; la fonction de traduction
 * est passée par l'appelant.
 */

/** Rang (à partir de 1) de chaque siège, par `publicId`. */
export type SeatOrdinals = ReadonlyMap<string, number>;

/** Les rangs des sièges, dans l'ordre de la liste reçue. */
export function seatOrdinals(
    seats: readonly Pick<PlayerIdentity, 'publicId'>[],
): SeatOrdinals {
    const ordinals = new Map<string, number>();

    for (const seat of seats) {
        if (!ordinals.has(seat.publicId)) {
            ordinals.set(seat.publicId, ordinals.size + 1);
        }
    }

    return ordinals;
}

type Translate = (key: TranslationKey, replacements?: Replacements) => string;

/**
 * Le nom affiché d'une identité. Un siège masqué absent de la liste (cas qui
 * ne se produit pas : toute identité masquée vient du salon) prend le rang
 * qui suit le dernier, jamais un rang déjà donné.
 */
export function playerLabel(
    identity: Pick<
        PlayerIdentity,
        'publicId' | 'nickname' | 'masked' | 'avatar'
    >,
    ordinals: SeatOrdinals,
    t: Translate,
): string {
    if (identity.masked) {
        const ordinal = ordinals.get(identity.publicId) ?? ordinals.size + 1;

        return t('common.player.masked', { ordinal });
    }

    return identity.nickname ?? identity.avatar.initials;
}
