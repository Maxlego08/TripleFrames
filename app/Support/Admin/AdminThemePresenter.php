<?php

namespace App\Support\Admin;

use App\Enums\Locale;
use App\Enums\ThemeKind;
use App\Models\Collection;
use App\Models\Theme;
use App\Models\ThemeLabel;
use App\Models\TmdbCompany;
use App\Support\Catalog\ThemeRules;
use Illuminate\Support\Collection as SupportCollection;

/**
 * La forme EXACTE des thèmes de l'écran des thèmes (spec 20 § 9.6, D43 du
 * 01/10), en **snake_case**, miroir de `AdminTheme` dans
 * `resources/js/types/admin.ts`.
 *
 * La règle est rendue **en données** (`rule_items` : valeur et nom résolu),
 * jamais en phrase pré-formatée (règle 4) : le front compose « Marvel Studios
 * (420) ». Une société sans ligne `tmdb_company` et tout genre restent
 * nommés par leur identifiant. Les noms se résolvent en un nombre fixe de
 * requêtes pour tout l'écran ({@see self::names()}), jamais par thème.
 */
final class AdminThemePresenter
{
    /**
     * Les noms des sociétés et des collections désignées par les thèmes :
     * deux requêtes au plus, quel que soit le nombre de thèmes.
     *
     * @param  SupportCollection<int, Theme>|iterable<Theme>  $themes
     * @return array{companies: array<int, string>, collections: array<int, string>}
     */
    public static function names(iterable $themes): array
    {
        $companyIds = [];
        $collectionIds = [];

        foreach ($themes as $theme) {
            if ($theme->theme_kind === ThemeKind::Studio) {
                array_push($companyIds, ...ThemeRules::studioCompanyIds($theme->rule_value));
            }

            if ($theme->theme_kind === ThemeKind::Saga && $theme->rule_value !== null && ctype_digit($theme->rule_value)) {
                $collectionIds[] = (int) $theme->rule_value;
            }
        }

        $companies = [];

        if ($companyIds !== []) {
            foreach (TmdbCompany::query()->whereIn('tmdb_id', array_unique($companyIds))->get(['tmdb_id', 'name']) as $company) {
                $companies[$company->tmdb_id] = $company->name;
            }
        }

        $collections = [];

        if ($collectionIds !== []) {
            foreach (Collection::query()->whereIn('id', array_unique($collectionIds))->get(['id', 'name']) as $collection) {
                $collections[$collection->id] = $collection->name;
            }
        }

        return ['companies' => $companies, 'collections' => $collections];
    }

    /**
     * Un thème de l'écran.
     *
     * @param  array{companies: array<int, string>, collections: array<int, string>}  $names
     * @param  array{update: bool, publish: bool}  $abilities
     * @return array{
     *     id: int,
     *     key: string,
     *     kind: string,
     *     rule_value: string|null,
     *     company_ids: list<int>,
     *     collection_id: int|null,
     *     rule_items: list<array{value: string, name: string|null}>,
     *     negated: bool,
     *     manual: bool,
     *     is_published: bool,
     *     sort_order: int,
     *     labels: array<string, string|null>,
     *     works: int,
     *     active_films: int,
     *     missing_locales: list<string>,
     *     publish_notice: string|null,
     *     abilities: array{update: bool, publish: bool},
     * }
     */
    public static function theme(Theme $theme, int $works, int $activeFilms, array $names, array $abilities): array
    {
        $companyIds = $theme->theme_kind === ThemeKind::Studio
            ? ThemeRules::studioCompanyIds($theme->rule_value)
            : [];

        $collectionId = $theme->theme_kind === ThemeKind::Saga && $theme->rule_value !== null && ctype_digit($theme->rule_value)
            ? (int) $theme->rule_value
            : null;

        $labels = [];

        foreach (Locale::cases() as $locale) {
            $labels[$locale->value] = $theme->labels
                ->first(fn (ThemeLabel $label): bool => $label->locale === $locale)
                ?->label;
        }

        return [
            'id' => $theme->id,
            'key' => $theme->key,
            'kind' => $theme->theme_kind->value,
            'rule_value' => $theme->rule_value,
            'company_ids' => $companyIds,
            'collection_id' => $collectionId,
            'rule_items' => self::ruleItems($theme, $companyIds, $collectionId, $names),
            'negated' => $theme->rule_negated,
            'manual' => $theme->rule_value === null,
            'is_published' => $theme->is_published,
            'sort_order' => $theme->sort_order,
            'labels' => $labels,
            'works' => $works,
            'active_films' => $activeFilms,
            'missing_locales' => array_map(
                static fn (Locale $locale): string => $locale->value,
                $theme->missingLabelLocales(),
            ),
            'publish_notice' => self::publishNotice($theme),
            'abilities' => $abilities,
        ];
    }

    /**
     * L'avertissement propre à la publication d'un thème, ou `null`.
     *
     * Un thème de langue `ja` non nié — `language.anime`, « Animés japonais »
     * — capte aussi les films japonais en prise de vue réelle : ils doivent
     * en avoir été retirés avant publication (spec 30 § 12.3, critique C17).
     * Le seuil d'œuvres seul ne le garantit pas ; la boîte le rappelle.
     */
    public static function publishNotice(Theme $theme): ?string
    {
        if ($theme->theme_kind === ThemeKind::Language
            && $theme->rule_value === 'ja'
            && ! $theme->rule_negated) {
            return 'live_action_japanese';
        }

        return null;
    }

    /**
     * La règle en données : une entrée par valeur, avec son nom résolu.
     *
     * @param  list<int>  $companyIds
     * @param  array{companies: array<int, string>, collections: array<int, string>}  $names
     * @return list<array{value: string, name: string|null}>
     */
    private static function ruleItems(Theme $theme, array $companyIds, ?int $collectionId, array $names): array
    {
        if ($theme->rule_value === null) {
            return [];
        }

        return match ($theme->theme_kind) {
            ThemeKind::Studio => array_map(
                static fn (int $id): array => ['value' => (string) $id, 'name' => $names['companies'][$id] ?? null],
                $companyIds,
            ),
            ThemeKind::Saga => [[
                'value' => $theme->rule_value,
                'name' => $collectionId !== null ? ($names['collections'][$collectionId] ?? null) : null,
            ]],
            ThemeKind::Genre,
            ThemeKind::Decade,
            ThemeKind::Language,
            ThemeKind::Difficulty => [['value' => $theme->rule_value, 'name' => null]],
        };
    }
}
