<?php

namespace App\Enums;

/** Discriminant d'un thème, qui détermine l'interprétation de `theme.rule_value` : cast de `theme.theme_kind`. */
enum ThemeKind: string
{
    /** Largeur d'un bloc de famille dans `theme.sort_order` (spec 30 § 12.5). */
    public const int SORT_BLOCK_WIDTH = 100;

    /** `rule_value` = un `movie_tmdb_tag.tmdb_tag_id` de nature `genre`. */
    case Genre = 'genre';

    /** `rule_value` = l'année de début de la décennie. */
    case Decade = 'decade';

    /** `rule_value` = un `movie_tmdb_tag.tmdb_tag_id` de nature `company`. */
    case Studio = 'studio';

    /** `rule_value` = un `collection.id`. */
    case Saga = 'saga';

    /** `rule_value` = un code de langue comparé à `movie.original_language`. */
    case Language = 'language';

    /** `rule_value` = une valeur de `MovieDifficulty`. */
    case Difficulty = 'difficulty';

    /**
     * Le bloc de la famille dans `theme.sort_order` (spec 30 § 12.5) :
     * `bloc × 100 + rang`. Lu par le seeder de plateforme pour les thèmes
     * livrés et par l'écran des thèmes pour ranger un thème créé en fin de
     * bloc de sa nature (spec 20 § 9.6) — une seule table, ici.
     */
    public function sortBlock(): int
    {
        return match ($this) {
            self::Genre => 1,
            self::Studio => 2,
            self::Decade => 3,
            self::Language => 4,
            self::Difficulty => 5,
            self::Saga => 6,
        };
    }

    /**
     * Vrai pour les cinq natures qu'un curateur crée au back-office ; les
     * cinq thèmes de difficulté sont livrés seuls (spec 20 § 9.6).
     */
    public function isCreatable(): bool
    {
        return $this !== self::Difficulty;
    }
}
