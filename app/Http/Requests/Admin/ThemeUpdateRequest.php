<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ThemeRuleValidationRules;
use App\Models\Theme;
use App\ValueObjects\Admin\ThemeChanges;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Corriger un thème — spec 20 § 9.6 (D43 du 01/10). Le formulaire envoie
 * l'**état complet** : règle (ou `manual`), négation, libellés, ordre.
 *
 * - `theme_kind` et `key` ne sont **jamais** acceptés : la nature et la clé
 *   d'un thème ne changent pas ;
 * - la règle est validée selon la nature EN BASE du thème, sous le champ de
 *   cette nature ; une correction accepte une société ou un genre encore
 *   absents du catalogue (correction de `studio.disney`) ;
 * - passer d'une règle à « sans règle », ou l'inverse, est admis.
 */
class ThemeUpdateRequest extends FormRequest
{
    use ThemeRuleValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = $this->theme()->theme_kind;

        return [
            'theme_kind' => ['prohibited'],
            'key' => ['prohibited'],
            'manual' => ['sometimes', 'boolean'],
            'rule_negated' => $this->themeNegationRules($kind, $this->manual()),
            'sort_order' => ['required', ...$this->themeSortOrderRules()],
            ...$this->themeLabelRules(),
            ...$this->themeRuleRules($kind, $this->manual(), requireCatalogPresence: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'theme_kind.prohibited' => (string) __('admin.themes.kind_immutable'),
            'key.prohibited' => (string) __('admin.themes.key_immutable'),
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

    /** Vrai pour un thème sans règle. */
    public function manual(): bool
    {
        return $this->boolean('manual');
    }

    /** L'état demandé, règle sous sa forme canonique. */
    public function changes(): ThemeChanges
    {
        return new ThemeChanges(
            ruleValue: $this->themeRuleValue($this->theme()->theme_kind, $this->manual()),
            negated: $this->boolean('rule_negated'),
            labels: $this->themeLabels(),
            sortOrder: $this->integer('sort_order'),
        );
    }

    /** Le thème de la route, lié avant la validation. */
    private function theme(): Theme
    {
        $theme = $this->route('theme');

        if (! $theme instanceof Theme) {
            throw new LogicException('ThemeUpdateRequest exige le thème de la route.');
        }

        return $theme;
    }
}
