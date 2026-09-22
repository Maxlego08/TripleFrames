<?php

namespace App\Enums;

/** État d'un balayage d'import, filtré par l'index `(status)` qui retrouve un balayage à reprendre : cast de `import_run.status`. */
enum ImportRunStatus: string
{
    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';
}
