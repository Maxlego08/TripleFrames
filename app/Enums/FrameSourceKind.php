<?php

namespace App\Enums;

/** Source déclarée d'une image, qui pilote le périmètre du tier froid de sauvegarde : cast de `frame.source_kind` et `frame_review.declared_source_kind`. */
enum FrameSourceKind: string
{
    case Tmdb = 'tmdb';

    case Capture = 'capture';
}
