<?php

namespace App\Jobs\Retention;

use App\Support\Retention\RetentionPurger;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * La purge de rétention quotidienne — spec 100 § 10.7 et § 14.
 *
 * Planifiée chaque jour à `config('ops.purge.daily_at')` (UTC), avant
 * l'élagage des instantanés et le tier chaud de sauvegarde. **File `default`,
 * jamais `game`** (10 § 12) : une purge qui borne ses lots peut quand même
 * tenir la file plusieurs minutes, et rien de ce qui dure ne partage la file
 * du temps réel. Tout le travail est fait par {@see RetentionPurger}, que
 * `purge:run --sync` appelle aussi (restauration, contrôle manuel).
 *
 * `ShouldBeUnique` : deux exécutions ne se chevauchent jamais depuis la
 * file. Un seul essai : la purge est idempotente et la nuit suivante la
 * rejoue ; un réessai immédiat buterait sur la même panne. `$uniqueFor`
 * borne le verrou d'unicité — un worker tué par son plafond de mémoire
 * n'appelle jamais `failed()` et ne doit pas bloquer la purge des nuits
 * suivantes.
 *
 * `$timeout` : avec `pcntl` (production), `$timeout = 900` l'emporte sur le
 * `--timeout=120` du worker `default` (spec 100 § 10.4) et reste sous
 * `REDIS_QUEUE_RETRY_AFTER` = 960 (§ 10.3) ; sans `pcntl` (poste Windows,
 * CLAUDE.md § 8), aucune borne ne s'applique, ni celle-ci ni celle du
 * worker. Une purge qui tient la file `default` plus de
 * `ops.heartbeat.default_stale_seconds` (600 s) met la sonde
 * `worker-default` en alerte (§ 15) : comme pour un import, c'est un
 * incident à voir avant la borne de 900 s.
 */
final class RunRetentionPurge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** La file du job, et la seule : jamais `game`. */
    public const string QUEUE = 'default';

    public int $tries = 1;

    public int $timeout = 900;

    /** Une heure : bien au-delà d'une exécution, bien en deçà de la suivante. */
    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(self::QUEUE);
    }

    public function handle(RetentionPurger $purger): void
    {
        $purger->run();
    }
}
