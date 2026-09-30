<?php

namespace App\Support\Retention;

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Support\Ops\PurgeProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Le moteur de purge de rétention — spec 100 § 14, 10 § 11.
 *
 * Il exécute, **dans l'ordre du tableau de 10** (ordre des cas de
 * {@see PurgeScope}), chaque périmètre de `PurgeScope::implemented()`, et
 * lui seul : un gestionnaire étiqueté pour un autre périmètre n'est jamais
 * appelé — `stale_lobby` compris, exécuté par le balayage de 50.
 *
 * Pour chaque périmètre :
 *
 * - **une ligne `purge_run`**, écrite `running` avant le premier lot puis
 *   close `completed` ou `failed`, même sans ligne éligible : `rows_deleted
 *   = 0` est une information, l'absence de ligne est une panne. `ran_at` est
 *   l'instant de l'exécution, commun à tous ses périmètres ; `started_at`
 *   celui du périmètre, qui fixe aussi la borne d'éligibilité ;
 * - **un seul gestionnaire** : aucun, ou plusieurs, et le périmètre n'est pas
 *   exécuté (ligne `failed`) ;
 * - **lots bornés**, du plus ancien au plus récent : au plus
 *   `ops.purge.batch_size` lignes par lot et `ops.purge.max_batches` lots par
 *   exécution ; le reste est le travail de la nuit suivante ;
 * - **résilient ligne à ligne** : une transaction par ligne ; une ligne qui
 *   échoue est comptée, sa transaction seule est annulée, et le curseur passe
 *   à la suivante — jamais un lot entier annulé, jamais la même ligne
 *   resélectionnée. Un périmètre dont des lignes ont échoué est `completed`
 *   avec le compte d'échecs dans `error` : c'est la sonde `purge` qui voit un
 *   périmètre bloqué, pas le statut — en entier par la sonde n° 4 (rien
 *   supprimé en 48 h alors que des lignes restent éligibles), en partie par
 *   la lecture de `error` sur la dernière exécution terminée
 *   ({@see PurgeProbe}) ;
 * - **suspension** ({@see PurgeSuspension}) relue avant chaque périmètre et
 *   chaque lot : suspendue avant de commencer, l'exécution n'écrit rien ;
 *   suspendue en cours, le périmètre courant est clos `failed` et les
 *   suivants ne partent pas.
 *
 * **Aucune donnée personnelle dans `purge_run` ni au journal** : une
 * exception de requête porte dans son message la clé de la ligne (une
 * adresse électronique pour un jeton de réinitialisation, l'identifiant d'une
 * session). Seuls sa classe et son code sont consignés
 * ({@see PurgeRun::describeFailure()}), et les largeurs de `error` et `batches`
 * se lisent sur le modèle : le balayage `stale_lobby` de `50` écrit le même
 * journal, avec les mêmes règles.
 */
