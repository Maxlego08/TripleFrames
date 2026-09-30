<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dépublier une image publiée, ou écarter une image jamais publiée — spec 20
 * § 8.4, contrat C14.
 *
 * Le motif est **facultatif** (`frame.unpublished`, C14) et borné par la
 * colonne `admin_action.reason`. L'état de départ n'est pas un champ : la
 * garde `can:unpublish,frame` le lit, et l'action le relit sous verrou.
 */
class FrameUnpublishRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.AdminAction::REASON_MAX_LENGTH],
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

    /** Le motif saisi, rogné, ou `null` s'il est vide. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
