<?php

namespace Tests\Support\Identity;

use App\Concerns\PlayerIdentityValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Une FormRequest de siège réduite à l'intégration du § 5.8 (spec 40, contrat
 * C5), telle que `50` et `60` l'écriront : le trait, la préparation du pseudo
 * dans `prepareForValidation()`, puis les deux listes de règles.
 *
 * Tant que les FormRequest de `room.join`, `room.store` et `solo.store`
 * n'existent pas, c'est par elle que les tests de pseudo traversent la vraie
 * pile HTTP — `TrimStrings`, `ConvertEmptyStringsToNull`, `SetLocale` — plutôt
 * qu'un validateur nu. L'exclusion des deux listes à la reprise d'un siège
 * appartient à la FormRequest de son propriétaire, pas à celle-ci.
 */
final class NicknameFormRequest extends FormRequest
{
    use PlayerIdentityValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nickname' => $this->nicknameRules(),
            'avatar' => $this->avatarPresetRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['nickname' => $this->prepareNickname($this->input('nickname'))]);
    }
}
