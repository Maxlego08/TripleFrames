<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Enums\PurgeRunStatus;
use App\Jobs\Retention\RunRetentionPurge;
use App\Models\PurgeRun;
use App\Support\Retention\PurgeSuspension;
use App\Support\Retention\RetentionPurger;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * `purge:run {--sync}` — spec 100 § 14.
 *
 * Sans option, dépose {@see RunRetentionPurge} sur la file `default`, comme
 * le planificateur. Avec `--sync`, exécute la purge dans ce processus et en
 * rend le bilan : c'est l'étape (f) de la restauration (§ 13.5, règle 12 —
 * une sauvegarde ne doit jamais ressusciter des données purgées) et le
 * contrôle manuel.
 *
 * Codes de sortie : 0 si le job est déposé, ou sous `--sync` si tout
 * périmètre est `completed` sans ligne en échec ; 1 si la purge est suspendue
 * (rien n'est exécuté ni déposé : une restauration ne rouvre pas le trafic
 * sur une purge qui n'a pas tourné), si une purge tient déjà le verrou
 * d'unicité du job (en file, en cours, ou sur un worker tué depuis moins de
 * `$uniqueFor` : rien n'est déposé), ou si un périmètre a échoué ou laissé
 * des lignes en échec.
 *
 * Sortie en français par clés littérales, locale `fr` forcée (§ 11.3).
 */
class PurgeRunCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:run
        {--sync : Exécuter la purge dans ce processus au lieu de la déposer sur la file default}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lance la purge de rétention des périmètres implémentés';

    public function handle(RetentionPurger $purger, PurgeSuspension $suspension, Cache $cache, Dispatcher $bus): int
    {
        if ($suspension->isSuspended()) {
            $this->components->error(self::text(trans('admin.console.purge.suspended', [], Locale::French->value)));

            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            $job = new RunRetentionPurge;

            // Le verrou d'unicité est pris ICI, comme le fait le planificateur
            // (`Schedule::dispatchUniqueJobToQueue`) : `RunRetentionPurge::dispatch()`
            // abandonnerait le dépôt en silence et la commande annoncerait une
            // purge qui ne partira jamais.
            if (! (new UniqueLock($cache))->acquire($job)) {
                $this->components->warn(self::text(trans('admin.console.purge.already_queued', [], Locale::French->value)));

                return self::FAILURE;
            }

            $bus->dispatch($job);

            $this->components->info(self::text(trans('admin.console.purge.queued', [], Locale::French->value)));

            return self::SUCCESS;
        }

        $runs = $purger->run();

        $this->components->info(self::text(trans('admin.console.purge.done', [
            'scopes' => count($runs),
            'rows' => array_sum(array_map(static fn (PurgeRun $run): int => $run->rows_deleted, $runs)),
        ], Locale::French->value)));

        $failed = array_values(array_filter(
            $runs,
            static fn (PurgeRun $run): bool => $run->status !== PurgeRunStatus::Completed || $run->error !== null,
        ));

        if ($failed !== []) {
            $this->components->error(self::text(trans('admin.console.purge.failed', [
                'scopes' => implode(', ', array_map(static fn (PurgeRun $run): string => $run->scope->value, $failed)),
            ], Locale::French->value)));
        }

        // Suspendue pendant l'exécution : les périmètres restants n'ont pas tourné.
        if ($suspension->isSuspended()) {
            $this->components->error(self::text(trans('admin.console.purge.suspended', [], Locale::French->value)));
        }

        return $failed === [] && ! $suspension->isSuspended() ? self::SUCCESS : self::FAILURE;
    }

    private static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