final readonly class RetentionPurger
{
    public function __construct(
        private PurgeHandlers $handlers,
        private PurgeSuspension $suspension,
    ) {}

    /**
     * Une exécution : une ligne `purge_run` par périmètre implémenté, dans
     * l'ordre du tableau de 10 ; aucune si la purge est suspendue.
     *
     * @return list<PurgeRun>
     */
    public function run(): array
    {
        $ranAt = CarbonImmutable::now();
        $handlers = $this->handlers->byScope();
        $runs = [];

        foreach (PurgeScope::implemented() as $scope) {
            if ($this->suspension->isSuspended()) {
                Log::notice('Purge de rétention suspendue : périmètres restants non exécutés.', [
                    'remaining_from' => $scope->value,
                ]);

                break;
            }

            $runs[] = $this->runScope($scope, $handlers[$scope->value] ?? [], $ranAt);
        }

        return $runs;
    }

    /**
     * @param  list<PurgeHandler>  $handlers
     */
    private function runScope(PurgeScope $scope, array $handlers, CarbonImmutable $ranAt): PurgeRun
    {
        $now = CarbonImmutable::now();
        $clock = hrtime(true);

        $run = new PurgeRun;
        $run->forceFill([
            'scope' => $scope,
            'status' => PurgeRunStatus::Running,
            'started_at' => $now,
            'ran_at' => $ranAt,
        ])->save();

        if (count($handlers) !== 1) {
            Log::error('Purge de rétention : périmètre non exécuté, gestionnaire absent ou multiple.', [
                'scope' => $scope->value,
                'handlers' => count($handlers),
            ]);

            return $this->close($run, $clock, PurgeRunStatus::Failed, 0, 0, $handlers === []
                ? 'Aucun gestionnaire pour ce périmètre : non exécuté.'
                : count($handlers).' gestionnaires pour ce périmètre : non exécuté.');
        }

        $handler = $handlers[0];
        $batchSize = max(1, Config::integer('ops.purge.batch_size'));
        $maxBatches = min(PurgeRun::MAX_BATCHES, max(1, Config::integer('ops.purge.max_batches')));

        $deleted = 0;
        $batches = 0;
        $rowFailures = 0;
        $lastRowFailure = null;
        $after = null;

        try {
            while ($batches < $maxBatches) {
                if ($this->suspension->isSuspended()) {
                    Log::notice('Purge de rétention suspendue en cours d’exécution.', ['scope' => $scope->value]);

                    return $this->close($run, $clock, PurgeRunStatus::Failed, $deleted, $batches, 'Purge suspendue en cours d’exécution.');
                }

                $rows = $handler->nextBatch($now, $after, $batchSize);

                if ($rows === []) {
                    break;
                }

                $batches++;

                foreach ($rows as $row) {
                    $after = $row;

                    try {
                        $deleted += $handler->connection()->transaction(
                            static fn (): int => $handler->purge($row, $now),
                        );
                    } catch (Throwable $failure) {
                        $rowFailures++;
                        $lastRowFailure = $failure;
                    }
                }

                if (count($rows) < $batchSize) {
                    break;
                }
            }
        } catch (Throwable $failure) {
            Log::error('Purge de rétention : périmètre interrompu.', [
                'scope' => $scope->value,
                ...PurgeRun::describeFailure($failure),
            ]);

            return $this->close(
                $run,
                $clock,
                PurgeRunStatus::Failed,
                $deleted,
                $batches,
                'Périmètre interrompu : '.PurgeRun::summarizeFailure($failure),
            );
        }

        if ($lastRowFailure !== null) {
            Log::warning('Purge de rétention : lignes en échec, reprises à la ligne suivante.', [
                'scope' => $scope->value,
                'failures' => $rowFailures,
                ...PurgeRun::describeFailure($lastRowFailure),
            ]);
        }

        return $this->close(
            $run,
            $clock,
            PurgeRunStatus::Completed,
            $deleted,
            $batches,
            $lastRowFailure === null ? null : "{$rowFailures} ligne(s) en échec ; dernière : ".PurgeRun::summarizeFailure($lastRowFailure),
        );
    }

    /**
     * Clôt la ligne `purge_run`. `finished_at` et `duration_ms` restent nuls
     * sur un échec : NULL signifie « en cours OU plantée » (10 § 11.3).
     */
    private function close(PurgeRun $run, int $clock, PurgeRunStatus $status, int $deleted, int $batches, ?string $error): PurgeRun
    {
        $completed = $status === PurgeRunStatus::Completed;

        $run->forceFill([
            'status' => $status,
            'finished_at' => $completed ? CarbonImmutable::now() : null,
            'duration_ms' => $completed ? intdiv(hrtime(true) - $clock, 1_000_000) : null,
            'rows_deleted' => $deleted,
            'batches' => $batches,
            'error' => $error === null ? null : mb_substr($error, 0, PurgeRun::ERROR_LENGTH),
        ])->save();

        return $run;
    }
}
