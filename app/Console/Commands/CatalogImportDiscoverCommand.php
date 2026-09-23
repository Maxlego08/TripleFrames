<?php

namespace App\Console\Commands;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Support\Catalog\DiscoverCursor;
use App\Support\Catalog\ImportOutcome;
use App\Support\Tmdb\TmdbException;
use App\Support\Tmdb\TmdbMovieSummary;
use App\Support\Tmdb\TmdbPage;
use App\ValueObjects\Catalog\ImportFilter;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Le balayage `discover`, **filtré** — la voie ordinaire d'entrée au catalogue.
 *
 * Le filtre de goût — notoriété, langue originale, profondeur historique — ne
 * gouverne que cette commande. Ce qu'il écarte se rattrape par
 * `catalog:import-ids`, qui l'ignore entièrement et marque le film
 * `is_import_exception` : c'est elle qui fait entrer Parasite, Le Labyrinthe de
 * Pan et tout l'âge d'or Disney antérieur à 1970 (décision 11).
 *
 * **Reprenable de bout en bout.** `import_run` porte la provenance et la
 * reprise : le curseur composite, l'instant du dernier appel et les quatre
 * compteurs sont enregistrés à chaque page. Un quota atteint, un `queue:restart`
 * ou un Ctrl-C laissent un balayage `running` que `--resume` reprend là où il
 * s'était arrêté — un balayage de 500+ films non reprenable n'aboutit jamais.
 *
 * **Deux appels par film, et pas un de plus.** La page de balayage, puis un
 * appel de détail par film retenu, `append_to_response` compris. Les films que
 * le filtre écarte ne coûtent aucun appel de détail : c'est tout l'intérêt du
 * tri fait sur la fiche de balayage.
 */
