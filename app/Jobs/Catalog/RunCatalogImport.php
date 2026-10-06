<?php

namespace App\Jobs\Catalog;

use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Models\ImportRun;
use App\Models\User;
use App\Support\Catalog\DiscoverCursor;
use App\Support\Catalog\ImportSnapshotGuard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * L'import déclenché depuis le back-office, **différé**, et c'est mesuré.
 *
 * Un balayage `discover` coûte DEUX appels TMDB par film retenu — la page, puis
 * le détail avec `append_to_response=release_dates`. À cinq pages, soit une
 * centaine de fiches, c'est environ 105 appels séquentiels : le limiteur les
 * espace déjà de 28,6 ms et la latence réelle de TMDB est de 150 à 400 ms, donc
 * 25 à 45 secondes de mur, avant la moindre écriture vers une base distante où
 * vivent aussi la session, le cache et la file. Le SAPI web porte
 * `max_execution_time = 30`, que le CLI seul écrase. **Les trois routes POST ne
 * font donc jamais d'appel TMDB dans la requête** : elles ouvrent la ligne
 * `import_run`, dispatchent ce job et redirigent aussitôt.
 *
 * **File par DÉFAUT, jamais une file de jeu** — c'est la même règle que la
 * purge (§ 11) : rien de ce qui peut durer une minute ne partage la file du
 * temps réel. Aucun `onQueue()` n'est donc posé ici.
 *
 * **Aucune logique d'import n'est réécrite.** Le job appelle la commande, déjà
 * couverte par la suite existante, avec `--resume --run=<id>` :
 * `CatalogImportCommand::resumableRun()` retrouve le balayage par son
 * identifiant, l'estampille `started_at` — ce qui fait basculer l'écran de « en
 * file » à « en cours » — et reprend exactement là où il en était.
 *
 * `$tries = 1` n'est pas une frilosité : un réessai rejouerait un curseur déjà
 * consommé et fausserait les quatre compteurs, qui sont la preuve opposable de
 * ce qu'un balayage a fait. L'unicité sur l'identifiant du balayage
 * (`ShouldBeUniqueUntilProcessing`) empêche deux jobs en file pour le même
 * curseur ; elle tombe au début du traitement, pour qu'un passage puisse
 * mettre en file le suivant. Deux traitements simultanés restent exclus : la
 * reprise manuelle exige un balayage inactif depuis cinq minutes
 * (`ImportLauncher::resume()`), et un passage avance `last_request_at` à
 * chaque appel.
 *
 * **Par passages** (D60 du 06/10) : un balayage de 100 pages, soit 2 000
 * fiches et autant d'appels de détail, dépasse de loin le délai d'un job. Un
 * passage enchaîne les pages une à une, au plus {@see self::BUDGET_SECONDS}
 * secondes, puis met en file le passage suivant avec les pages restantes ;
 * le curseur `tmdb_page_cursor`, écrit après chaque page, fait reprendre
 * exactement où le passage s'est arrêté. Entre deux passages, les jobs
 * d'image de la file `default` passent.
 *
 * **Ce qui borne réellement la durée ici, et ce qui ne la borne pas.**
 * `$timeout` et `$failOnTimeout` sont inertes dans cet environnement : Laravel
 * n'arme le délai d'un job que par `pcntl_alarm()`, et `ext-pcntl` est absente
 * (CLAUDE.md § 8). La seule borne active est le `--timeout=900` passé au
 * `queue:listen` de `composer dev`, appliqué par le processus PARENT, qui tue
 * l'enfant **sans** passer par `failed()`. Un balayage tué de cette façon
 * reste donc `running` jusqu'à la réservation suivante (`retry_after`), où la
 * tentative est comptée en trop et `failed()` s'exécute enfin. `$uniqueFor`
 * borne le verrou d'unicité pour que cette fenêtre n'immobilise pas la voie
 * indéfiniment ; les deux propriétés redeviennent utiles dès qu'un worker
 * tourne sous `pcntl`.
 *
 * **L'autorisation est vérifiée deux fois, et il le faut.** `can:create` tombe
 * à la mise en file ; l'écriture réelle, elle, a lieu ici, jusqu'à quinze
 * minutes plus tard. Entre les deux, l'auteur peut avoir été rétrogradé. Le
 * job relit donc le seuil avant d'appeler la commande. Un `actor_id` nul
 * passe : un compte supprimé n'invalide pas un journal de provenance.
 */
