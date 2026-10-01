<?php

namespace App\Concerns;

use App\Enums\Locale;
use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Enums\TmdbTagKind;
use App\Models\MovieTmdbTag;
use App\Support\Catalog\ThemeRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Exists;

/**
 * Les règles d'un thème saisi à l'écran des thèmes (spec 20 § 9.6, D43 du
 * 01/10), partagées par `ThemeStoreRequest` et `ThemeUpdateRequest`.
 *
 * La valeur de la règle est validée **selon la nature** (spec 30 § 12.1),
 * chacune sous SON champ :
 *
 * - saga : `collection_id`, une `collection` déjà au catalogue ;
 * - studio : `company_ids`, une à huit sociétés, liste canonique d'au plus
 *   64 caractères — la longueur se valide pour elle-même, les identifiants
 *   TMDB atteignant sept chiffres (critique C19) ;
 * - genre : `rule_value`, un identifiant de genre TMDB ;
 * - décennie : `rule_value`, année de début à quatre chiffres multiple de 10 ;
 * - langue : `rule_value`, deux lettres minuscules (forme de
 *   `movie.original_language`) ;
 * - difficulté (correction seulement) : `rule_value`, un cas de `MovieDifficulty`.
 *
 * À la **création**, genre et sociétés doivent être portés par au moins un
 * film du catalogue (`$requireCatalogPresence`) ; une **correction** les
 * accepte absents — c'est le cas de `studio.disney` corrigé vers
 * `2,6125,171656` avant qu'un film Disney d'animation n'entre au catalogue.
 * Un thème **manuel** (`manual` accepté) ne porte aucune règle : ses champs
 * de règle sont ignorés. La désignation (une collection ou une société par
 * thème) est relue sous verrou par l'action, jamais ici seulement.
 */
trait ThemeRuleValidationRules
{
    /** Plus petite année de début de décennie admise. */
    private const int THEME_DECADE_MIN = 1900;

    /** Plus grande année de début de décennie admise. */
    private const int THEME_DECADE_MAX = 2090;

    /** Largeur de `theme_label.label` (spec 10 § 3.7). */
    private const int THEME_LABEL_MAX_LENGTH = 80;

    /** Borne de `theme.sort_order`, un `unsignedSmallInteger`. */
    private const int THEME_SORT_ORDER_MAX = 65_535;

