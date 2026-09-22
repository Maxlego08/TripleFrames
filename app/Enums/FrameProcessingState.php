<?php

namespace App\Enums;

/** État du job d'image différé, `ready` étant l'une des trois conditions du prédicat de variante jouable : cast de `frame.processing_state`. */
enum FrameProcessingState: string
{
    case Pending = 'pending';

    case Ready = 'ready';

    case Failed = 'failed';
}
