<?php

namespace App\Enums;

/** État d'une exécution de purge, sonde de la seule panne du projet dont la conséquence est juridique : cast de `purge_run.status`. */
enum PurgeRunStatus: string
{
    case Running = 'running';

    case Completed = 'completed';

    case Failed = 'failed';
}
