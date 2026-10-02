<?php

namespace App\Http\Requests\Room;

use App\Actions\Room\TakeSeat;
use App\Concerns\PlayerIdentityValidationRules;
use App\Enums\RoomStatus;
use App\Models\Room;
use App\Support\Identity\PlayerTokenManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Entrée dans un salon — `room.join`, `POST /r/{room}/join` (spec 50 § 7.2 ;
 * contrat C5, 40 § 2.1 étape 3 et § 5.8).
 *
 * Même intégration du trait {@see PlayerIdentityValidationRules} que la
 * création, à une exception près, qui appartient à cette requête (§ 5.8,
 * « Reprise ») : **quand le jeton courant tient déjà un siège dans ce salon**
 * (`seatIn()`, expulsé exclu), `nickname` n'est ni exigé ni
 * validé — la reprise ne revalide jamais un pseudo (I5.5), et une liste
 * noire enrichie depuis n'éjecte personne. Il en va de même d'un salon
 * archivé : la prise de siège le refuse avant tout champ, et le visiteur est
 * renvoyé sans erreur vers la page « salon expiré ».
 *
 * Ce n'est qu'une lecture préalable, sans verrou : {@see TakeSeat} relit le
 * siège du jeton et le statut sous le verrou du salon, et fait seule
 * autorité.
 *
 * **Aucun avatar** (D55 du 02/10) : la prise de siège l'attribue ; un champ
 * `avatar` envoyé est ignoré. Il se change ensuite au lobby
 * (`room.avatar.update`).
 */
class JoinRoomRequest extends FormRequest
{
    use PlayerIdentityValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if (! $this->requiresIdentity()) {
            return [];
        }

        return [
            'nickname' => $this->nicknameRules(),
        ];
    }

    /** Le pseudo validé, forme canonique ; `null` quand il n'est pas exigé. */
    public function nickname(): ?string
    {
        $nickname = $this->validated('nickname');

        return is_string($nickname) ? $nickname : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))]);
    }

    /**
     * Faux pour une reprise (le jeton tient un siège non expulsé de ce salon)
     * et pour un salon archivé ; vrai sinon.
     */
    private function requiresIdentity(): bool
    {
        $room = $this->route('room');

        if (! $room instanceof Room) {
            return true;
        }

        if ($room->status === RoomStatus::Archived) {
            return false;
        }

        return app(PlayerTokenManager::class)->seatIn($this, $room) === null;
    }
}
