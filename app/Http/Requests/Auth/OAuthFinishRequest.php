<?php

namespace App\Http\Requests\Auth;

use App\Concerns\ConsentValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'envoi de « Finaliser l'inscription » (spec 40 § 12.3) : le nom du compte,
 * par la règle de pseudo de jeu (§ 13.1), l'acceptation des CGU et la
 * déclaration d'âge.
 */
class OAuthFinishRequest extends FormRequest
{
    use ConsentValidationRules, ProfileValidationRules;

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => $this->prepareName($this->input('name'))]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            ...$this->consentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->consentMessages();
    }

    public function accountName(): string
    {
        return trim((string) $this->validated('name'));
    }
}
