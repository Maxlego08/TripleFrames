<?php

namespace App\Enums;

/** Nature d'un balayage d'import — ni le même filtre, ni le même droit d'écrasement, ni la même conséquence sur `is_import_exception` : cast de `import_run.run_kind`. */
enum ImportRunKind: string
{
    case Discover = 'discover';

    case Paste = 'paste';

    case Resync = 'resync';
}