    /**
     * Les règles des champs de la règle, pour la nature `$kind`.
     *
     * @return array<string, array<int, ValidationRule|Closure|string|Exists|Enum>>
     */
    protected function themeRuleRules(?ThemeKind $kind, bool $manual, bool $requireCatalogPresence): array
    {
        if ($kind === null || $manual) {
            return [];
        }

        return match ($kind) {
            ThemeKind::Saga => [
                'collection_id' => ['required', 'integer', 'min:1', Rule::exists('collection', 'id')],
            ],
            ThemeKind::Studio => [
                'company_ids' => [
                    'required',
                    'array',
                    'min:1',
                    'max:'.ThemeRules::STUDIO_MAX_COMPANIES,
                    static function (string $attribute, mixed $value, Closure $fail): void {
                        if (! is_array($value)) {
                            return;
                        }

                        $ids = array_filter($value, static fn (mixed $id): bool => is_numeric($id));

                        if (strlen(ThemeRules::studioRuleValue(array_map('intval', $ids))) > ThemeRules::RULE_VALUE_MAX_LENGTH) {
                            $fail(__('admin.themes.rule_too_long', ['max' => ThemeRules::RULE_VALUE_MAX_LENGTH]));
                        }
                    },
                    ...($requireCatalogPresence ? [self::companiesPresentRule()] : []),
                ],
                'company_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            ],
            ThemeKind::Genre => [
                'rule_value' => [
                    'required',
                    'integer',
                    'min:1',
                    ...($requireCatalogPresence
                        ? [Rule::exists('movie_tmdb_tag', 'tmdb_tag_id')->where('tag_kind', TmdbTagKind::Genre->value)]
                        : []),
                ],
            ],
            ThemeKind::Decade => [
                'rule_value' => [
                    'required',
                    'integer',
                    'min:'.self::THEME_DECADE_MIN,
                    'max:'.self::THEME_DECADE_MAX,
                    'multiple_of:10',
                ],
            ],
            ThemeKind::Language => [
                'rule_value' => ['required', 'string', 'regex:/^[a-z]{2}$/'],
            ],
            ThemeKind::Difficulty => [
                'rule_value' => ['required', 'string', Rule::enum(MovieDifficulty::class)],
            ],
        };
    }

    /**
     * Les libellés, un par locale d'interface activée, rognés, 1 à 80 caractères.
     *
     * @return array<string, array<int, string>>
     */
    protected function themeLabelRules(): array
    {
        $rules = ['labels' => ['required', 'array']];

        foreach (Locale::cases() as $locale) {
            $rules['labels.'.$locale->value] = ['required', 'string', 'max:'.self::THEME_LABEL_MAX_LENGTH];
        }

        return $rules;
    }

    /**
     * La négation : proposée pour toute nature sauf `saga` et le thème manuel,
     * où elle est refusée si elle est demandée.
     *
     * @return array<int, string|ConditionalRules>
     */
    protected function themeNegationRules(?ThemeKind $kind, bool $manual): array
    {
        return [
            'sometimes',
            'boolean',
            Rule::when($manual || $kind === ThemeKind::Saga, ['declined']),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function themeSortOrderRules(): array
    {
        return ['integer', 'min:0', 'max:'.self::THEME_SORT_ORDER_MAX];
    }

    /**
     * Messages des refus propres aux thèmes.
     *
     * @return array<string, string>
     */
    protected function themeMessages(): array
    {
        return [
            'rule_negated.declined' => (string) __('admin.themes.negation_forbidden'),
            'rule_value.multiple_of' => (string) __('admin.themes.decade_invalid'),
            'rule_value.regex' => (string) __('admin.themes.language_invalid'),
            'rule_value.exists' => (string) __('admin.themes.genre_absent'),
        ];
    }

    /**
     * Les attributs nommés des champs de thème.
     *
     * @return array<string, string>
     */
    protected function themeAttributes(): array
    {
        return [
            'theme_kind' => (string) __('admin.validation.theme_kind'),
            'manual' => (string) __('admin.validation.theme_manual'),
            'collection_id' => (string) __('admin.validation.theme_collection'),
            'company_ids' => (string) __('admin.validation.theme_companies'),
            'company_ids.*' => (string) __('admin.validation.theme_company'),
            'rule_value' => (string) __('admin.validation.theme_rule'),
            'rule_negated' => (string) __('admin.validation.theme_negated'),
            'labels' => (string) __('admin.validation.theme_labels'),
            'labels.fr' => (string) __('admin.validation.theme_label_fr'),
            'labels.en' => (string) __('admin.validation.theme_label_en'),
            'sort_order' => (string) __('admin.validation.theme_sort_order'),
        ];
    }

    /**
     * La règle canonique demandée, lue sous le champ de sa nature ; `null`
     * pour un thème manuel.
     */
    protected function themeRuleValue(ThemeKind $kind, bool $manual): ?string
    {
        if ($manual) {
            return null;
        }

        return match ($kind) {
            ThemeKind::Saga => (string) $this->integer('collection_id'),
            ThemeKind::Studio => ThemeRules::studioRuleValue(array_map(
                'intval',
                array_filter((array) $this->input('company_ids', []), static fn (mixed $id): bool => is_numeric($id)),
            )),
            ThemeKind::Genre, ThemeKind::Decade => (string) $this->integer('rule_value'),
            ThemeKind::Language, ThemeKind::Difficulty => (string) $this->string('rule_value'),
        };
    }

    /**
     * Les libellés rognés, par locale d'interface activée.
     *
     * @return array<string, string>
     */
    protected function themeLabels(): array
    {
        $labels = [];

        foreach (Locale::cases() as $locale) {
            $labels[$locale->value] = trim((string) $this->string('labels.'.$locale->value));
        }

        return $labels;
    }

    /**
     * Chaque société doit être portée par au moins un film du catalogue — une
     * seule requête, quel que soit le nombre de sociétés.
     */
    private static function companiesPresentRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $ids = array_values(array_unique(array_map('intval', array_filter(
                $value,
                static fn (mixed $id): bool => is_numeric($id),
            ))));

            if ($ids === []) {
                return;
            }

            $present = MovieTmdbTag::query()
                ->where('tag_kind', TmdbTagKind::Company)
                ->whereIn('tmdb_tag_id', $ids)
                ->distinct()
                ->pluck('tmdb_tag_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $missing = array_values(array_diff($ids, $present));

            if ($missing !== []) {
                $fail(__('admin.themes.company_absent', ['ids' => implode(', ', $missing)]));
            }
        };
    }
}
