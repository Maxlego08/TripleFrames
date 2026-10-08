<?php

namespace App\Support;

use App\Enums\Locale;
use Carbon\CarbonInterface;

/**
 * Mise en forme serveur des nombres et des dates, sans `ext-intl` (spec 05
 * § Nombres, dates et durées, point 2 ; lots 05-O et 05-P).
 *
 * **Seulement là où aucun client ne rend le texte** : corps d'e-mail et
 * archive d'export de données (J2). Un écran — jeu comme back-office — reçoit
 * toujours des entiers, des instants ISO-8601 UTC et des millisecondes, et
 * formate lui-même avec `Intl` (point 1) ; le back-office passe par
 * `resources/js/lib/admin-format.ts`, jamais par cette classe (garde :
 * `tests/Feature/I18n/FormatTest.php`).
 *
 * `Number::format()` lève sans `ext-intl` : les séparateurs viennent du
 * registre `App\Enums\Locale`, appliqués par `number_format()` ; les dates
 * passent par `CarbonInterface::translatedFormat()`, dont les noms de mois
 * sont ceux des traductions de Carbon, sans `ext-intl` non plus.
 *
 * Locale omise : celle de l'application au moment de l'appel — un e-mail
 * en file l'a déjà réglée par `HasLocalePreference` —, avec repli sur
 * `app.fallback_locale` puis sur l'anglais si elle n'est pas activée.
 */
final class Format
{
    /** Entier ou décimal à `$decimals` chiffres : « 12 345,6 » en français, « 12,345.6 » en anglais. */
    public static function number(int|float $value, int $decimals = 0, ?Locale $locale = null): string
    {
        $locale ??= self::locale();

        $formatted = number_format(
            $value,
            max(0, $decimals),
            $locale->decimalSeparator(),
            $locale->thousandsSeparator(),
        );

        // `number_format(-0.04, 1)` rend « -0,0 » : un zéro n'a pas de signe.
        return preg_match('/^-[0.,\x{00A0}]+$/u', $formatted) === 1
            ? substr($formatted, 1)
            : $formatted;
    }

    /**
     * Durée en secondes depuis des millisecondes (la forme des durées en
     * données) : « 12,4 s ». L'unité, symbole SI, est la même dans les deux
     * langues, séparée par une espace insécable.
     */
    public static function seconds(int $milliseconds, int $decimals = 1, ?Locale $locale = null): string
    {
        return self::number($milliseconds / 1000, $decimals, $locale)."\u{00A0}s";
    }

    /** Date seule : « 7 octobre 2026 », « October 7, 2026 ». */
    public static function date(CarbonInterface $date, ?Locale $locale = null): string
    {
        $locale ??= self::locale();

        return $date->copy()->locale($locale->value)->translatedFormat($locale->datePattern());
    }

    /**
     * Instant, fuseau affiché (aucun fuseau joueur n'est connu du serveur :
     * celui de l'instant est dit, jamais tu) : « 7 octobre 2026 à 14:05 UTC ».
     */
    public static function dateTime(CarbonInterface $instant, ?Locale $locale = null): string
    {
        $locale ??= self::locale();

        return $instant->copy()->locale($locale->value)->translatedFormat($locale->dateTimePattern());
    }

    private static function locale(): Locale
    {
        $fallback = config('app.fallback_locale');

        return Locale::tryFrom(app()->getLocale())
            ?? (is_string($fallback) ? Locale::tryFrom($fallback) : null)
            ?? Locale::English;
    }
}
