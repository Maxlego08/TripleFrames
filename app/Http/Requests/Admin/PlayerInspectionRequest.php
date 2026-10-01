<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string de l'annuaire des sièges — recherche, mode et
 * pagination (spec 20 § 12.2, D46 du 01/10).
 */
class PlayerInspectionRequest extends FormRequest
{
    /**
     * Les deux origines d'un siège : un salon, ou le mode solo
     * (`player.room_id` nul).
     *
     * @var list<string>
     */
    public const array MODES = ['room', 'solo'];

    /** Une page d'annuaire. */
    public const int PER_PAGE = 25;

    /** La longueur d'une recherche libre, comme celle de l'annuaire des comptes. */
    public const int SEARCH_MAX_LENGTH = 120;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            'mode' => ['nullable', Rule::in(self::MODES)],
            'page' => ['nullable', 'integer', 'min:1'],
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
            'q' => __('admin.validation.search'),
            'mode' => __('admin.validation.game_mode'),
        ];
    }

    /**
     * Les filtres tels que l'écran doit les réafficher.
     *
     * @return array{q: string|null, mode: string|null}
     */
    public function filters(): array
    {
        return [
            'q' => $this->search(),
            'mode' => $this->mode(),
        ];
    }

    /** La recherche libre, vide ramenée à `null`. */
    public function search(): ?string
    {
        $value = trim((string) $this->string('q'));

        return $value === '' ? null : $value;
    }

    public function mode(): ?string
    {
        $value = trim((string) $this->string('mode'));

        return in_array($value, self::MODES, true) ? $value : null;
    }
}
