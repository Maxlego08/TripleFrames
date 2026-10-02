<?php

namespace App\Http\Requests\Room;

use App\Actions\Room\ChangeSeatAvatar;
use App\Avatars\SeatAvatar;
use App\Concerns\PlayerIdentityValidationRules;
use App\Http\Middleware\EnsureActiveSeat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Changement d'avatar d'un siège au lobby — `room.avatar.update`,
 * `POST /r/{room}/avatar`, corps `{ avatar }` (spec 50 § 8.1, spec 40 § 11.4 ;
 * D55 du 02/10).
 *
 * `avatar` : une clé du catalogue, ou {@see SeatAvatar::ACCOUNT} pour un
 * compte qui porte une image visible — jamais pour un invité ni pour une
 * image masquée (`seatAvatarRules()`), ni pour un compte connecté qui n'est
 * pas celui du siège (`player.user_id`, {@see SeatAvatar::seatAccount()}).
 * L'unicité dans le salon n'est pas
 * ici : elle se lit sous le verrou du salon, par {@see ChangeSeatAvatar}.
 */
class ChangeSeatAvatarRequest extends FormRequest
{
    use PlayerIdentityValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $seat = EnsureActiveSeat::seat($this);

        return [
            'avatar' => $this->seatAvatarRules(
                $seat === null ? null : SeatAvatar::seatAccount($seat, $this->authenticatedUser()),
            ),
        ];
    }

    /** Le choix validé : clé du catalogue, ou `account`. */
    public function avatarChoice(): string
    {
        return (string) $this->validated('avatar');
    }
}
