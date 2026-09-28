<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * L'écran de gestion des accès — spec 20 § 2.8.
 *
 * Un seul paramètre, facultatif : `email`, la recherche par adresse EXACTE qui
 * sert à promouvoir un compte existant. Pas de règle `email` : une saisie mal
 * formée ne trouve simplement aucun compte, et l'écran le dit, plutôt que de
 * refuser une recherche.
 */
class AccessIndexRequest extends FormRequest
{
    /** La largeur de `users.email`. */
    public const int EMAIL_MAX_LENGTH = 255;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'max:'.self::EMAIL_MAX_LENGTH],
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
            'email' => __('admin.validation.email'),
        ];
    }

    /** L'adresse cherchée, rognée ; `null` sans recherche. */
    public function email(): ?string
    {
        $email = trim((string) $this->string('email'));

        return $email === '' ? null : $email;
    }
}
