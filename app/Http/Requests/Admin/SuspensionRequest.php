<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspendre un film ou une image, lever la suspension d'une image — spec 20
 * § 11.2, contrat C14 : le motif est **facultatif** (`movie.suspended`,
 * `frame.suspended`, `frame.unsuspended`) et borné par la colonne
 * `admin_action.reason`. L'état de départ n'est pas un champ : la garde
 * `can:` le lit, et l'action le relit sous verrou.
 *
 * La demande de retrait liée n'est pas un champ de l'écran : elle passe par
 * la décision d'une demande (`DecideTakedownRequest`, L20-22), qui appelle
 * les mêmes actions. Forme livrée — amendé le 08/10.
 */
class SuspensionRequest extends FormRequest
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
