<?php

namespace App\Http\Requests\Auth;

use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'envoi de « Finaliser l'inscription » (spec 40 § 12.3) : le nom du compte,
 * par les mêmes règles que le profil, l'acceptation des CGU et la
 * déclaration d'âge.
 */
class OAuthFinishRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'terms' => ['accepted'],
            'age' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'terms.accepted' => (string) __('account.oauth.finish.terms_required'),
            'age.accepted' => (string) __('account.oauth.finish.age_required'),
        ];
    }

    public function accountName(): string
    {
        return trim((string) $this->validated('name'));
    }
}
