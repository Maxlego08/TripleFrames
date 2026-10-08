<?php

namespace App\Http\Requests\Admin;

use App\Models\TakedownRequest;
use App\Support\Curation\RetroactiveGrid;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\In;

/**
 * Le geste rétroactif de grille (spec 20 § 7.7, L20-25) :
 *
 * - `version` = la version courante de la grille : un écran resté ouvert
 *   pendant une montée de version ne dépublie jamais sous une version qu'il
 *   n'a pas montrée ;
 * - `reason` obligatoire, ≤ 500 (largeur de `admin_action.reason`, motif
 *   obligatoire de `frame.grid_unpublished`) ;
 * - `takedown_reference` facultatif, la référence `char(12)` d'une demande de
 *   retrait existante, liée à chaque ligne du journal.
 */
class ExclusionGridRetroactiveRequest extends FormRequest
{
    /** Largeur de `admin_action.reason`. */
    public const int REASON_MAX_LENGTH = 500;

    /** Largeur de `takedown_request.reference`. */
    public const int REFERENCE_LENGTH = 12;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|In|Exists|string>>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', Rule::in([app(RetroactiveGrid::class)->version])],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH],
            'takedown_reference' => [
                'nullable',
                'string',
                'size:'.self::REFERENCE_LENGTH,
                Rule::exists('takedown_request', 'reference'),
            ],
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.in' => (string) __('admin.exclusion_grid.retroactive.version_changed'),
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
            'reason' => (string) __('admin.validation.reason'),
            'takedown_reference' => (string) __('admin.validation.takedown_reference'),
        ];
    }

    /** Le motif, rogné. */
    public function reason(): string
    {
        return trim((string) $this->string('reason'));
    }

    /** La demande de retrait liée, s'il y en a une. */
    public function takedownRequest(): ?TakedownRequest
    {
        $reference = $this->validated('takedown_reference');

        return is_string($reference) && $reference !== ''
            ? TakedownRequest::query()->where('reference', $reference)->first()
            : null;
    }
}
