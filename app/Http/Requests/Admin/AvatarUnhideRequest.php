<?php

namespace App\Http\Requests\Admin;

use App\Avatars\AccountImage;
use App\Models\AdminAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lever le masquage ou le retrait d'un avatar téléversé (spec 40 § 11.7) :
 * motif facultatif (`avatar.unhidden`).
 */
class AvatarUnhideRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH],
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

    /** Le motif rogné, vide ramené à `null`. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }

    /** La nature d'image visée, `upload` par défaut (spec 40 § 12.6). */
    public function accountImage(): AccountImage
    {
        return AccountImage::tryFrom((string) $this->string('image')) ?? AccountImage::Upload;
    }
}
