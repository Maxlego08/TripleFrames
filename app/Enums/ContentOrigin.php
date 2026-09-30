<?php

namespace App\Enums;

/** Provenance d'une ligne de contenu, une ligne `curator` n'étant jamais réécrite par un réimport : cast de `movie_title.origin` et `alias.origin`. */
enum ContentOrigin: string
{
    case Tmdb = 'tmdb';

    case Curator = 'curator';
}
