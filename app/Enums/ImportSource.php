<?php

namespace App\Enums;

/** Voie d'entrée d'un film au catalogue : cast de `movie.import_source`. */
enum ImportSource: string
{
    case Discover = 'discover';

    case Paste = 'paste';

    case Demo = 'demo';

    /** `demo` exclut définitivement le film de la resynchronisation TMDB et des statistiques de débit. */
    public function isResyncable(): bool
    {
        return $this !== self::Demo;
    }
}
