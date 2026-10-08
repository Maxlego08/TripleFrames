<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Models\Theme;
use App\Support\Catalog\MovieDifficultyDeriver;
use App\Support\Catalog\ThemeEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

/**
 * Le rattrapage complet de la difficulté dérivée et des appartenances film ↔
 * thème (spec 30 § 13.2, lots L30-8 et L30-10, D43 du 01/10).
 *
 * Outil du porteur, joué **à la main** — au déploiement de L30-8, puis de
 * L30-10 —, jamais sur le chemin du curateur ni dans le hook de déploiement.
 * Il dérive d'abord la difficulté ({@see MovieDifficultyDeriver::derive()}),
 * **puis** réévalue chaque thème, publié ou non, dans l'ordre d'affichage, par
 * l'évaluateur unique {@see ThemeEvaluator::syncTheme()}. Idempotent : rejoué
 * sur un catalogue à jour, il n'écrit aucune ligne.
 *
 * **Règle 12** : il écrit `movie` (`movie_difficulty_derived`,
 * `movie_difficulty`) ; il appelle donc **en tête** `backup:snapshot`, sans
 * `--if-pending`, et s'arrête **sans rien écrire** sur un code non nul. Une
 * configuration de difficulté hors bornes l'arrête aussi, avant toute
 * écriture.
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
    protected $description = 'Dérive la difficulté puis réévalue l’appartenance de tout le catalogue à chaque thème, après un instantané bloquant';

    public function handle(ThemeEvaluator $evaluator, MovieDifficultyDeriver $deriver): int
    {
        App::setLocale(Locale::French->value);

        if ($this->call('backup:snapshot') !== self::SUCCESS) {
            $this->components->error($this->message('admin.console.themes.snapshot_failed'));

            return self::FAILURE;
        }

        // La dérivation d'abord (spec 30 § 13.2) : les thèmes de difficulté
        // sont ensuite évalués sur la valeur effective à jour. Elle lève sur
        // une configuration hors bornes, avant toute écriture.
        $this->components->info($this->message('admin.console.themes.derived', [
            'changed' => $deriver->derive(),
        ]));

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
