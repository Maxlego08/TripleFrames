<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le contrat de la query string de l'écran « Avatars » — filtre et pagination
 * (spec 20 § 12.5, D49 du 01/10).
 */
class AvatarModerationRequest extends FormRequest
{
    /**
     * Les filtres de l'écran : tous les avatars téléversés ou masqués, les
     * seuls masqués, les seuls signalés dans leur fenêtre courante.
     *
     * @var list<string>
     */
    public const array FILTERS = ['hidden', 'reported'];

    public const int PER_PAGE = 25;

    /**
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'filter' => ['nullable', Rule::in(self::FILTERS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filter(): ?string
    {
        $value = trim((string) $this->string('filter'));

        return in_array($value, self::FILTERS, true) ? $value : null;
    }
}
