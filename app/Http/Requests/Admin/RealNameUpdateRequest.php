<?php

namespace App\Http\Requests\Admin;

use App\Concerns\RealNameValidationRules;
use App\Models\AdminAction;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corriger le nom réel d'un compte privilégié — spec 20 § 2.8.
 *
 * `real_name` passe par {@see RealNameValidationRules::realNameRules()}, les
 * mêmes règles que la commande du premier administrateur (D12 du 23/09) ;
 * `reason`, le motif, est **facultatif** (`user.real_name_changed`, EN20-3) et
 * borné par la colonne `admin_action.reason`.
 */
class RealNameUpdateRequest extends FormRequest
{
    use RealNameValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string|Closure>>
     */
    public function rules(): array
    {
        return [
            'real_name' => $this->realNameRules(),
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
            'real_name' => __('admin.validation.real_name_field'),
            'reason' => __('admin.validation.reason'),
        ];
    }

    /** Le nom réel saisi, rogné — non vide une fois la validation passée. */
    public function realName(): string
    {
        return trim((string) $this->string('real_name'));
    }

    /** Le motif saisi, rogné, ou `null` s'il est vide. */
    public function reason(): ?string
    {
        $reason = trim((string) $this->string('reason'));

        return $reason === '' ? null : $reason;
    }
}
