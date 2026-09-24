<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Support\Retention\PurgeSuspension;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `purge:resume` — lève la suspension posée par `purge:suspend` (spec 100
 * § 14). La purge repart à sa prochaine exécution, planifiée ou lancée par
 * `purge:run` ; la sonde `purge` revient au vert dès que chaque périmètre a
 * une exécution terminée dans sa fenêtre.
 *
 * Idempotente : non suspendue, rien n'est modifié (code 0).
 */
class PurgeResumeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:resume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lève la suspension de la purge de rétention';

    public function handle(PurgeSuspension $suspension): int
    {
        if (! $suspension->resume()) {
            $message = trans('admin.console.purge.not_suspended', [], Locale::French->value);
            $this->components->info(is_string($message) ? $message : '');

            return self::SUCCESS;
        }

        Log::notice('Purge de rétention reprise depuis la console.');

        $message = trans('admin.console.purge.resumed', [], Locale::French->value);
        $this->components->info(is_string($message) ? $message : '');

        return self::SUCCESS;
    }
}
