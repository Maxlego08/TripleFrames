<?php

namespace App\Support\Ops;

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Support\Retention\PurgeHandler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * La sonde `purge` — spec 100 § 15, sonde n° 4 de 10 § 11.3.
 *
 * Pour chaque périmètre surveillé :
 *
 * 1. une ligne `purge_run` de statut `completed` commencée depuis moins de
 *    `ops.purge.stale_hours` heures — l'absence de ligne est une panne, et
 *    une ligne `running` ou `failed` n'en tient pas lieu ;
 * 2. **sonde n° 4** : aucune exécution terminée à `rows_deleted > 0` sur la
 *    même fenêtre alors que des lignes restent éligibles. La sonde qui ne
 *    lirait que la fraîcheur resterait verte sur un périmètre bloqué qui
 *    tourne chaque nuit sans rien supprimer — la panne typique d'un lot qui
 *    bute sur un `restrict`. L'éligibilité se lit au début de la dernière
 *    exécution terminée ({@see PurgeHandler::eligibleCount()}) : ce que cette
 *    exécution aurait dû supprimer et qui existe encore.
 *
 * Périmètres surveillés : ceux de `PurgeScope::implemented()` ET ceux des
 * gestionnaires étiquetés {@see PurgeHandler} dans le conteneur. Un périmètre
 * sans gestionnaire, ou servi par deux, est en alerte.
 *
 * **Premier temps de L100-7** (D37 du 23/09) : ni la vérification
 * `stale_lobby` (ligne écrite par le balayage de 50, branchée avec L50-8 —
 * elle serait sinon en alerte permanente), ni le drapeau de suspension de
 * `purge:suspend` (L100-8). Tant qu'aucun périmètre n'est implémenté, la
 * sonde n'a rien à surveiller.
 */
final readonly class PurgeProbe
{
    public function __construct(private Container $container) {}

    /**
     * Motifs d'alerte, vides si la purge est saine à l'instant `$now`.
     *
     * @return list<string>
     */
    public function failures(CarbonImmutable $now): array
    {
        $staleHours = Config::integer('ops.purge.stale_hours');
        $since = $now->subHours($staleHours);
        $failures = [];
        $handlers = [];

        foreach ($this->container->tagged(PurgeHandler::class) as $handler) {
            if (! $handler instanceof PurgeHandler) {
                continue;
            }

            $scope = $handler->scope()->value;

            if (array_key_exists($scope, $handlers)) {
                $failures[] = "{$scope} : servi par plusieurs gestionnaires";
            }

            $handlers[$scope] = $handler;
        }

        $watched = array_unique([
            ...array_map(static fn (PurgeScope $scope): string => $scope->value, PurgeScope::implemented()),
            ...array_keys($handlers),
        ]);

        foreach ($watched as $scope) {
            $handler = $handlers[$scope] ?? null;

            if ($handler === null) {
                $failures[] = "{$scope} : aucun gestionnaire";

                continue;
            }

            $lastCompleted = $this->completedRuns($scope, $since)->latest('started_at')->first();

            if ($lastCompleted === null) {
                $failures[] = "{$scope} : aucune exécution terminée depuis {$staleHours} h";

                continue;
            }

            if ($this->completedRuns($scope, $since)->where('rows_deleted', '>', 0)->exists()) {
                continue;
            }

            if ($handler->eligibleCount($lastCompleted->started_at) > 0) {
                $failures[] = "{$scope} : rien supprimé en {$staleHours} h alors que des lignes sont éligibles";
            }
        }

        return $failures;
    }

    /**
     * @return Builder<PurgeRun>
     */
    private function completedRuns(string $scope, CarbonImmutable $since): Builder
    {
        return PurgeRun::query()
            ->where('scope', $scope)
            ->where('status', PurgeRunStatus::Completed->value)
            ->where('started_at', '>=', $since);
    }
}
