<?php

namespace App\Enums;

/** Discriminant d'un thème, qui détermine l'interprétation de `theme.rule_value` : cast de `theme.theme_kind`. */
enum ThemeKind: string
{
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
}
