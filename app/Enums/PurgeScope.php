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

    /**
     * Les périmètres exécutés par `RetentionPurger` (spec 100 § 14), dans
     * l'ordre du tableau de 10 § 11.1 — **déclarés dans le code et jamais en
     * configuration** : un périmètre ne se désactive pas en silence par une
     * variable. `StaleLobby` n'y entre jamais : il est exécuté par le
     * balayage de 50, qui écrit lui-même sa ligne `purge_run`.
     *
     * La sonde `purge` (spec 100 § 15) surveille chacun d'eux, et chacun a
     * exactement un gestionnaire dans `App\Support\Retention\PurgeHandlers`.
     *
     * **Premier temps de L100-8** (D37 du 23/09) : les seuls périmètres sans
     * jeu, qui ne dépendent d'aucun lot de 50 ni de 60. `StaleRoom` entre avec
     * `ArchiveRoom` de 50 (L50-8), la branche sièges solo d'`OrphanPlayer`
     * avec le démarrage solo de 60 (L60-15), chacun à sa place dans l'ordre
     * du tableau.
     *
     * @return list<self>
     */
    public static function implemented(): array
    {
        return [
            self::FrameworkSessions,
            self::FrameworkFailedJobs,
            self::FrameworkResetTokens,
            self::PurgeRun,
        ];
    }
}
