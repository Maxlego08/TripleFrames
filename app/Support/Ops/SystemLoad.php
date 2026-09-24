<?php

namespace App\Support\Ops;

use Closure;

/**
 * Les mesures de la machine que lit la sonde `load` — spec 100 § 15.
 *
 * - Charge moyenne sur 5 minutes : `sys_getloadavg()`, qui lit le noyau sans
 *   passer par l'`open_basedir` de PHP-FPM. Absente sous Windows : `null`.
 * - Nombre de vCPU : lignes `processor` de `/proc/cpuinfo`.
 * - Mémoire disponible : `MemAvailable` ÷ `MemTotal` de `/proc/meminfo`.
 *
 * Les deux fichiers de `/proc` supposent que l'`open_basedir` de l'abonnement
 * les admette **[à confirmer au relevé du VPS]**. Une lecture refusée rend
 * `null`, jamais une exception : l'avertissement est tu par `@` (le
 * gestionnaire d'erreurs de Laravel ne lève que ce que `error_reporting()`
 * garde), et la sonde décide seule de ce que vaut une mesure absente.
 *
 * Les sources sont injectées pour que les tests ne dépendent ni du système
 * du poste ni de celui de la CI ; le conteneur lie {@see self::fromHost()}.
 */
final readonly class SystemLoad
{
    /**
     * @param  Closure(): (array<int, float>|false)  $loadAverages  les trois moyennes (1, 5, 15 min)
     */
    public function __construct(
        private Closure $loadAverages,
        private string $meminfoPath,
        private string $cpuinfoPath,
    ) {}

    /** Les sources réelles de la machine. */
    public static function fromHost(): self
    {
        return new self(
            static fn (): array|false => function_exists('sys_getloadavg') ? sys_getloadavg() : false,
            '/proc/meminfo',
            '/proc/cpuinfo',
        );
    }

    /** Charge moyenne sur 5 minutes, `null` si illisible. */
    public function fiveMinuteLoad(): ?float
    {
        $averages = ($this->loadAverages)();

        if ($averages === false || ! isset($averages[1])) {
            return null;
        }

        return $averages[1];
    }

    /** Nombre de processeurs logiques, `null` si illisible. */
    public function cpuCount(): ?int
    {
        $cpuinfo = self::read($this->cpuinfoPath);

        if ($cpuinfo === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', $cpuinfo);

        return is_int($count) && $count > 0 ? $count : null;
    }

    /** Part de la mémoire disponible, en pour cent, `null` si illisible. */
    public function availableMemoryPercent(): ?float
    {
        $meminfo = self::read($this->meminfoPath);

        if ($meminfo === null
            || preg_match('/^MemTotal:\s+(\d+)/m', $meminfo, $total) !== 1
            || preg_match('/^MemAvailable:\s+(\d+)/m', $meminfo, $available) !== 1
            || (int) $total[1] === 0) {
            return null;
        }

        return (int) $available[1] * 100 / (int) $total[1];
    }

    private static function read(string $path): ?string
    {
        $contents = @file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
