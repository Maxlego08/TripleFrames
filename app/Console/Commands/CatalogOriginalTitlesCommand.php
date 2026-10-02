<?php

namespace App\Console\Commands;

use App\Enums\Locale;
use App\Models\Movie;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use App\Support\Catalog\OriginalLanguageTitle;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * Le rattrapage du titre de la langue originale — spec 10 § 3.4, D52 du 02/10.
 *
 * Les films importés avant D52 n'ont aucune ligne `movie_title` dans leur
 * langue originale quand elle est activée (TMDB rend cette traduction vide).
 * Pour chacun, la commande écrit `title_original` comme titre de cette locale
 * par l'écrivain unique {@see OriginalLanguageTitle::write()}, puis reprojette
 * le film — `movie_projection` (donc `title_locale_mask`) et `answer_key` —
 * dans **sa** transaction, comme l'import.
 *
 * Outil du porteur, joué **à la main**, jamais dans le hook de déploiement ni
 * en CI. Idempotent : rejoué sur un catalogue à jour, il n'écrit rien.
 *
 * **Règle 12** : elle écrit `movie_title`, donc elle appelle **en tête**
 * `backup:snapshot`, sans `--if-pending`, et s'arrête **sans rien écrire** sur
 * un code non nul. `--dry-run` compte les films à rattraper sans instantané ni
 * écriture. Aucune ligne `admin_action` : la liste fermée du journal (`10`
 * § 8.3) ne porte aucun geste de console sur le catalogue, comme
 * `catalog:themes`.
 */
class CatalogOriginalTitlesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:original-titles
        {--dry-run : compter les films à rattraper, sans instantané ni écriture}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Écrit le titre de la langue originale activée des films qui n’en ont pas, après un instantané bloquant';

    /** Films lus par lot : borne la mémoire sur un catalogue entier. */
    private const int CHUNK = 200;

    public function handle(MovieProjector $projections, AnswerKeyProjector $answerKeys): int
    {
        // Le domaine `admin` est français par construction : aucune requête
        // HTTP ne pose la locale d'une console.
        App::setLocale(Locale::French->value);

        if ($this->option('dry-run')) {
            $this->components->info($this->message('admin.console.original_titles.dry_run', [
                'count' => $this->pending()->count(),
            ]));

            return self::SUCCESS;
        }

        if ($this->call('backup:snapshot') !== self::SUCCESS) {
            $this->components->error($this->message('admin.console.original_titles.snapshot_failed'));

            return self::FAILURE;
        }

        $written = 0;

        $this->pending()->chunkById(self::CHUNK, function (EloquentCollection $movies) use ($projections, $answerKeys, &$written): void {
            foreach ($movies as $movie) {
                $created = DB::transaction(static function () use ($movie, $projections, $answerKeys): bool {
                    if (! OriginalLanguageTitle::write($movie)) {
                        return false;
                    }

                    $answerKeys->project($movie);
                    $projections->recompute($movie);

                    return true;
                });

                if ($created) {
                    $written++;
                }
            }
        });

        $this->components->info($this->message('admin.console.original_titles.done', ['count' => $written]));

        return self::SUCCESS;
    }

    /**
     * Les films dont la langue originale est activée et qui n'ont aucune ligne
     * `movie_title` pour elle. Le verdict final reste celui de
     * {@see OriginalLanguageTitle::isMissing()}, relu dans la transaction.
     *
     * @return Builder<Movie>
     */
    private function pending(): Builder
    {
        return Movie::query()
            ->whereIn('original_language', array_map(static fn (Locale $locale): string => $locale->value, Locale::cases()))
            ->where('title_original', '!=', '')
            ->whereNotExists(static fn (QueryBuilder $titles): QueryBuilder => $titles
                ->selectRaw('1')
                ->from('movie_title')
                ->whereColumn('movie_title.movie_id', 'movie.id')
                ->whereColumn('movie_title.locale', 'movie.original_language'));
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
