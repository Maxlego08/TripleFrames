<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * La sélection d'une resynchronisation (spec 20 § 3.7, L20-24) : un film —
 * l'écran de différences — ou un lot pris dans le catalogue, au plus
 * `catalog.import.paste_max_ids` films, le plafond d'un collage, puisque le
 * lot part dans la même charge utile de job.
 *
 * Partagée par l'écran (`GET`, la sélection en chaîne de requête) et le
 * lancement (`POST`) : la sélection montrée est, par construction, celle qui
 * est acceptée. L'éligibilité de chaque film (retiré, de démonstration, sans
 * identifiant TMDB) n'est pas une erreur de validation : l'écran nomme le
 * film écarté, et le lancement ne le relit pas.
 */
class MovieResyncRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Exists|string>>
     */
    public function rules(): array
    {
        return [
            'movies' => ['required', 'array', 'min:1', 'max:'.self::maxMovies()],
            'movies.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('movie', 'id')],
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
            'movies' => (string) __('admin.validation.resync_movies'),
            'movies.*' => (string) __('admin.validation.resync_movies'),
        ];
    }

    /** Le plafond d'un lot, celui d'un collage. */
    public static function maxMovies(): int
    {
        return max(1, Config::integer('catalog.import.paste_max_ids'));
    }

    /**
     * Les identifiants de films validés, dans l'ordre reçu.
     *
     * @return list<int>
     */
    public function movieIds(): array
    {
        /** @var array<array-key, mixed> $movies */
        $movies = (array) $this->validated('movies');

        return array_values(array_map(
            static fn (mixed $id): int => is_int($id) ? $id : (int) (is_string($id) ? $id : 0),
            $movies,
        ));
    }
}
