<?php

namespace App\Console\Commands;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Support\Catalog\ImportOutcome;
use App\Support\Catalog\TmdbIdentifierList;
use App\Support\Tmdb\TmdbException;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Support\Facades\File;

/**
 * La **voie d'exception** — collage d'identifiants ou d'URL TMDB.
 *
 * Elle **ignore entièrement le filtre de goût** et marque chaque film entré
 * `is_import_exception`, avec ses motifs, filtrables et comptables en
 * back-office, jamais silencieux (décision 11). C'est elle qui fait entrer
 * Parasite, Le Labyrinthe de Pan, Old Boy, La vita è bella et tout l'âge d'or
 * Disney antérieur à 1970 : la liste d'amorçage d'environ 200 identifiants est
 * un fichier livré au dépôt, collé ici en un geste.
 *
 * **Le filtre de contenu, lui, s'applique à l'identique.** `adult`, FR -18, US
 * NC-17 et US X refusent par cette voie exactement comme par le balayage, et
 * aucune option ne le contourne (décision 12). L'asymétrie ne porte que sur le
 * goût.
 *
 * `--resync` relit les métadonnées TMDB de films déjà en base, sous la **liste
 * close** du § 9.3 : elle n'écrase jamais une ligne `curator`, ni
 * `movie_difficulty_override`, ni `group_id`, ni les motifs d'exception, ni la
 * disponibilité, ni la moindre frame. Elle ne réapplique jamais le filtre
 * d'import : un film marqué exception n'est jamais proposé au retrait pour sa
 * langue, ses votes ou sa date.
 */
class CatalogImportIdsCommand extends CatalogImportCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:import-ids
        {ids?* : Identifiants TMDB ou URL, séparés par des espaces}
        {--file= : Fichier de collage, un identifiant ou une URL par ligne}
        {--resync : Relit les métadonnées TMDB des films déjà en base}
        {--resume : Reprend le dernier collage laissé en cours}
        {--run= : Identifiant du collage à reprendre, avec --resume}
        {--actor= : Identifiant ou e-mail du compte à qui attribuer le collage}
        {--dry-run : N’écrit rien ; compte ce qui serait importé}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe une liste d’identifiants ou d’URL TMDB, hors filtre de notoriété';

    public function handle(): int
    {
        if (! $this->assertConfigured()) {
            return self::FAILURE;
        }

        $this->trapInterrupts();

        $identifiers = $this->identifiers();

        if ($identifiers === []) {
            $this->components->error(
                'Aucun identifiant lisible : passez-les en arguments ou en --file=<chemin>. '
                .'Les URL TMDB de la forme https://www.themoviedb.org/movie/1234-un-slug sont acceptées.',
            );

            return self::FAILURE;
        }

        $kind = $this->option('resync') ? ImportRunKind::Resync : ImportRunKind::Paste;
        $dryRun = (bool) $this->option('dry-run');

        $run = $this->option('resume') ? $this->resumableRun($kind) : null;

        if ($this->option('resume') && ! $run instanceof ImportRun) {
            $this->components->info('Aucun collage à reprendre.');

            return self::SUCCESS;
        }

        // Le filtre est enregistré sur le collage **sans être appliqué** : il
        // documente le défaut en vigueur au moment du geste, ce qui rend les
        // trois motifs relisibles des mois plus tard. `is_widened` reste faux —
        // un collage n'élargit rien, il contourne (§ 9.2).
        $filter = ImportFilter::default();

        $run ??= $this->openRun($kind, $filter);

        $this->limiter->resumeFrom($run);

        // La reprise d'un collage repart du nombre de films déjà vus : la liste
        // est ordonnée et stable, donc `total_seen` est à lui seul un curseur.
        $processed = $this->option('resume') ? $run->total_seen : 0;
        $remaining = array_slice($identifiers, $processed);

        if ($remaining === []) {
            $this->components->info('Collage déjà entièrement traité.');
            $this->closeRun($run, ImportRunStatus::Completed);
            $this->reportRun($run);

            return self::SUCCESS;
        }

        $status = $this->consume($remaining, $run, $filter, $dryRun);

        if ($dryRun) {
            $this->components->info('Simulation : aucune ligne écrite.');
        }

        $this->closeRun($run, $status);
        $this->reportRun($run);

        return $status === ImportRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Un identifiant à la fois : un collage de 200 films est 200 appels de
     * détail, et chacun est une frontière sûre où l'interruption laisse un
     * collage reprenable.
     *
     * @param  list<int>  $identifiers
     */
    private function consume(array $identifiers, ImportRun $run, ImportFilter $filter, bool $dryRun): ImportRunStatus
    {
        // La déduplication en lot, servie par `movie_tmdb_uq` (§ 9.2) : un seul
        // aller-retour par tranche, au lieu d'un `SELECT` par identifiant.
        $existing = $this->importer->existingByTmdbId($identifiers);

        $bar = $this->progressBar(count($identifiers));

        foreach ($identifiers as $identifier) {
            $known = $existing[$identifier] ?? null;

            // Un film déjà connu que la voie n'autorise pas à relire ne coûte
            // aucun appel de détail.
            if ($known !== null) {
                $outcome = $this->importer->outcomeForExisting($known, $run);

                if ($outcome !== null) {
                    $this->importer->journal($run, $outcome);
                    $this->reportOutcome($outcome);
                    $bar->advance();
                    $run->save();

                    continue;
                }
            }

            try {
                $this->limiter->throttle($run);
                $detail = $this->client->movie($identifier);
            } catch (TmdbException $exception) {
                $bar->finish();
                $run->save();

                return $this->reportTmdbFailure($exception);
            }

            $outcome = $detail === null
                ? ImportOutcome::notFound($identifier)
                : $this->importer->import($detail, $run, $filter, $dryRun);

            $this->importer->journal($run, $outcome);
            $this->reportOutcome($outcome);
            $bar->advance();
            $run->save();

            if ($this->interrupted) {
                $bar->finish();
                $this->newLine();
                $this->components->warn('Interruption demandée : le collage s’arrête, reprenable par --resume.');

                return ImportRunStatus::Running;
            }
        }

        $bar->finish();

        return ImportRunStatus::Completed;
    }

    /**
     * Les identifiants du collage : les arguments, puis le fichier, dans cet
     * ordre et dédoublonnés entre eux.
     *
     * @return list<int>
     */
    private function identifiers(): array
    {
        /** @var list<string> $lines */
        $lines = [];

        /** @var array<array-key, mixed> $arguments */
        $arguments = (array) $this->argument('ids');

        foreach ($arguments as $argument) {
            if (is_string($argument)) {
                $lines[] = $argument;
            }
        }

        $path = $this->option('file');

        if (is_string($path) && $path !== '') {
            if (! File::isFile($path)) {
                $this->components->error('Fichier de collage introuvable : ['.$path.'].');

                return [];
            }

            foreach (preg_split('/\R/u', File::get($path)) ?: [] as $line) {
                $lines[] = $line;
            }
        }

        return TmdbIdentifierList::parse($lines);
    }
}
