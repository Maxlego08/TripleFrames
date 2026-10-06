import type { AvatarPickerOption } from '@/components/game/avatar-picker';
import {
    AVATAR_PRESET_LABEL_KEYS,
    isAvatarPresetKey,
} from '@/lib/game/avatar-keys';
import { ACCOUNT_AVATAR_CHOICE } from '@/types/player';
import type { SeatView } from '@/types/game-wire';
import type { AvatarPresetOption, SeatAvatarChoice } from '@/types/player';
import type { TranslationKey } from '@/types/translations';

/**
 * Prop `avatars` de la page `game/lobby` (spec 50 § 8.1, D55 du 02/10),
 * miroir de `App\Support\Room\LobbyAvatars::of()` : réponse au seul
 * demandeur, rechargeable seule (`only: ['avatars']`).
 */
export type LobbyAvatars = {
    /** Le catalogue des prédéfinis, en données (règle 4). */
    options: AvatarPresetOption[];
    /** Clés tenues par les AUTRES sièges tenus du salon, replis compris. */
    taken: string[];
    /**
     * Le choix effectif du siège : `account` quand il affiche une image de
     * compte visible, sinon sa clé de prédéfini.
     */
    current: string;
    /** « Mon avatar », ou `null` : invité, ou compte sans image visible. */
    account: { url: string } | null;
};

/**
 * Les options du sélecteur, traduites par `label` : seules les clés du
 * catalogue client passent, et une clé tenue par un autre siège est marquée
 * prise — le sélecteur la désactive, le serveur la refuserait.
 */
export function lobbyAvatarOptions(
    avatars: Pick<LobbyAvatars, 'options' | 'taken'>,
    label: (key: TranslationKey) => string,
): AvatarPickerOption[] {
    const taken = new Set(avatars.taken);

    return avatars.options.flatMap((option) =>
        isAvatarPresetKey(option.key)
            ? [
                  {
                      key: option.key,
                      url: option.url,
                      label: label(AVATAR_PRESET_LABEL_KEYS[option.key]),
                      taken: taken.has(option.key),
                  },
              ]
            : [],
    );
}

/**
 * La valeur cochée du sélecteur : la clé du siège, ou `account` quand
 * l'image du compte est encore offerte. `null` quand le choix courant n'a
 * aucune tuile (clé inconnue du client, image de compte devenue invisible) :
 * rien n'est coché, le joueur choisit.
 */
export function lobbyAvatarValue(
    avatars: Pick<LobbyAvatars, 'current' | 'account'>,
): SeatAvatarChoice | null {
    if (isAvatarPresetKey(avatars.current)) {
        return avatars.current;
    }

    if (avatars.current === ACCOUNT_AVATAR_CHOICE && avatars.account !== null) {
        return ACCOUNT_AVATAR_CHOICE;
    }

    return null;
}

/**
 * Empreinte des avatars des AUTRES sièges, telle que le salon les diffuse
 * (`seat.joined`, `seat.updated`) : nature, image, présence et expulsion.
 * Elle change quand une clé peut être prise ou libérée ; le sélecteur ouvert
 * relit alors la prop `avatars`, sans quoi sa liste des clés prises vieillit
 * (le prédéfini de repli d'un siège à image n'est pas diffusé : seule la
 * prop le connaît). Indépendante de l'ordre des sièges.
 */
export function otherSeatsAvatarSignature(
    seats: readonly Pick<
        SeatView,
        'publicId' | 'connection' | 'kicked' | 'avatar'
    >[],
    selfPublicId: string,
): string {
    return seats
        .filter((seat) => seat.publicId !== selfPublicId)
        .map((seat) =>
            [
                seat.publicId,
                seat.connection,
                seat.kicked ? '1' : '0',
                seat.avatar.kind ?? '',
                seat.avatar.url ?? '',
            ].join('\u001f'),
        )
        .sort()
        .join('\u001e');
}
