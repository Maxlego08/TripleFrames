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
 * - `theme_id` : un thème existant, publié ou non, pour un geste unitaire ;
 * - `theme_ids` : plusieurs thèmes existants, pour l'ajout groupé ;
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
            'theme_id' => ['nullable', 'required_without:theme_ids', 'prohibits:theme_ids', 'integer', 'exists:theme,id'],
            'theme_ids' => ['nullable', 'required_without:theme_id', 'prohibits:theme_id', 'prohibited_unless:manual_state,added', 'array', 'min:1'],
            'theme_ids.*' => ['integer', 'distinct', 'exists:theme,id'],
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
            'theme_ids' => __('admin.validation.movie_themes'),
            'theme_ids.*' => __('admin.validation.movie_theme'),
            'manual_state' => __('admin.validation.movie_theme_state'),
        ];
    }

    /** Le thème visé. */
    public function themeId(): int
    {
        return $this->integer('theme_id');
    }

    /**
     * Les thèmes visés par un ajout groupé.
     *
     * @return list<int>
     */
    public function themeIds(): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->input('theme_ids', []);

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    /** La requête porte l'ajout groupé plutôt qu'un geste unitaire. */
    public function isBatchAddition(): bool
    {
        return $this->has('theme_ids');
    }

    /** L'exception demandée ; `null` annule l'exception. */
    public function state(): ?ThemeMembershipState
    {
        return ThemeMembershipState::tryFrom(trim((string) $this->string('manual_state')));
    }
}
