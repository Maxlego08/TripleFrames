<?php

namespace App\Support\Room;

use App\Avatars\AvatarPresetCatalog;
use App\Avatars\SeatAvatar;
use App\Enums\AvatarKind;
use App\Models\Player;
use App\Models\Room;
use App\Models\User;

/**
 * La prop `avatars` de la page `game/lobby` — le sélecteur d'avatar du siège
 * (spec 50 § 8.1, spec 40 § 11.4 ; D55 du 02/10).
 *
 * Réponse au seul demandeur, jamais diffusée, rechargeable seule
 * (`only: ['avatars']`) :
 *
 * - `options` : le catalogue des prédéfinis, en données (règle 4) ;
 * - `taken` : les clés tenues par les AUTRES sièges tenus du salon,
 *   prédéfinis de repli compris ({@see TakenAvatars}) — des clés, jamais un
 *   siège ni un pseudo ;
 * - `current` : le choix effectif du siège — `account` quand il affiche une
 *   image de compte visible, sinon sa clé de prédéfini ; une image masquée
 *   redescend donc à son repli, comme {@see Player::avatarRef()} ;
 * - `account` : « Mon avatar » (`{ url }`) pour un compte qui porte une image
 *   visible, sinon `null`.
 *
 * Le changement n'est accepté qu'au lobby ; la prop, elle, est servie dans
 * tout statut, le client grisant le sélecteur hors du lobby.
 */
final class LobbyAvatars
{
    /**
     * @return array{options: list<array{key: string, url: string, labelKey: string}>, taken: list<string>, current: string, account: array{url: string}|null}
     */
    public static function of(Room $room, Player $seat, ?User $user): array
    {
        return [
            'options' => AvatarPresetCatalog::options(),
            'taken' => TakenAvatars::of($room, $seat),
            'current' => self::current($seat),
            // Seul le compte du siège ouvre « Mon avatar » (player.user_id).
            'account' => SeatAvatar::accountOption(SeatAvatar::seatAccount($seat, $user)),
        ];
    }

    /** Le choix effectif du siège, au sens du sélecteur. */
    public static function current(Player $seat): string
    {
        $kind = $seat->avatarRef()->kind;

        if ($kind === AvatarKind::Upload || $kind === AvatarKind::Provider) {
            return SeatAvatar::ACCOUNT;
        }

        return $seat->avatar_preset ?? '';
    }
}
