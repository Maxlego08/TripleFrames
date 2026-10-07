<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ignorer les signalements ouverts d'une cible (D63 du 07/10, spec 20
 * § 11.6) : motif facultatif, borné comme tout motif du journal.
 */
class ContentReportDismissRequest extends FormRequest
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

    /** Le motif saisi, rogné, ou `null` s'il est vide. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
