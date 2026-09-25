<?php

namespace App\Http\Requests\Admin;

use App\Concerns\AdminReasonValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dépublier un film publié, ou écarter un brouillon — spec 20 § 8.3, § 4.2.
 *
 * Un seul champ, le **motif obligatoire** (`movie.unpublished`, C14) : il est
 * écrit sur `availability_reason` et au journal. L'état de départ n'est pas un
 * champ : la garde `can:unpublish,movie` le lit, et l'action le relit sous
 * verrou.
 */
class MovieUnpublishRequest extends FormRequest
{
    use AdminReasonValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'reason' => $this->requiredReasonRules(),
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
}
