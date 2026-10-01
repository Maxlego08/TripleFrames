<?php

namespace App\Http\Requests\Admin;

use App\Enums\ThemeMembershipState;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'exception manuelle d'un film pour un thème — spec 20 § 9.6 (D43 du
 * 01/10).
 *
 * - `theme_id` : un thème existant, publié ou non ;
 * - `manual_state` : `added`, `removed`, ou vide pour annuler l'exception.
 *   Le champ doit être **présent** : un envoi qui ne le nomme pas est
 *   refusé, jamais lu comme une annulation.
 *
 * L'état du film (retiré ou non) se relit sous verrou dans l'action.
 */
class MovieThemeUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'theme_id' => ['required', 'integer', 'exists:theme,id'],
            'manual_state' => ['present', 'nullable', Rule::enum(ThemeMembershipState::class)],
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
            'theme_id' => __('admin.validation.movie_theme'),
            'manual_state' => __('admin.validation.movie_theme_state'),
        ];
    }

    /** Le thème visé. */
    public function themeId(): int
    {
        return $this->integer('theme_id');
    }

    /** L'exception demandée ; `null` annule l'exception. */
    public function state(): ?ThemeMembershipState
    {
        return ThemeMembershipState::tryFrom(trim((string) $this->string('manual_state')));
    }
}
