<?php

namespace App\Support\Ops;

use Illuminate\Support\Facades\Config;

/**
 * La sonde `load` — spec 100 § 15.
 *
 * En alerte si la charge moyenne sur 5 minutes dépasse `ops.load.max_per_cpu`
 * × vCPU, ou si la mémoire disponible passe sous
 * `ops.load.min_available_percent` %. La machine est partagée avec d'autres
 * sites : c'est ce qui dit « est-ce que ça casse les autres ? ».
 *
 * - vCPU : `ops.load.cpu_count` s'il est posé (relevé du VPS), sinon compté
 *   dans `/proc/cpuinfo`.
 * - **Une mesure qui ne se lit pas n'est pas verte** : sans charge ni nombre
 *   de vCPU, la sonde est en alerte. Seule la mémoire est facultative, comme
 *   le dit le § 15 : si `/proc/meminfo` est hors de l'`open_basedir`, la
 *   sonde ne mesure que la charge et le relevé le consigne.
 */
final readonly class LoadProbe
{
    public function __construct(private SystemLoad $system) {}

    /**
     * @return list<string>
     */
    public function failures(): array
    {
        $failures = [];
        $load = $this->system->fiveMinuteLoad();
        $configured = Config::integer('ops.load.cpu_count');
        $cpus = $configured > 0 ? $configured : $this->system->cpuCount();
        $maxPerCpu = Config::float('ops.load.max_per_cpu');

        if ($load === null) {
            $failures[] = 'charge illisible';
        } elseif ($cpus === null) {
            $failures[] = 'nombre de vCPU illisible';
        } elseif ($load > $maxPerCpu * $cpus) {
            $failures[] = sprintf('charge %.2f au-delà de %.2f × %d vCPU', $load, $maxPerCpu, $cpus);
        }

        $memory = $this->system->availableMemoryPercent();
        $floor = Config::float('ops.load.min_available_percent');

        if ($memory !== null && $memory < $floor) {
            $failures[] = sprintf('mémoire disponible %.1f %% sous %.1f %%', $memory, $floor);
        }

        return $failures;
    }
}
