<?php

namespace App\Console\Commands;

use App\Enums\DrainPhase;
use App\Enums\Locale;
use App\Support\Deploy\DeployDrain;
use App\Support\Game\GamesInProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `deploy:guard` — la garde du déploiement : spec 100 § 11.3 et § 11.5
 * (étape 3 du hook), contrat C18-bis (nom et signature figés).
 *
 * Code **0** si et seulement si aucune partie n'est en cours
 * ({@see GamesInProgress::count()}, solo compris) **et** le drapeau est en
 * phase `window` ; code **1** si au moins une partie est en cours ; code **2**
 * hors fenêtre (aucun drapeau, drapeau échu, ou drainage pas encore abouti).
 * Les parties en cours sont regardées d'abord : c'est la cause la plus grave,
 * et son remède est le même.
 *
 * Dans le hook, un code non nul arrête tout avant l'instantané et les
 * migrations (`set -e`) ; elle rattrape le résidu de la course entre un
 * lancement validé et la pose du drapeau. Elle ne fait que lire : ni le
 * drapeau ni une partie ne sont modifiés.
 */
class DeployGuardCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:guard';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Réussit seulement dans une fenêtre libre de déploiement, sans aucune partie en cours';

    public function handle(DeployDrain $drain): int
    {
        $games = GamesInProgress::count();

        if ($games > 0) {
            $this->components->error(self::text(trans('admin.console.deploy.guard_games_in_progress', ['count' => $games], Locale::French->value)));

            Log::warning('Garde de déploiement refusée : parties en cours.', ['games_in_progress' => $games]);

            return self::FAILURE;
        }

        if ($drain->state()?->phase !== DrainPhase::Window) {
            $this->components->error(self::text(trans('admin.console.deploy.guard_no_window', [], Locale::French->value)));

            Log::warning('Garde de déploiement refusée : aucune fenêtre libre.');

            return self::INVALID;
        }

        $this->components->info(self::text(trans('admin.console.deploy.guard_ok', [], Locale::French->value)));

        return self::SUCCESS;
    }

    private static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
