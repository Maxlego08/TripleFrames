<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * L'élagage quotidien des instantanés — spec 100 § 13.1, planifié au § 10.7.
 *
 * **Pourquoi une rétention EXÉCUTÉE et non seulement déclarée.** Les vidages de
 * `backup:snapshot` portent des données personnelles ; pris seulement avant
 * une migration ou un geste de console, ils s'accumuleraient sans borne, et la
 * durée de conservation annoncée (10 § 11.1 : sauvegardes à 30 jours) serait
 * fausse. D'où une commande planifiée chaque jour à `config('backup.prune_at')`.
 *
 * **Elle ne supprime que des instantanés, et tous les instantanés échus** : un
 * fichier du répertoire dont le NOM suit le motif de § 13.1
 * ({@see BackupSnapshotCommand::FILE_PATTERN}, restes `.partial` d'un
 * instantané interrompu compris) et dont l'instant, lu dans ce nom, est plus
 * vieux que la rétention. Tout autre fichier, tout répertoire et tout lien
 * restent intacts. L'âge se lit dans le nom et jamais dans l'heure de
 * modification, qu'une copie ou une restauration réécrit.
 *
 * **Rétention** : `config('backup.snapshot_keep_days')`, ramenée à 30 jours au
 * plus — une variable réglée plus haut ne prolonge jamais la conservation —
 * et à un jour au moins, pour qu'un réglage nul ne supprime pas l'instantané
 * qu'un déploiement vient de prendre.
 *
 * Une suppression impossible fait échouer la commande (code non nul, journal
 * du planificateur) : un instantané qu'on n'arrive pas à effacer est une donnée
 * personnelle conservée au-delà de la durée annoncée.
 */
class BackupPruneSnapshotsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:prune-snapshots';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Supprime les instantanés plus vieux que la rétention configurée, jamais au-delà de 30 jours';

    /** Plafond de la rétention : les sauvegardes vivent 30 jours (10 § 11.1). */
    public const int MAX_KEEP_DAYS = 30;

    /** Plancher : l'instantané du déploiement du jour n'est jamais élagué le jour même. */
    public const int MIN_KEEP_DAYS = 1;

    public function handle(): int
    {
        $deleted = 0;
        // Résolu exactement comme l'écrivain le résout (blancs de bord rognés).
        $directory = BackupSnapshotCommand::configuredDirectory();
        $threshold = CarbonImmutable::now()->subDays(self::retentionDays());

        if ($directory !== '' && is_dir($directory)) {
            $entries = scandir($directory);

            foreach ($entries === false ? [] : $entries as $entry) {
                $takenAt = BackupSnapshotCommand::takenAt($entry);

                if ($takenAt === null || ! $takenAt->lessThan($threshold)) {
                    continue;
                }

                $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$entry;

                if (is_link($path) || ! is_file($path)) {
                    continue;
                }

                unlink($path);
                $deleted++;
            }
        }

        $message = trans('admin.console.backup.pruned', ['count' => $deleted], Locale::French->value);

        $this->components->info(is_string($message) ? $message : '');

        return self::SUCCESS;
    }

    /**
     * La rétention appliquée, en jours : la configuration, bornée à
     * [{@see self::MIN_KEEP_DAYS}, {@see self::MAX_KEEP_DAYS}].
     */
    public static function retentionDays(): int
    {
        $configured = config('backup.snapshot_keep_days');
        $days = is_numeric($configured) ? (int) $configured : self::MIN_KEEP_DAYS;

        return max(self::MIN_KEEP_DAYS, min(self::MAX_KEEP_DAYS, $days));
    }
}
