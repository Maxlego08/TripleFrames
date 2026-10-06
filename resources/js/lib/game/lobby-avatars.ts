import type { AvatarPickerOption } from '@/components/game/avatar-picker';
import {
    AVATAR_PRESET_LABEL_KEYS,
    isAvatarPresetKey,
} from '@/lib/game/avatar-keys';
import { ACCOUNT_AVATAR_CHOICE } from '@/types/player';
import type { SeatView } from '@/types/game-wire';
import type {
    AvatarData,
    AvatarPresetOption,
    SeatAvatarChoice,
} from '@/types/player';
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
 * L'avatar voisin du choix courant, pour les flèches ‹ › du siège dans la
 * salle d'attente : `direction` 1 (suivant) ou -1 (précédent), en boucle,
 * dans l'ordre « Mon avatar » (s'il est offert) puis le catalogue, en
 * sautant les clés tenues par un autre siège. `null` quand aucun autre
 * choix n'est libre. Le serveur reste juge : une clé prise entre-temps est
 * refusée (`room.lobby.avatar_taken`).
 */
export function cycleLobbyAvatar(
    avatars: Pick<LobbyAvatars, 'options' | 'taken' | 'current' | 'account'>,
    direction: 1 | -1,
): SeatAvatarChoice | null {
    const taken = new Set(avatars.taken);
    const account: SeatAvatarChoice[] =
        avatars.account === null ? [] : [ACCOUNT_AVATAR_CHOICE];
    const presets: SeatAvatarChoice[] = avatars.options
        .filter(
            (option) => isAvatarPresetKey(option.key) && !taken.has(option.key),
        )
        .map((option) => option.key);
    const choices = [...account, ...presets];
    const current = lobbyAvatarValue(avatars);
    const index = current === null ? -1 : choices.indexOf(current);

    if (choices.length === 0 || (index !== -1 && choices.length === 1)) {
        return null;
    }

    if (index === -1) {
        return direction === 1 ? choices[0] : choices[choices.length - 1];
    }

    return choices[(index + direction + choices.length) % choices.length];
}

/**
 * L'avatar à afficher pour un choix du siège, dérivé de la prop `avatars`
 * (D55 du 02/10, amendé le 06/10) : le choix optimiste s'affiche avant la
 * réponse du serveur, et sans dépendre de `seat.updated`. `fallback` (l'avatar
 * que le salon voit) garde les initiales et la clé d'alternative ; il est
 * rendu tel quel quand le choix n'a pas d'image connue.
 */
export function lobbyAvatarData(
    choice: SeatAvatarChoice | null,
    avatars: Pick<LobbyAvatars, 'options' | 'account'>,
    fallback: AvatarData,
): AvatarData {
    if (choice === ACCOUNT_AVATAR_CHOICE && avatars.account !== null) {
        return { ...fallback, kind: 'upload', url: avatars.account.url };
    }

    const option = avatars.options.find(
        (candidate) => candidate.key === choice,
    );

    return option === undefined
        ? fallback
        : { ...fallback, kind: 'preset', url: option.url };
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
