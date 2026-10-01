<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AdminReasonValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Retirer l'avatar téléversé d'un compte (spec 40 § 11.7) : motif
 * OBLIGATOIRE (`avatar.removed`).
 */
class AvatarRemoveRequest extends FormRequest
{
    use AdminReasonValidationRules;

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => __('admin.validation.reason'),
        ];
    }
}
