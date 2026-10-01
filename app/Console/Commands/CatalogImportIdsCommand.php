<?php

namespace App\Console\Commands;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Support\Admin\PastePreview;
use App\Support\Catalog\ImportDecision;
use App\Support\Catalog\ImportOutcome;
use App\Support\Catalog\TmdbIdentifierList;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use App\Support\Tmdb\TmdbMovie;
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
 * un fichier livré au dépôt, importé lot par lot depuis l'écran d'import.
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
 *
 * **`--dry-run` et `--preview` sont des simulations** : aucune ligne n'est
 * écrite, `import_run` compris (spec 20 § 3.3). `--preview=<jeton>` est la
 * voie de l'aperçu à blanc du back-office, lancée par le job
 * `PreviewCatalogPaste` : le sort de chaque identifiant — titre original,
 * année, motifs d'exception, motif de refus — part dans le cache de
 * {@see PastePreview}, sous la clé de son auteur (`--actor`, obligatoire ici).
 *
 * **Thèmes du collage** (D43 du 01/10, spec 20 § 3.3) : ils sont lus sur la
 * ligne `import_run.added_theme_ids`, écrite par le back-office à l'ouverture,
 * et relus par la reprise. Ils s'appliquent aux films importés (dans la
 * transaction de leur import) et aux films déjà présents (issue `duplicate`,
 * ici), jamais aux refusés, aux retirés ni en simulation. **Aucune option
 * `--theme`** : poser une exception hors du back-office échapperait au
 * journal (critique C9).
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
        {--dry-run : N’écrit rien, pas même le balayage ; compte ce qui serait importé}
        {--preview= : Jeton d’un aperçu à blanc du back-office ; simulation, --actor obligatoire}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe une liste d’identifiants ou d’URL TMDB, hors filtre de notoriété';

    /** Auteur et jeton de l'aperçu en cours, sous `--preview`. */
    private ?int $previewUserId = null;

    private ?string $previewToken = null;

    /**
     * L'auteur et le jeton d'un aperçu précédent ne survivent jamais à son
     * appel : voir {@see CatalogImportCommand::resetInvocationState()}.
     */
    protected function resetInvocationState(): void
    {
        parent::resetInvocationState();

        $this->previewUserId = null;
        $this->previewToken = null;
    }

    public function handle(): int
    {
        if (! $this->readPreview()) {
            return self::FAILURE;
        }

        $this->simulation = $this->previewToken !== null || (bool) $this->option('dry-run');

        if (! $this->assertConfigured()) {
            $this->failPreview('admin.tmdb.error.not_configured');

            return self::FAILURE;
        }

        if (! $this->assertSimulationIsFresh()) {
            return self::FAILURE;
        }

        $this->trapInterrupts();

        $identifiers = $this->identifiers();

        if ($identifiers === []) {
            $this->components->error(
                'Aucun identifiant lisible : passez-les en arguments ou en --file=<chemin>. '
                .'Les URL TMDB de la forme https://www.themoviedb.org/movie/1234-un-slug sont acceptées.',
            );

            $this->failPreview(PastePreview::FAILED_KEY);

            return self::FAILURE;
        }

        // Règle 12 : avant la première écriture, jamais après. Une simulation
        // et le chemin d'import ordinaire du back-office en sont dispensés.
        if (! $this->guardSnapshot()) {
            return self::FAILURE;
        }

        $kind = $this->option('resync') ? ImportRunKind::Resync : ImportRunKind::Paste;

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

        if ($this->previewUserId !== null && $this->previewToken !== null) {
            PastePreview::start($this->previewUserId, $this->previewToken);
        }

        $status = $this->consume($remaining, $run, $filter);

        if ($this->simulation) {
            $this->components->info('Simulation : aucune ligne écrite, pas même le balayage.');
        }

        if ($status === ImportRunStatus::Completed && $this->previewUserId !== null && $this->previewToken !== null) {
            PastePreview::complete($this->previewUserId, $this->previewToken);
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
    private function consume(array $identifiers, ImportRun $run, ImportFilter $filter): ImportRunStatus
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
                    $this->applyThemesToDuplicate($run, $outcome);
                    $this->settle($run, $outcome, null);
                    $bar->advance();

                    continue;
                }
            }

            try {
                $this->limiter->throttle($run);
                $detail = $this->client->movie($identifier);
            } catch (TmdbException $exception) {
                $bar->finish();
                $this->persist($run);
                $this->failPreview($this->previewFailureKey($exception));

                $status = $this->reportTmdbFailure($exception);

                // Un aperçu interrompu n'est pas un balayage suspendu : il n'a
                // pas de curseur, et il se relance d'un bouton.
                return $this->previewToken !== null ? ImportRunStatus::Failed : $status;
            }

            $outcome = $detail === null
                ? ImportOutcome::notFound($identifier)
                : $this->importer->import($detail, $run, $filter, $this->simulation);

            // Un film entré entre la déduplication en lot et l'appel de
            // détail revient `duplicate` d'`import()` : même traitement.
            $this->applyThemesToDuplicate($run, $outcome);
            $this->settle($run, $outcome, $detail);
            $bar->advance();

            if ($this->interrupted) {
                $bar->finish();
                $this->newLine();
                $this->components->warn('Interruption demandée : le collage s’arrête, reprenable par --resume.');
                $this->failPreview(PastePreview::FAILED_KEY);

                return ImportRunStatus::Running;
            }
        }

        $bar->finish();

        return ImportRunStatus::Completed;
    }

    /**
     * Les thèmes du collage sur un film déjà présent (spec 20 § 3.3) : issue
     * `duplicate` seulement — un film retiré revient `refused_withdrawn` et
     * ne reçoit rien —, jamais en simulation. Les règles de non-écrasement
     * (critique C6) sont celles de `MovieImporter::applyRunThemes()`.
     */
    private function applyThemesToDuplicate(ImportRun $run, ImportOutcome $outcome): void
    {
        if ($this->simulation || $outcome->decision !== ImportDecision::Duplicate || $outcome->movie === null) {
            return;
        }

        $this->importer->applyRunThemesToExisting($outcome->movie, $run);
    }

    /**
     * Le sort d'un identifiant : les compteurs, la ligne bavarde, la ligne de
     * l'aperçu s'il y en a un, et l'enregistrement du balayage — jamais en
     * simulation.
     */
    private function settle(ImportRun $run, ImportOutcome $outcome, ?TmdbMovie $detail): void
    {
        $this->importer->journal($run, $outcome);
        $this->reportOutcome($outcome);

        if ($this->previewUserId !== null && $this->previewToken !== null) {
            PastePreview::record(
                $this->previewUserId,
                $this->previewToken,
                PastePreview::row($outcome, $detail?->originalTitle, $detail?->releaseYear()),
            );
        }

        $this->persist($run);
    }

    /**
     * Lit `--preview` : un jeton bien formé et un auteur numérique, ou rien.
     * Un aperçu ne se combine ni avec `--resync` ni avec `--resume` — il ne
     * relit ni ne reprend rien.
     */
    private function readPreview(): bool
    {
        $token = $this->option('preview');

        if ($token === null || $token === '') {
            return true;
        }

        $actor = $this->integerOption('actor');

        if (! PastePreview::isToken($token) || $actor === null || $actor < 1) {
            $this->components->error($this->renderReason('admin.console.import.preview_invalid', []));

            return false;
        }

        if ($this->option('resync') || $this->option('resume')) {
            $this->components->error($this->renderReason('admin.console.import.preview_exclusive', []));

            return false;
        }

        $this->previewUserId = $actor;
        $this->previewToken = $token;

        return true;
    }

    /** Interrompt l'aperçu en cours, s'il y en a un, avec son motif. */
    private function failPreview(string $key): void
    {
        if ($this->previewUserId !== null && $this->previewToken !== null) {
            PastePreview::fail($this->previewUserId, $this->previewToken, $key);
        }
    }

    /**
     * Le motif montré au curateur quand TMDB interrompt un aperçu. Un quota
     * atteint est un appel INTERACTIF qui se rejoue d'un bouton (§ 3.6) ; le
     * reste est une panne générique, dont le détail part au journal.
     */
    private function previewFailureKey(TmdbException $exception): string
    {
        return match ($exception->kind) {
            TmdbErrorKind::RateLimited => 'admin.tmdb.error.rate_limited_interactive',
            TmdbErrorKind::NotConfigured => 'admin.tmdb.error.not_configured',
            default => PastePreview::FAILED_KEY,
        };
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
