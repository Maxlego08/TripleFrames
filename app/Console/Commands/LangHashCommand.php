<?php

namespace App\Console\Commands;

use App\Support\I18n\LangVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Écrit l'empreinte des fichiers `lang/` dans `bootstrap/cache/lang-version.php`.
 *
 * À placer dans le script de déploiement **à côté de `config:cache`**. En
 * développement le fichier est absent, et l'empreinte est recalculée à chaque
 * requête : une traduction modifiée est visible au rechargement suivant.
 */
class LangHashCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lang:hash {--clear : Supprime le fichier au lieu de l’écrire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calcule l’empreinte des fichiers lang/ consommée par HandleInertiaRequests::version()';

    public function handle(LangVersion $version): int
    {
        $path = $version->cachePath();

        if ($this->option('clear')) {
            File::delete($path);

            $this->components->info('Empreinte des traductions supprimée : elle sera recalculée à la volée.');

            return self::SUCCESS;
        }

        $fingerprint = $version->compute();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, "<?php\n\nreturn '".$fingerprint."';\n");

        $this->components->info("Empreinte des traductions écrite : {$fingerprint}");

        return self::SUCCESS;
    }
}
