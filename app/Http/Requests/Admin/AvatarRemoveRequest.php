<?php

namespace App\Http\Requests\Admin;

use App\Avatars\AccountImage;
use App\Concerns\AdminReasonValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Retirer l'avatar téléversé d'un compte (spec 40 § 11.7) : motif
 * OBLIGATOIRE (`avatar.removed`).
 */
class AvatarRemoveRequest extends FormRequest
{
    use AdminReasonValidationRules;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
            'image' => ['nullable', Rule::enum(AccountImage::class)],
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

    /** La nature d'image visée, `upload` par défaut (spec 40 § 12.6). */
    public function accountImage(): AccountImage
    {
        return AccountImage::tryFrom((string) $this->string('image')) ?? AccountImage::Upload;
    }
}
