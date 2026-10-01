<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Models\Theme;
use App\Support\Catalog\ThemeEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Le rattrapage complet des appartenances film ↔ thème (spec 30 § 13.2,
 * lot L30-8, D43 du 01/10).
 *
 * Outil du porteur, joué **à la main** — au déploiement de L30-8, puis de
 * L30-10 —, jamais sur le chemin du curateur ni dans le hook de déploiement.
 * Il réévalue chaque thème, publié ou non, dans l'ordre d'affichage, par
 * l'évaluateur unique {@see ThemeEvaluator::syncTheme()}. Idempotent : rejoué
 * sur un catalogue à jour, il n'écrit aucune ligne.
 *
 * **Règle 12** : il appelle **en tête** `backup:snapshot`, sans
 * `--if-pending`, et s'arrête **sans rien écrire** sur un code non nul. Au
 * J1 il ne réécrit que `movie_theme`, mais dès L30-10 il écrira `movie` : une
 * commande de rattrapage sur tout le catalogue ne change pas de contrat de
 * sécurité d'un lot à l'autre.
 *
 * Aucune option (§ 13.1).
 */
class CatalogThemesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:themes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Réévalue l’appartenance de tout le catalogue à chaque thème, après un instantané bloquant';

    public function handle(ThemeEvaluator $evaluator): int
    {
        App::setLocale(Locale::French->value);

        if ($this->call('backup:snapshot') !== self::SUCCESS) {
            $this->components->error($this->message('admin.console.themes.snapshot_failed'));

            return self::FAILURE;
        }

        $total = 0;

        $themes = Theme::query()->orderBy('sort_order')->orderBy('key')->get();

        foreach ($themes as $theme) {
            $changed = $evaluator->syncTheme($theme);
            $total += $changed;

            $this->components->twoColumnDetail($theme->key, $this->message('admin.console.themes.theme', ['changed' => $changed]));
        }

        $this->components->info($this->message('admin.console.themes.done', [
            'themes' => $themes->count(),
            'changed' => $total,
        ]));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    private function message(string $key, array $replacements = []): string
    {
        $message = __($key, $replacements, Locale::French->value);

        return is_string($message) ? $message : $key;
    }
}
