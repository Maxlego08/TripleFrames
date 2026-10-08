<?php

namespace App\Http\Requests\Admin;

use App\Enums\MovieDifficulty;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Corriger la difficulté d'un film (spec 20 § 9.6, L20-28b) :
 * `movie_difficulty_override` ∈ `MovieDifficulty`, ou vide pour rendre la
 * main à la dérivation. Le champ est **présent** dans tout envoi : un envoi
 * qui l'omet ne se lit jamais comme un retrait de correction.
 */
class MovieDifficultyUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Enum|string>>
     */
    public function rules(): array
    {
        return [
            'movie_difficulty_override' => ['present', 'nullable', 'string', Rule::enum(MovieDifficulty::class)],
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
            'movie_difficulty_override' => (string) __('admin.validation.movie_difficulty_override'),
        ];
    }

    /** La correction demandée, ou `null` pour rendre la main à la dérivation. */
    public function override(): ?MovieDifficulty
    {
        $value = $this->validated('movie_difficulty_override');

        return is_string($value) ? MovieDifficulty::from($value) : null;
    }
}
