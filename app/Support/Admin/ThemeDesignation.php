<?php

namespace App\Support\Admin;

use App\Enums\ThemeKind;
use App\Models\Theme;
use App\Support\Catalog\ThemeRules;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Qui désigne déjà une collection ou une société (spec 20 § 9.6, D43 du 01/10).
 *
 * La base n'a **aucune contrainte** pour ces deux unicités : un thème de saga
 * par `collection`, un thème studio par société, celle-ci pouvant figurer dans
 * une liste (`"429,128064,184898"`). Les gestes de création et de correction
 * les relisent donc **sous un verrou de désignation** ({@see self::exclusively()}),
 * dans la transaction du geste : deux créations simultanées ne désignent
 * jamais deux fois la même collection ni la même société.
 *
 * Les règles studio se découpent en PHP par {@see ThemeRules::studioCompanyIds()},
 * seul lecteur de leur forme — une recherche `LIKE` confondrait 42 et 420.
 * Les thèmes studio se comptent sur les doigts : les lire tous est sans coût.
 */
final class ThemeDesignation
{
    /** Nom du verrou partagé par tous les gestes qui désignent une collection ou une société. */
    public const string LOCK = 'theme-designation';

    /** Durée de vie du verrou, en secondes : bien au-delà d'une transaction de geste. */
    private const int LOCK_SECONDS = 10;

    /** Attente maximale du verrou avant un refus traduit, en secondes. */
    private const int LOCK_WAIT_SECONDS = 5;

    /**
     * Exécute `$gesture` sous le verrou de désignation.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $gesture
     * @return TResult
     *
     * @throws ValidationException le verrou n'a pas été obtenu à temps
     * @throws Throwable les exceptions du geste lui-même, transmises telles quelles
     */
    public static function exclusively(Closure $gesture): mixed
    {
        try {
            return Cache::lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $gesture);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'theme_kind' => __('admin.themes.busy'),
            ]);
        }
    }

    /**
     * Refuse une règle de saga ou de studio qui désignerait une collection ou
     * une société déjà désignée par un autre thème de sa nature — à appeler
     * sous {@see self::exclusively()}, dans la transaction du geste. Sans effet
     * pour les autres natures et pour un thème manuel.
     *
     * @throws ValidationException
     */
    public static function assertFree(ThemeKind $kind, ?string $ruleValue, ?int $exceptThemeId = null): void
    {
        if ($ruleValue === null) {
            return;
        }

        if ($kind === ThemeKind::Saga && ctype_digit($ruleValue)) {
            $owner = self::collectionTakenBy((int) $ruleValue, $exceptThemeId);

            if ($owner !== null) {
                throw ValidationException::withMessages([
                    'collection_id' => __('admin.themes.collection_taken', ['key' => $owner]),
                ]);
            }
        }

        if ($kind === ThemeKind::Studio) {
            $owner = self::companiesTakenBy(ThemeRules::studioCompanyIds($ruleValue), $exceptThemeId);

            if ($owner !== null) {
                throw ValidationException::withMessages([
                    'company_ids' => __('admin.themes.company_taken', ['key' => $owner]),
                ]);
            }
        }
    }

    /**
     * La clé du thème de saga qui désigne déjà `$collectionId`, autre que
     * `$exceptThemeId` ; `null` si aucun.
     */
    public static function collectionTakenBy(int $collectionId, ?int $exceptThemeId = null): ?string
    {
        $key = Theme::query()
            ->where('theme_kind', ThemeKind::Saga)
            ->where('rule_value', (string) $collectionId)
            ->when($exceptThemeId !== null, fn ($query) => $query->whereKeyNot($exceptThemeId))
            ->orderBy('id')
            ->value('key');

        return is_string($key) ? $key : null;
    }

    /**
     * La clé du premier thème studio qui désigne déjà l'une des sociétés,
     * autre que `$exceptThemeId` ; `null` si aucun.
     *
     * @param  list<int>  $companyIds
     */
    public static function companiesTakenBy(array $companyIds, ?int $exceptThemeId = null): ?string
    {
        foreach (self::studioRules($exceptThemeId) as $key => $ids) {
            if (array_intersect($companyIds, $ids) !== []) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Société → clé du thème studio qui la désigne.
     *
     * @return array<int, string>
     */
    public static function companyOwners(): array
    {
        $owners = [];

        foreach (self::studioRules(null) as $key => $ids) {
            foreach ($ids as $id) {
                $owners[$id] ??= $key;
            }
        }

        return $owners;
    }

    /**
     * Collection → clé du thème de saga qui la désigne.
     *
     * @return array<int, string>
     */
    public static function collectionOwners(): array
    {
        $owners = [];

        $rows = Theme::query()
            ->where('theme_kind', ThemeKind::Saga)
            ->whereNotNull('rule_value')
            ->orderBy('id')
            ->get(['key', 'rule_value']);

        foreach ($rows as $theme) {
            if ($theme->rule_value !== null && ctype_digit($theme->rule_value)) {
                $owners[(int) $theme->rule_value] ??= $theme->key;
            }
        }

        return $owners;
    }

    /**
     * Clé → identifiants de société de chaque thème studio à règle.
     *
     * @return array<string, list<int>>
     */
    private static function studioRules(?int $exceptThemeId): array
    {
        $rules = [];

        $rows = Theme::query()
            ->where('theme_kind', ThemeKind::Studio)
            ->whereNotNull('rule_value')
            ->when($exceptThemeId !== null, fn ($query) => $query->whereKeyNot($exceptThemeId))
            ->orderBy('id')
            ->get(['key', 'rule_value']);

        foreach ($rows as $theme) {
            $rules[$theme->key] = ThemeRules::studioCompanyIds($theme->rule_value);
        }

        return $rules;
    }
}
