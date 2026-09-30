<?php

namespace App\Support\Ops;

use App\Enums\PurgeRunStatus;
use App\Enums\PurgeScope;
use App\Models\PurgeRun;
use App\Support\Retention\PurgeHandler;
use App\Support\Retention\PurgeHandlers;
use App\Support\Retention\PurgeSuspension;
use Carbon\CarbonImmutable;
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
 *    exécution aurait dû supprimer et qui existe encore ;
 * 3. **périmètre bloqué en partie** : la dernière exécution terminée a des
 *    lignes en échec (`error` non nul) et des lignes éligibles à son début
 *    existent encore — même si d'autres ont été supprimées. Sans cette
 *    règle, une ligne qui échoue chaque nuit pendant que ses voisines
 *    partent survivrait à sa durée de conservation sans aucune alerte : la
 *    sonde n° 4 ne voit que le périmètre bloqué EN ENTIER. Un échec
 *    passager s'efface à la nuit suivante, quand `error` redevient nul.
 *
 * Périmètres surveillés : ceux de `PurgeScope::implemented()` ET ceux des
 * gestionnaires étiquetés {@see PurgeHandler} ({@see PurgeHandlers}). Un
 * périmètre sans gestionnaire, ou servi par deux, est en alerte. S'y ajoute
 * `stale_lobby`, pour le seul point 1 (paragraphe `stale_lobby` ci-dessous).
 *
 * **Suspension** (`purge:suspend`, {@see PurgeSuspension}) : tant que le
 * drapeau existe, la sonde est en alerte — l'interrupteur d'incident
 * déclenche l'alerte au lieu de la masquer (spec 100 § 14).
 *
 * **`stale_lobby`** (spec 100 § 15, 50 § 16.2) : ce périmètre n'est jamais
 * exécuté par le moteur, mais par le balayage `room:archive-idle` de 50, qui
 * écrit sa ligne `purge_run` à chaque passage, même à zéro salon archivé. La
 * sonde n'en lit que la **fraîcheur** — une ligne `completed` commencée depuis
 * moins de `ops.purge.stale_hours` heures —, jamais la sonde n° 4, réservée
 * aux périmètres de `PurgeScope::implemented()` : un balayage qui tourne sans
 * archiver ce qu'il devrait se voit par la sonde n° 2 d'`integrity`. Un
 * gestionnaire étiqueté pour `stale_lobby` est en alerte : il ferait un second
 * exécutant d'un périmètre que 50 exécute seul, et le moteur ne l'appellerait
 * jamais.
 */
final readonly class PurgeProbe
{
    public function __construct(
        private PurgeHandlers $handlers,
        private PurgeSuspension $suspension,
    ) {}

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

        if ($this->suspension->isSuspended()) {
            $failures[] = 'purge suspendue';
        }

        foreach ($this->handlers->byScope() as $scope => $served) {
            if (count($served) > 1) {
                $failures[] = "{$scope} : servi par plusieurs gestionnaires";
            }

            $handlers[$scope] = $served[0];
        }

        $swept = PurgeScope::StaleLobby->value;

        if (isset($handlers[$swept])) {
            $failures[] = "{$swept} : gestionnaire déclaré pour un périmètre confié au balayage de 50";

            unset($handlers[$swept]);
        }

        if (! $this->completedRuns($swept, $since)->exists()) {
            $failures[] = "{$swept} : aucun passage terminé du balayage depuis {$staleHours} h";
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

            $advanced = $this->completedRuns($scope, $since)->where('rows_deleted', '>', 0)->exists();

            // Une exécution terminée n'écrit `error` que pour ses lignes en échec.
            if ($advanced && $lastCompleted->error === null) {
                continue;
            }

            if ($handler->eligibleCount($lastCompleted->started_at) > 0) {
                $failures[] = $advanced
                    ? "{$scope} : lignes en échec à la dernière exécution alors que des lignes restent éligibles"
                    : "{$scope} : rien supprimé en {$staleHours} h alors que des lignes sont éligibles";
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
