<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ThemeRuleValidationRules;
use App\Enums\ThemeKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Créer un thème — spec 20 § 9.6 (D43 du 01/10).
 *
 * - `theme_kind` requis, parmi les cinq natures créables : `difficulty` est
 *   refusée par une erreur de validation (`admin.themes.kind_forbidden`),
 *   jamais par la policy, qui ne voit pas la nature ;
 * - `manual` (accepté) : thème **sans règle**, qui ne contient que ses ajouts
 *   manuels ; ses champs de règle sont alors ignorés ;
 * - la règle sous le champ de sa nature, présente au catalogue
 *   ({@see ThemeRuleValidationRules}) ;
 * - `rule_negated` refusé pour une saga et pour un thème manuel ;
 * - un libellé par locale activée, 1 à 80 caractères ; `sort_order` facultatif.
 *
 * Clé, collection et sociétés déjà désignées : refus de l'action, relus sous
 * verrou.
 */
class ThemeStoreRequest extends FormRequest
{
    use ThemeRuleValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'theme_kind' => [
                'required',
                'string',
                Rule::enum(ThemeKind::class),
                Rule::notIn([ThemeKind::Difficulty->value]),
            ],
            'manual' => ['sometimes', 'boolean'],
            'rule_negated' => $this->themeNegationRules($this->requestedKind(), $this->manual()),
            'sort_order' => ['nullable', ...$this->themeSortOrderRules()],
            ...$this->themeLabelRules(),
            ...$this->themeRuleRules($this->requestedKind(), $this->manual(), requireCatalogPresence: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'theme_kind.not_in' => (string) __('admin.themes.kind_forbidden'),
            ...$this->themeMessages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->themeAttributes();
    }

    /** La nature validée. */
    public function kind(): ThemeKind
    {
        return ThemeKind::from((string) $this->string('theme_kind'));
    }

    /** Vrai pour un thème sans règle. */
    public function manual(): bool
    {
        return $this->boolean('manual');
    }

    /** La règle canonique ; `null` pour un thème manuel. */
    public function ruleValue(): ?string
    {
        return $this->themeRuleValue($this->kind(), $this->manual());
    }

    /** La négation demandée — la validation l'a refusée pour une saga et un thème manuel. */
    public function negated(): bool
    {
        return $this->boolean('rule_negated');
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->themeLabels();
    }

    /** L'ordre saisi ; `null` = fin du bloc de la nature. */
    public function sortOrder(): ?int
    {
        return $this->filled('sort_order') ? $this->integer('sort_order') : null;
    }

    /** La nature demandée, avant validation — `null` si elle n'en est pas une. */
    private function requestedKind(): ?ThemeKind
    {
        return ThemeKind::tryFrom((string) $this->string('theme_kind'));
    }
}
