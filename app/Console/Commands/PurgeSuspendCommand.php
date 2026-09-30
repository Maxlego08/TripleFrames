<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Support\Retention\PurgeSuspension;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `purge:suspend` — l'interrupteur d'incident de la purge (spec 100 § 14).
 *
 * Pose le drapeau de {@see PurgeSuspension} : aucune exécution ne part plus,
 * et celle qui tourne s'arrête au lot suivant. **La sonde `purge` passe en
 * alerte tant que le drapeau existe** : suspendre ne masque jamais une
 * alerte, cela en déclenche une, qui ne se lève qu'avec `purge:resume`.
 *
 * Idempotente : déjà suspendue, rien n'est modifié (code 0). Aucune ligne
 * `admin_action` : la liste fermée appartient à 10 et la suspension n'est pas
 * un geste sur un sujet. Le journal de l'application la consigne.
 */
class PurgeSuspendCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:suspend';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Suspend la purge de rétention (la sonde purge reste en alerte jusqu’à purge:resume)';

    public function handle(PurgeSuspension $suspension): int
    {
        if (! $suspension->suspend(CarbonImmutable::now())) {
            $message = trans('admin.console.purge.already_suspended', [], Locale::French->value);
            $this->components->warn(is_string($message) ? $message : '');

            return self::SUCCESS;
        }

        Log::warning('Purge de rétention suspendue depuis la console.');

        $message = trans('admin.console.purge.suspended', [], Locale::French->value);
        $this->components->warn(is_string($message) ? $message : '');

        return self::SUCCESS;
    }
}
