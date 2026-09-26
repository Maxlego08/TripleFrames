<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Support\Deploy\DeployDrain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `deploy:release` — lève le drapeau de drainage : spec 100 § 11.3 et
 * § 11.5 (étape 12 du hook), contrat C18-bis (nom et signature figés).
 *
 * Retire le drapeau **quelle que soit sa phase** et sort **toujours** en
 * code 0 : sans drapeau, elle le dit et ne fait rien. C'est aussi le remède
 * écrit d'un hook qui a échoué avant son étape 12, une fois l'état réparé
 * (§ 11.5) — sinon le drapeau tient jusqu'à l'échéance de la fenêtre.
 */
class DeployReleaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:release';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lève le drapeau de drainage, quelle que soit sa phase : les lancements sont de nouveau permis';

    public function handle(DeployDrain $drain): int
    {
        $state = $drain->state();

        $drain->release();

        if ($state === null) {
            $this->components->info(self::text(trans('admin.console.deploy.nothing_to_release', [], Locale::French->value)));

            return self::SUCCESS;
        }

        $this->components->info(self::text(trans('admin.console.deploy.released', [], Locale::French->value)));

        Log::notice('Drapeau de drainage levé.', [
            'started_at' => $state->startedAt->utc()->toIso8601ZuluString(),
            'expires_at' => $state->expiresAt->utc()->toIso8601ZuluString(),
        ]);

        return self::SUCCESS;
    }

    private static function text(mixed $translated): string
    {
        return is_string($translated) ? $translated : '';
    }
}
