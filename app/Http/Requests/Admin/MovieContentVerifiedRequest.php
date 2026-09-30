<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AdminReasonValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cocher « contenu vérifié, pas de classification restrictive » — spec 20
 * § 4.4.
 *
 * Un seul champ, le **motif obligatoire** (`movie.content_verified`, C14) :
 * ce que le curateur a vérifié, relu tel quel au journal. Le drapeau de
 * départ n'est pas un champ : la garde `can:verifyContent,movie` exige
 * `unrated_pending`, et l'action le relit sous verrou.
 */
class MovieContentVerifiedRequest extends FormRequest
{
    use AdminReasonValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => __('admin.validation.reason'),
        ];
    }
}
