<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La recherche TMDB du back-office — spec 20 § 3.4.
 *
 * `q` de deux à cent caractères : en deçà, TMDB rendrait des milliers de
 * résultats sans rapport ; au-delà, aucun titre ne s'écrit ainsi. Ce sont les
 * bornes d'un champ de recherche, pas des valeurs de jeu : elles ne viennent
 * d'aucun `room_settings` et n'atteignent aucune partie.
 */
class ImportSearchRequest extends FormRequest
{
    /** Longueur minimale de la recherche, en caractères. */
    public const int QUERY_MIN_LENGTH = 2;

    /** Longueur maximale de la recherche, en caractères. */
    public const int QUERY_MAX_LENGTH = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        return [
            'q' => [
                'required',
                'string',
                'min:'.self::QUERY_MIN_LENGTH,
                'max:'.self::QUERY_MAX_LENGTH,
            ],
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
            'q' => __('admin.validation.tmdb_query'),
        ];
    }

    /** La recherche, telle que TMDB la recevra. */
    public function searchQuery(): string
    {
        return trim((string) $this->string('q'));
    }
}
