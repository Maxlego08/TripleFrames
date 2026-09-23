<?php

namespace App\Console\Commands;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\Catalog\ImportDecision;
use App\Support\Catalog\ImportOutcome;
use App\Support\Catalog\MovieImporter;
use App\Support\Catalog\TmdbQuotaLimiter;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use App\ValueObjects\Catalog\ImportFilter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Ce que les deux voies d'import partagent : le refus poli quand aucune clé
 * n'est posée, l'ouverture et la clôture d'un `import_run`, l'étranglement de
 * quota, l'interruption propre et le compte rendu.
 *
 * **Les textes de console sont en français littéral**, et c'est le précédent
 * déjà posé par `lang:hash` et `lang:types` dans ce dépôt. La règle 4 porte sur
 * les surfaces d'interface — écrans, erreurs de validation, e-mails, attributs
 * `alt` — ; une sortie de terminal n'en est pas une, et le domaine `admin` est
 * français par construction (décision 9). **Seuls les motifs de refus voyagent
 * en clés de traduction** : ils viennent du service d'import et du client TMDB,
 * ils seront rendus tels quels par l'écran de back-office, et les
 * pré-formatter ici les rendrait intraduisibles là-bas.
 */
abstract class CatalogImportCommand extends Command
{
    /**
     * Numéros de signaux écrits en littéral : `SIGINT` et `SIGTERM` sont des
     * constantes de `ext-pcntl`, **absente de cet environnement**, et les
     * nommer ferait tomber la commande sur une constante indéfinie avant même
     * d'avoir affiché quoi que ce soit. `Command::trap()` est de toute façon un
     * appel sans effet quand l'extension manque : l'interruption reste alors
     * celle du terminal, et c'est l'enregistrement à chaque page qui rend le
     * balayage reprenable, pas le piège de signal.
     *
     * @var list<int>
     */
    protected const array INTERRUPT_SIGNALS = [2, 15];

    /** Posé par le piège de signal ; relu à chaque frontière sûre. */
    protected bool $interrupted = false;

    public function __construct(
        protected readonly TmdbClient $client,
        protected readonly MovieImporter $importer,
        protected readonly TmdbQuotaLimiter $limiter,
    ) {
        parent::__construct();
    }

    /**
     * Le refus poli du § « la CI doit passer sans clé TMDB » : une clé absente
     * ne casse rien, elle désactive l'import avec un message clair.
     */
    protected function assertConfigured(): bool
    {
        if ($this->client->isConfigured()) {
            return true;
        }

        $this->components->error(
            'Aucune clé TMDB configurée : posez TMDB_API_READ_ACCESS_TOKEN ou TMDB_API_KEY dans .env. '
            .'L’import est désactivé, rien d’autre ne l’est.',
        );

        return false;
    }

    /**
     * Arme l'interruption propre : à la prochaine frontière sûre — une page
     * terminée, un identifiant traité —, le balayage enregistre son curseur et
     * ses compteurs, et s'arrête **reprenable**.
     */
    protected function trapInterrupts(): void
    {
        $this->trap(self::INTERRUPT_SIGNALS, function (): void {
            $this->interrupted = true;
        });
    }

    /**
     * Ouvre un balayage. `is_widened` et les trois colonnes de filtre sont
     * **figées au démarrage** : le défaut du site peut changer après coup, la
     * preuve qu'un balayage a été élargi, non (§ 9.1).
     */
    protected function openRun(ImportRunKind $kind, ImportFilter $filter): ImportRun
    {
        $run = new ImportRun;

        $run->forceFill(array_merge($filter->toColumns(), [
            'run_kind' => $kind,
            'status' => ImportRunStatus::Running,
            'actor_id' => $this->actorId(),
            'is_widened' => $filter->isWiderThanDefault(),
            'started_at' => CarbonImmutable::now(),
        ]));

        $run->save();

        return $run;
    }

    /**
     * Le balayage à reprendre, servi par l'index `(status)` — la raison d'être
     * de cet index (§ 9.1).
     */
    protected function resumableRun(ImportRunKind $kind): ?ImportRun
    {
        $query = ImportRun::query()
            ->where('status', ImportRunStatus::Running->value)
            ->where('run_kind', $kind->value)
            ->latest('id');

        $runId = $this->integerOption('run');

        if ($runId !== null) {
            $query->whereKey($runId);
        }

        return $query->first();
    }

    /**
     * Clôt un balayage. Un balayage **suspendu** — quota atteint, interruption,
     * plafond de pages de l'invocation — reste `running` : c'est exactement ce
     * que l'index `(status)` sert à retrouver au démarrage du worker suivant.
     */
    protected function closeRun(ImportRun $run, ImportRunStatus $status): void
    {
        $run->status = $status;

        if ($status !== ImportRunStatus::Running) {
            $run->finished_at = CarbonImmutable::now();
        }

        $run->save();
    }

