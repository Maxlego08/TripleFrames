<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lever le masquage ou le retrait d'un avatar téléversé (spec 40 § 11.7) :
 * motif facultatif (`avatar.unhidden`).
 */
class AvatarUnhideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH],
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

    /** Le motif rogné, vide ramené à `null`. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
