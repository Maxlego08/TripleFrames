<?php

namespace App\Enums;

/** Verdict du filtre de contenu, jamais contournable et présent dans le prédicat de vivier : cast de `movie.content_flag`. */
enum ContentFlag: string
{
    case Clear = 'clear';

    case Blocked = 'blocked';

    case UnratedPending = 'unrated_pending';
}