    /**
     * Le compte rendu d'un balayage, en quatre compteurs plus la reprise.
     */
    protected function reportRun(ImportRun $run): void
    {
        $this->newLine();

        $this->table(
            ['Balayage', 'Vus', 'Importés', 'Écartés', 'Refusés (contenu)'],
            [[
                '#'.$run->id.' ('.$run->run_kind->value.')',
                (string) $run->total_seen,
                (string) $run->total_imported,
                (string) $run->total_skipped,
                (string) $run->total_refused_content,
            ]],
        );

        if ($run->status === ImportRunStatus::Running) {
            $this->components->warn(
                'Balayage #'.$run->id.' suspendu et REPRENABLE : relancez la même commande avec --resume.',
            );
        }
    }

    /**
     * Une ligne par film, en mode bavard seulement : sur 500 films, la barre de
     * progression est le seul affichage utile.
     */
    protected function reportOutcome(ImportOutcome $outcome): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $reason = $outcome->reasonKey === null
            ? ''
            : ' — '.$this->renderReason($outcome->reasonKey, $outcome->reasonReplacements);

        $line = '#'.$outcome->tmdbId.' : '.$outcome->decision->value.$reason;

        match ($outcome->decision) {
            ImportDecision::RefusedContent, ImportDecision::RefusedWithdrawn => $this->components->warn($line),
            default => $this->line('  '.$line),
        };
    }

    /**
     * Traduit une panne TMDB et dit si le balayage est suspendu ou échoué.
     *
     * **Le message brut de l'exception n'est jamais affiché** : un
     * `RequestException` de Guzzle porte l'URL complète, donc la clé v3
     * lorsqu'elle voyage en paramètre. Seuls la clé de traduction et ses
     * substitutions sortent.
     */
    protected function reportTmdbFailure(TmdbException $exception): ImportRunStatus
    {
        $message = __($exception->translationKey(), $exception->translationReplacements());

        $this->newLine();

        if ($exception->kind === TmdbErrorKind::RateLimited) {
            $this->components->warn('Quota TMDB atteint : '.(is_string($message) ? $message : $exception->translationKey()));

            return ImportRunStatus::Running;
        }

        if ($exception->isTransient()) {
            $this->components->warn('Panne passagère de TMDB : '.(is_string($message) ? $message : $exception->translationKey()));

            return ImportRunStatus::Running;
        }

        $this->components->error(is_string($message) ? $message : $exception->translationKey());

        return ImportRunStatus::Failed;
    }

    /**
     * Une barre de progression sans total connu d'avance : le balayage
     * découvre le nombre de films page après page.
     */
    protected function progressBar(int $max = 0): ProgressBar
    {
        $bar = $this->output->createProgressBar($max);
        $bar->start();

        return $bar;
    }

    /**
     * Une option numérique, quelle que soit la forme sous laquelle elle arrive.
     *
     * Symfony rend une chaîne en ligne de commande, mais `$this->artisan()`
     * passe la valeur PHP telle quelle : un `is_string()` nu laisserait
     * silencieusement retomber `--min-votes=10` sur le défaut du site dès
     * qu'un test l'exprime en entier, et le balayage ne serait pas celui qu'on
     * croit lancer.
     */
    protected function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * L'auteur du balayage, `nullOnDelete`. La console n'a pas de session :
     * l'option `--actor` nomme un compte, et une valeur inconnue est ignorée
     * plutôt que fatale — la traçabilité ne vaut pas qu'on refuse d'importer.
     */
    private function actorId(): ?int
    {
        $actor = $this->option('actor');

        if (! is_scalar($actor) || (string) $actor === '') {
            return null;
        }

        $actor = (string) $actor;

        $user = ctype_digit($actor)
            ? User::query()->whereKey((int) $actor)->first()
            : User::query()->where('email', $actor)->first();

        if (! $user instanceof User) {
            $this->components->warn('Auteur ['.$actor.'] inconnu : le balayage sera enregistré sans auteur.');

            return null;
        }

        return $user->id;
    }

    /**
     * Motif d'une décision d'import, rendu pour un humain.
     *
     * La clé et ses substitutions voyagent en DONNÉES jusqu'ici (règle 4) :
     * c'est l'affichage, et lui seul, qui les résout. Une clé absente du
     * dictionnaire est renvoyée telle quelle par le traducteur ; on retombe
     * alors sur la clé suivie de ses substitutions, qui reste diagnosticable,
     * plutôt que sur une phrase tronquée.
     *
     * @param  array<string, string|int>  $replacements
     */
    private function renderReason(string $key, array $replacements): string
    {
        $message = __($key, $replacements);

        if (is_string($message) && $message !== $key) {
            return $message;
        }

        return $key.$this->renderReplacements($replacements);
    }

    /**
     * @param  array<string, string|int>  $replacements
     */
    private function renderReplacements(array $replacements): string
    {
        if ($replacements === []) {
            return '';
        }

        $pairs = [];

        foreach ($replacements as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return ' ('.implode(', ', $pairs).')';
    }
}
