<?php

namespace App\Http\Requests\Room;

use App\Concerns\PlayerIdentityValidationRules;
use App\Rules\ValidNickname;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un salon — `room.store`, `POST /r` (spec 50 § 6.2 ; contrat C5,
 * 40 § 5.8).
 *
 * Pseudo et avatar de l'hôte, par le trait {@see PlayerIdentityValidationRules} :
 * `prepareForValidation()` remplace le pseudo par sa forme canonique, puis
 * `nicknameRules()` et `avatarPresetRules()`. La forme validée est celle
 * qu'écrit la prise de siège : aucune seconde normalisation. L'unicité du
 * pseudo (`taken`) n'est pas ici : elle se lit sous le verrou du salon, par
 * la prise de siège, jamais par {@see ValidNickname}.
 *
 * Un envoi refusé à la validation n'atteint pas l'action : il ne frappe
 * aucun `player_token` (40 § 2.1, étape 3).
 */
class StoreRoomRequest extends FormRequest
{
    use PlayerIdentityValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nickname' => $this->nicknameRules(),
            'avatar' => $this->seatAvatarRules($this->authenticatedUser()),
        ];
    }

    /** Le pseudo validé, forme canonique (40 § 5.2). */
    public function nickname(): string
    {
        return (string) $this->validated('nickname');
    }

    /** La clé d'avatar prédéfini validée, dans le catalogue. */
    public function avatarPreset(): string
    {
        return (string) $this->validated('avatar');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))]);
    }
}