class CatalogImportDiscoverCommand extends CatalogImportCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:import-discover
        {--resume : Reprend le dernier balayage `discover` laissé en cours}
        {--run= : Identifiant du balayage à reprendre, avec --resume}
        {--pages=5 : Nombre de pages TMDB traitées par invocation}
        {--min-votes= : Remplace le seuil de notoriété du filtre par défaut}
        {--languages= : Remplace les langues originales du filtre, séparées par des virgules}
        {--min-year= : Remplace l’année de sortie minimale du filtre}
        {--actor= : Identifiant ou e-mail du compte à qui attribuer le balayage}
        {--dry-run : N’écrit rien ; compte ce qui serait importé}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Balaie TMDB avec le filtre de notoriété et importe les films retenus';

    public function handle(): int
    {
        if (! $this->assertConfigured()) {
            return self::FAILURE;
        }

        $this->trapInterrupts();

        $dryRun = (bool) $this->option('dry-run');

        $run = $this->option('resume') ? $this->resumableRun(ImportRunKind::Discover) : null;

        if ($this->option('resume') && ! $run instanceof ImportRun) {
            $this->components->info('Aucun balayage `discover` à reprendre.');

            return self::SUCCESS;
        }

        $filter = $run instanceof ImportRun
            ? ImportFilter::fromColumns($run->filter_min_vote_count, $run->filter_languages, $run->filter_min_release_year)
            : $this->filterFromOptions();

        $run ??= $this->openRun(ImportRunKind::Discover, $filter);

        if ($run->is_widened) {
            $this->components->warn(
                'Filtre ÉLARGI par rapport au défaut du site : les films entrés par ce balayage seront marqués '
                .'`is_import_exception`, avec leurs motifs.',
            );
        }

        $this->limiter->resumeFrom($run);

        $status = $this->sweep($run, $filter, $dryRun);

        if ($dryRun) {
            $this->components->info('Simulation : aucune ligne écrite, aucun compteur figé au-delà de ce balayage.');
        }

        $this->closeRun($run, $status);
        $this->reportRun($run);

        return $status === ImportRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Le balayage proprement dit : une série paginée par langue, reprise à la
     * position composite portée par `tmdb_page_cursor`.
     */
    private function sweep(ImportRun $run, ImportFilter $filter, bool $dryRun): ImportRunStatus
    {
        $languages = $filter->languages;
        $budget = max(1, (int) $this->option('pages'));
        $cursor = DiscoverCursor::fromColumn($run->tmdb_page_cursor);
        $bar = $this->progressBar();

        while ($budget > 0) {
            $language = $languages[$cursor->languageIndex] ?? null;

            if ($language === null) {
                break;
            }

            try {
                $this->limiter->throttle($run);
                $page = $this->client->discover($filter->discoverQueryFor($language), $cursor->page);
            } catch (TmdbException $exception) {
                $bar->finish();
                $run->tmdb_page_cursor = $cursor->toColumn();
                $run->save();

                return $this->reportTmdbFailure($exception);
            }

            $this->growProgressBar($bar, $page);

            $status = $this->consume($page, $run, $filter, $dryRun, $bar);

            $budget--;

            $next = $cursor->next($page) ?? $cursor->nextLanguage(count($languages));

            // Une page à demi consommée est REJOUÉE EN ENTIER : `consume()` ne
            // rend un statut que lorsqu'un appel de DÉTAIL a échoué, donc au
            // milieu de la page courante. Avancer le curseur ici perdrait en
            // silence tous les films restants de la page — jusqu'à dix-neuf par
            // incident, sans aucune trace dans `import_run`, alors que la
            // relecture est inoffensive : `movie_tmdb_uq` et
            // `outcomeForExisting()` rendent `Duplicate` sans appel de détail.
            if ($status !== null) {
                $run->tmdb_page_cursor = $cursor->toColumn();
                $run->save();

                $bar->finish();

                return $status;
            }

            // Page consommée jusqu'au bout : le curseur enregistré est celui de
            // la prochaine page à lire.
            $run->tmdb_page_cursor = ($next ?? $cursor)->toColumn();
            $run->save();

            if ($next === null) {
                $bar->finish();

                return ImportRunStatus::Completed;
            }

            $cursor = $next;

            if ($this->interrupted) {
                $bar->finish();
                $this->newLine();
                $this->components->warn('Interruption demandée : le balayage s’arrête à une frontière sûre.');

                return ImportRunStatus::Running;
            }
        }

        $bar->finish();

        return ImportRunStatus::Running;
    }

    /**
     * Traite les films d'une page. Rend un statut terminal quand TMDB fait
     * échouer un appel de détail, `null` quand la page est consommée.
     */
    private function consume(
        TmdbPage $page,
        ImportRun $run,
        ImportFilter $filter,
        bool $dryRun,
        ProgressBar $bar,
    ): ?ImportRunStatus {
        $existing = $this->importer->existingByTmdbId(array_map(
            static fn (TmdbMovieSummary $summary): int => $summary->tmdbId,
            $page->results,
        ));

        foreach ($page->results as $summary) {
            $outcome = $this->importer->screen(
                $summary,
                $run,
                $filter,
                $existing[$summary->tmdbId] ?? null,
            );

            if ($outcome === null) {
                try {
                    $this->limiter->throttle($run);
                    $detail = $this->client->movie($summary->tmdbId);
                } catch (TmdbException $exception) {
                    $run->save();

                    // Un 404 rend déjà `null` sans lever : ce qui passe ici est
                    // un quota, une panne serveur ou un transport. Suspendre
                    // vaut mieux qu'échouer — la page sera relue entière.
                    return $this->reportTmdbFailure($exception);
                }

                $outcome = $detail === null
                    ? ImportOutcome::notFound($summary->tmdbId)
                    : $this->importer->import($detail, $run, $filter, $dryRun);
            }

            $this->importer->journal($run, $outcome);
            $this->reportOutcome($outcome);
            $bar->advance();
        }

        return null;
    }

    /**
     * Étend la barre au fur et à mesure que le balayage découvre sa taille :
     * le total d'une série n'est connu qu'à sa première page, et le plafond dur
     * de `discover` borne ce que TMDB rendra réellement.
     */
    private function growProgressBar(ProgressBar $bar, TmdbPage $page): void
    {
        $reachable = min($page->totalPages, TmdbPage::MAX_PAGE) * max(1, count($page->results));

        $bar->setMaxSteps(max($bar->getMaxSteps(), $bar->getProgress() + min($page->totalResults, $reachable)));
    }

    /**
     * Le filtre de cette invocation. Chaque axe non fourni retombe sur le
     * défaut du site, et c'est la comparaison au défaut — et elle seule — qui
     * pose `is_widened`.
     */
    private function filterFromOptions(): ImportFilter
    {
        $languages = $this->option('languages');

        return ImportFilter::make(
            minVoteCount: $this->integerOption('min-votes'),
            languages: is_string($languages) && $languages !== '' ? explode(',', $languages) : null,
            minReleaseYear: $this->integerOption('min-year'),
        );
    }
}
