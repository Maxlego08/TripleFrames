<?php

namespace App\Enums;

/** Périmètre d'une exécution de purge, repris un à un du tableau de rétention : cast de `purge_run.scope`. */
enum PurgeScope: string
{
    case GameFacts = 'game_facts';

    case StaleGame = 'stale_game';

    case StaleRoom = 'stale_room';

    case StaleLobby = 'stale_lobby';

    case OrphanPlayer = 'orphan_player';

    case SeenFrame = 'seen_frame';

    case NearMiss = 'near_miss';

    case Report = 'report';

    case AdminAction = 'admin_action';

    case DataExport = 'data_export';

    case TakedownIdentity = 'takedown_identity';

    case WithdrawnFiles = 'withdrawn_files';

    case DormantAccount = 'dormant_account';

    case FrameworkSessions = 'framework_sessions';

    // Les deux valeurs ci-dessous font 21 et 22 caractères : c'est pour elles que
    // `purge_run.scope` est `string(32)` et non 20 (arbitré le 22/09, spec § 11.3).
    // MySQL strict aurait levé 1406 à l'insertion là où SQLite tronque en silence.
    // Elles restent écrites telles que le tableau § 11.1 de la spec 10 les fixe.
    case FrameworkFailedJobs = 'framework_failed_jobs';

    case FrameworkResetTokens = 'framework_reset_tokens';

    case PurgeRun = 'purge_run';
}
