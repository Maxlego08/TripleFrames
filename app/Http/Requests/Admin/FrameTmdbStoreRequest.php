<?php

namespace App\Http\Requests\Admin;

use App\Concerns\FrameCropValidationRules;
use App\Enums\FrameLevel;
use App\Settings\PlatformLimits;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Ajouter une variante depuis un visuel TMDB — contrat C9, spec 20 § 5.3.
 *
 * **Formulaire sans fichier** : le navigateur n'envoie que la référence du
 * visuel et le rectangle, et c'est le serveur qui télécharge l'original.
 *
 * Ce que cette requête ne peut PAS vérifier, et qui l'est par le contrôleur :
 * l'appartenance du chemin aux backdrops du film (un FormRequest ne connaît
 * pas `TmdbClient`, `TmdbBoundaryTest`) et la borne de HAUTEUR du plancher,
 * qui dépend de la hauteur du master, donc des métadonnées TMDB. Ici, la
 * forme seule, par les règles partagées du cadre
 * ({@see FrameCropValidationRules}) : 16:9 exact, et la largeur entre la
 * largeur minimale et la borne de largeur du plancher, lues dans
 * {@see PlatformLimits} — jamais un littéral (règle 2).
 *
 * Le niveau n'a **aucun défaut** : un défaut serait un classement non décidé
 * (§ 6.5).
 */
class FrameTmdbStoreRequest extends FormRequest
{
    use FrameCropValidationRules;

    /**
     * Forme d'un chemin de visuel TMDB, la même que
     * `TmdbClient::IMAGE_FILE_PATH_PATTERN` : recopiée et non importée, une
     * requête ne connaissant aucun type de `App\Support\Tmdb`
     * (`TmdbBoundaryTest`). `D` refuse un saut de ligne final.
     */
    public const string TMDB_FILE_PATH_PATTERN = '/^\/[A-Za-z0-9_-]+\.(?:jpg|png)$/D';

    /** Longueur maximale du chemin, celle de la colonne `frame.tmdb_file_path`. */
    public const int TMDB_FILE_PATH_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|Closure|string|Enum>>
     */
    public function rules(): array
    {
        return [
            'tmdb_file_path' => ['required', 'string', 'max:'.self::TMDB_FILE_PATH_MAX_LENGTH, 'regex:'.self::TMDB_FILE_PATH_PATTERN],
            'frame_level' => ['required', 'integer', Rule::enum(FrameLevel::class)],
            ...$this->cropRules(),
        ];
    }

    /**
     * Le ratio exact du cadre, une fois ses champs valides.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [$this->cropAspectCheck()];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Un chemin hors forme ne peut désigner aucun backdrop : même message que
     * le refus d'appartenance du contrôleur.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tmdb_file_path.regex' => __('admin.frame.tmdb.not_a_backdrop'),
            ...$this->cropMessages(),
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
            'tmdb_file_path' => __('admin.validation.tmdb_file_path'),
            'frame_level' => __('admin.validation.frame_level'),
            ...$this->cropAttributes(),
        ];
    }

    /** Le chemin du visuel, tel que TMDB le nomme. */
    public function tmdbFilePath(): string
    {
        return (string) $this->string('tmdb_file_path');
    }

    /** Le niveau choisi par le curateur, sur l'échelle fermée 1-5. */
    public function frameLevel(): FrameLevel
    {
        return FrameLevel::from($this->integer('frame_level'));
    }
}
