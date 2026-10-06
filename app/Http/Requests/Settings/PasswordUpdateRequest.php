<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // Un compte créé par un fournisseur n'a pas de mot de passe : il en
        // DÉFINIT un, sans ancien à saisir, derrière la confirmation fraîche
        // que la route exige déjà (spec 40 § 12.4, D51 du 01/10).
        if ($this->user()?->password === null) {
            return [
                'password' => $this->passwordRules(),
            ];
        }

        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
