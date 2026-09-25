<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Corriger ou saisir le titre d'un film dans une locale activée — spec 20
 * § 9.1.
 *
 * Un seul champ : `title`, rogné (`TrimStrings`), de 1 à 255 caractères — la
 * largeur de `movie_title.title` (spec 10 § 3.4). Un titre fait d'espaces
 * seulement arrive nul (`ConvertEmptyStringsToNull`) et tombe sur `required`.
 *
 * La locale n'est pas un champ : elle est dans l'URL, liée à
 * `App\Enums\Locale` — une locale non activée répond 404 avant la requête.
 */
class MovieTitleUpdateRequest extends FormRequest
{
    /** La largeur de `movie_title.title` (spec 10 § 3.4). */
    public const int TITLE_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.self::TITLE_MAX_LENGTH],
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
            'title' => __('admin.validation.movie_title'),
        ];
    }

    /** Le titre saisi, rogné — non vide une fois la validation passée. */
    public function movieTitle(): string
    {
        return trim((string) $this->string('title'));
    }
}
