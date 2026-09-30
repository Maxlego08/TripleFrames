<?php

namespace App\Enums;

/** Nature d'une étiquette TMDB brute, seul support local des règles de genre et de studio : cast de `movie_tmdb_tag.tag_kind`. */
enum TmdbTagKind: string
{
    case Genre = 'genre';

    case Company = 'company';
}
