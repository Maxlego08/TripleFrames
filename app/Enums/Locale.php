<?php

namespace App\Enums;

/** Registre fermé des locales d'interface activées : cast de `users.locale`, `player.locale`, `round_player.choices_locale`, `round_choice_set.locale`, `takedown_request.locale` et `theme_label.locale` — jamais des locales de catalogue `string(12)`. */
enum Locale: string
{
    /**
     * Version de la disposition des bits de `movie_projection.title_locale_mask`.
     * À incrémenter à tout changement de l'ensemble des locales activées ; tant que
     * `catalog:reproject` n'a pas tourné, aucune ligne n'apparie la version courante.
     */
    public const int MASK_VERSION = 1;

    case English = 'en';

    case French = 'fr';

    /** Libellé de la langue dans sa propre langue, jamais traduit ni passé par `__()`. */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::English => 'English',
            self::French => 'Français',
        };
    }

    public function bcp47(): string
    {
        return match ($this) {
            self::English => 'en',
            self::French => 'fr',
        };
    }

    public function direction(): string
    {
        return match ($this) {
            self::English, self::French => 'ltr',
        };
    }

    /** Rang de repli d'affichage déclaré par l'instance : `en` puis `fr`. Sans effet sur la négociation. */
    public function fallbackRank(): int
    {
        return match ($this) {
            self::English => 1,
            self::French => 2,
        };
    }

    /** Bit de cette locale dans `movie_projection.title_locale_mask`, dans l'ordre ordinal de déclaration. */
    public function maskBit(): int
    {
        return match ($this) {
            self::English => 1 << 0,
            self::French => 1 << 1,
        };
    }
}
