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

    // CONTRADICTION NON ARBITRÉE, à trancher par le porteur du projet avant la première
    // exécution de purge : `purge_run.scope` est déclarée string(20) par la migration
    // 2026_09_22_100037, or les deux valeurs ci-dessous font 21 et 22 caractères. MySQL strict
    // lèverait 1406 à l'insertion, SQLite accepterait en silence. Les valeurs sont laissées
    // telles que le tableau § 11.1 de la spec 10 les écrit ; les trois issues possibles sont
    // de raccourcir ces deux cas, de porter la colonne à string(24) par migration additive,
    // ou de journaliser ces périmètres sous un scope agrégé.
    case FrameworkFailedJobs = 'framework_failed_jobs';

    case FrameworkResetTokens = 'framework_reset_tokens';

    case PurgeRun = 'purge_run';
}
