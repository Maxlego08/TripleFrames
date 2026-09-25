<?php

namespace App\Http\Requests\Admin;

use App\Enums\Locale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Ajouter un alias à un film — spec 20 § 9.2.
 *
 * - `locale` : une locale **activée** (`App\Enums\Locale`) — seules elles
 *   entrent dans `answer_key` ; un alias `ja` ne serait accepté nulle part,
 *   il est refusé ici plutôt que stocké en silence ;
 * - `alias` : rogné, de 1 à 255 caractères, la largeur de `alias.alias`.
 *
 * Aucune unicité sur le texte (spec 10 § 3.4) : l'écran avertit quand la
 * forme normalisée est déjà acceptée pour ce film, le serveur ne refuse pas.
 */
class MovieAliasStoreRequest extends FormRequest
{
    /** La largeur de `alias.alias` (spec 10 § 3.4). */
    public const int ALIAS_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|Enum>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::enum(Locale::class)],
            'alias' => ['required', 'string', 'max:'.self::ALIAS_MAX_LENGTH],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * Une locale hors du registre n'est pas une faute de frappe : c'est une
     * langue que le jeu n'accepte pas encore. Le message le dit.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locale.enum' => __('admin.movie.aliases.locale_not_enabled'),
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
            'locale' => __('admin.validation.alias_locale'),
            'alias' => __('admin.validation.alias'),
        ];
    }

    /** La locale activée de l'alias. */
    public function aliasLocale(): Locale
    {
        return Locale::from((string) $this->string('locale'));
    }

    /** L'alias saisi, rogné — non vide une fois la validation passée. */
    public function aliasText(): string
    {
        return trim((string) $this->string('alias'));
    }
}
