<?php

namespace App\Enums;

/** Difficulté d'un film, à cas nommés et jamais un entier 1-5 : cast de `movie.movie_difficulty`, `movie.movie_difficulty_derived` et `movie.movie_difficulty_override`. */
enum MovieDifficulty: string
{
    case VeryEasy = 'very_easy';

    case Easy = 'easy';

    case Medium = 'medium';

    case Hard = 'hard';

    case VeryHard = 'very_hard';
}
