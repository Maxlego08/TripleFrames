<?php

namespace App\Support\Admin;

use App\Enums\ThemeKind;
use Illuminate\Support\Str;

/**
 * La clé d'un thème créé au back-office (spec 20 § 9.6, D43 du 01/10).
 *
 * `<nature>.` suivie de `Str::slug()` du libellé **anglais** (`en`, locale de
 * repli de l'instance, spec 05), article initial (`The`, `A`, `An`) retiré,
 * au plus {@see self::MAX_LENGTH} caractères — la convention des clés livrées
 * par le seeder de plateforme (`saga.lord-of-the-rings`,
 * `saga.back-to-the-future`), pour que l'écran et le seeder nomment une même
 * saga de la même façon.
 *
 * La clé est **immuable après création** : les préréglages et les
 * présentateurs s'y réfèrent. Son unicité (`theme_key_uq`) est gardée par
 * l'action, pas ici.
 */
final class ThemeKeyGenerator
{
    /** Largeur de `theme.key` (spec 10 § 3.7). */
    public const int MAX_LENGTH = 64;

    /** Les articles anglais retirés en tête du libellé. */
    private const string LEADING_ARTICLE = '/^(?:the|an?)\s+/i';

    /**
     * La clé dérivée, ou `null` quand le libellé ne laisse aucun caractère
     * utilisable (ponctuation seule) : l'action refuse alors en erreur
     * traduite, jamais une clé réduite à son préfixe.
     */
    public static function for(ThemeKind $kind, string $englishLabel): ?string
    {
        $label = preg_replace(self::LEADING_ARTICLE, '', trim($englishLabel)) ?? '';
        $slug = Str::slug($label);

        if ($slug === '') {
            return null;
        }

        $prefix = $kind->value.'.';

        return rtrim(substr($prefix.$slug, 0, self::MAX_LENGTH), '-');
    }
}