class RunCatalogImport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Un réessai rejouerait un curseur déjà consommé (§ 9.1). */
    public int $tries = 1;

    /** Quinze minutes : au-delà, c'est un incident, pas une lenteur. */
    public int $timeout = 900;

    public bool $failOnTimeout = true;

    /**
     * Secondes de travail d'un passage de balayage, bien sous `$timeout` : la
     * page en cours au moment du budget (une vingtaine de détails) doit finir
     * avant que le worker ne tue le job.
     */
    public const int BUDGET_SECONDS = 600;

    /**
     * Durée de vie du verrou d'unicité. Sans elle, un job tué par le parent
     * `queue:listen` — qui n'appelle jamais `failed()` — laisserait la clé
     * `catalog-import-<id>` tenue jusqu'au prochain passage par `failed()`, et
     * la reprise serait avalée en silence entre-temps.
     */
    public int $uniqueFor = 1800;

    /**
     * @param  int  $runId  la ligne `import_run` ouverte par le contrôleur
     * @param  int  $pages  pages TMDB autorisées à cette invocation (`discover`)
     * @param  list<int>  $identifiers  le collage, qu'aucune colonne du schéma ne porte
     */
    private function __construct(
        public readonly int $runId,
        public readonly ImportRunKind $kind,
        public readonly int $pages,
        public readonly array $identifiers,
    ) {}

    /**
     * Un balayage filtré, ou la reprise d'un balayage suspendu — le même job
     * dans les deux cas, la commande étant reprenable de bout en bout.
     */
    public static function discover(ImportRun $run, int $pages): self
    {
        return new self($run->id, ImportRunKind::Discover, max(1, $pages), []);
    }

    /**
     * Un collage. **La liste voyage dans la charge utile du job et nulle part
     * ailleurs** : aucune colonne de la spec 10 ne la porte, et en inventer une
     * serait empiéter sur le propriétaire du schéma. C'est aussi pourquoi un
     * collage interrompu n'est pas reprenable depuis l'écran — question
     * renvoyée à la spec 20.
     *
     * @param  list<int>  $identifiers
     */
    public static function paste(ImportRun $run, array $identifiers): self
    {
        return new self($run->id, ImportRunKind::Paste, 1, $identifiers);
    }

    /**
     * Deux workers ne traitent jamais le même curseur.
     */
    public function uniqueId(): string
    {
        return 'catalog-import-'.$this->runId;
    }

    public function handle(): void
    {
        if (! $this->actorStillAllowed()) {
            $this->closeAsFailed(onlyIfNeverStarted: false);

            return;
        }

        // Le chemin d'import ORDINAIRE (spec 30 § 13.2, spec 100 § 11.4) : la
        // garde d'instantané des commandes lancées à la main n'y joue pas. Un
        // vidage complet par balayage placerait un geste d'exploitation sur le
        // chemin du curateur (D10 du 23/09).
        if ($this->kind === ImportRunKind::Discover) {
            ImportSnapshotGuard::ordinaryPath(fn () => $this->sweepInPasses());

            return;
        }

        $status = ImportSnapshotGuard::ordinaryPath(fn (): int => Artisan::call('catalog:import-ids', [
            // La commande n'accepte que des chaînes en argument variadique :
            // un entier nu serait silencieusement ignoré par sa lecture, et
            // le collage partirait vide.
            'ids' => array_map(strval(...), $this->identifiers),
            '--resume' => true,
            '--run' => $this->runId,
        ]));

        // La commande clôt elle-même le balayage dans tous les cas qu'elle
        // connaît — suspendu, terminé, échoué. Reste le refus d'entrée : sans
        // clé TMDB, elle sort avant d'avoir touché la ligne, et un balayage
        // resterait « en file » pour toujours. `started_at` est le témoin : la
        // reprise l'estampille, donc une ligne encore nulle prouve que la
        // commande n'a jamais démarré.
        if ($status !== 0) {
            $this->closeAsFailed(onlyIfNeverStarted: true);
        }
    }

    /**
     * Un passage de balayage : une page par appel de la commande, tant que le
     * budget de temps et les pages demandées le permettent ; puis, s'il reste
     * des pages et que le balayage avance, le passage suivant en file.
     *
     * Trois arrêts, et la commande les connaît tous : balayage terminé ou
     * échoué (la ligne n'est plus `running`) ; balayage suspendu sur une panne
     * TMDB (le curseur n'a pas bougé : la page sera rejouée par « Reprendre »,
     * jamais en boucle ici) ; refus d'entrée (code non nul avant tout
     * démarrage).
     */
    private function sweepInPasses(): void
    {
        $deadline = CarbonImmutable::now()->addSeconds(self::BUDGET_SECONDS);
        $remaining = $this->pages;

        while ($remaining > 0) {
            $before = self::cursorOf(ImportRun::query()->find($this->runId));

            $status = Artisan::call('catalog:import-discover', [
                '--resume' => true,
                '--run' => $this->runId,
                '--pages' => 1,
            ]);

            if ($status !== 0) {
                $this->closeAsFailed(onlyIfNeverStarted: true);

                return;
            }

            $run = ImportRun::query()->find($this->runId);

            if (! $run instanceof ImportRun || $run->status !== ImportRunStatus::Running || self::cursorOf($run) === $before) {
                return;
            }

            $remaining--;

            if ($remaining > 0 && CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                dispatch(self::discover($run, $remaining));

                return;
            }
        }
    }

    /**
     * La position du balayage, un curseur nul valant le début : la première
     * page manquée écrit `0`, ce qui n'est pas une avancée.
     */
    private static function cursorOf(?ImportRun $run): int
    {
        return DiscoverCursor::fromColumn($run?->tmdb_page_cursor)->toColumn();
    }

    /**
     * L'auteur du balayage est-il toujours au-dessus du seuil ?
     *
     * `ImportRunPolicy::create` tombe au moment du dispatch ; l'écriture réelle
     * a lieu ici, plus tard. Un curateur rétrogradé entre les deux ne doit pas
     * faire entrer des films au catalogue sous une autorité qu'il n'a plus.
     *
     * Un `actor_id` nul passe : `nullOnDelete` efface l'auteur, pas le journal
     * de provenance, et un compte supprimé ne doit pas transformer un balayage
     * légitime en échec.
     */
    private function actorStillAllowed(): bool
    {
        $run = ImportRun::query()->find($this->runId);

        if (! $run instanceof ImportRun || $run->actor_id === null) {
            return true;
        }

        $actor = User::query()->find($run->actor_id);

        if (! $actor instanceof User) {
            return true;
        }

        return Gate::forUser($actor)->allows('create', ImportRun::class);
    }

    /**
     * Un job perdu ne laisse jamais un balayage « en cours » pour l'éternité :
     * l'écran doit rester lisible par un non-technicien, et « échoué » est un
     * état rejouable là où « en cours » est une impasse muette.
     */
    public function failed(?Throwable $exception): void
    {
        $this->closeAsFailed(onlyIfNeverStarted: false);
    }

    /**
     * Clôture en échec, par une mise à jour conditionnelle : un balayage déjà
     * terminé ou déjà échoué n'est jamais réécrit, et ses quatre compteurs
     * restent la preuve de ce qu'il a fait.
     */
    private function closeAsFailed(bool $onlyIfNeverStarted): void
    {
        $now = CarbonImmutable::now();

        $query = ImportRun::query()
            ->whereKey($this->runId)
            ->where('status', ImportRunStatus::Running->value);

        if ($onlyIfNeverStarted) {
            $query->whereNull('started_at');
        }

        $query->update([
            'status' => ImportRunStatus::Failed->value,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
