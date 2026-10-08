<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AdminReasonValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bannir un pseudo (spec 20 § 11.5, spec 40 § 13.3, n° 8 de D66 du 07/10) :
 * motif OBLIGATOIRE (`nickname.banned`).
 */
class ModerationBanRequest extends FormRequest
{
    use AdminReasonValidationRules;

    /**
     * @return array<string, array<int, mixed>>
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
