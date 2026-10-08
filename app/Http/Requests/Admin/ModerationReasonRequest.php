<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminAction;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lever le masquage d'un pseudo (spec 20 § 11.5, spec 40 § 13.3) : motif
 * FACULTATIF (`nickname.unmasked`), borné par la colonne du journal.
 */
class ModerationReasonRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
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
