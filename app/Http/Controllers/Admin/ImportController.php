<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\CatalogImportValidationRules;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Les deux voies d'import et leur journal — en lecture.
 *
 * L'ASYMÉTRIE est le sujet de l'écran, et elle est dite avant les formulaires :
 * le balayage `discover` applique le filtre de NOTORIÉTÉ, le collage
 * d'identifiants l'ignore entièrement et marque chaque film « entré par
 * exception » avec son motif. Les filtres de CONTENU, eux, ne sont
 * contournables par AUCUNE des deux voies (décision 12).
 *
 * Le détail d'un balayage n'est pas un écran d'anticipation : l'import étant
 * différé, un curateur qui lance un balayage n'a aucun autre endroit où lire ce
 * qu'il a fait ni pourquoi il s'est arrêté — et la décision 11 exige qu'un film
 * entré par exception soit auditable, ce qui suppose de remonter au balayage et
 * à son filtre exact.
 */
class ImportController extends Controller
{
    use CatalogImportValidationRules;

    /** Une page de journal d'import : l'écran porte deux formulaires au-dessus. */
    private const int RUNS_PER_PAGE = 10;

    /**
     * Les deux voies, et l'historique paginé des balayages.
     */
    public function index(TmdbClient $tmdb): Response
    {
        $runs = ImportRun::query()
            ->with('actor:id,name')
            ->latest('id')
            ->paginate(self::RUNS_PER_PAGE)
            ->withQueryString();

        // `started_at` non nul : un balayage encore « en file » n'est pas à
        // reprendre, il est à attendre — le job dort déjà dans la file, et
        // `ShouldBeUnique` avalerait un second dispatch en silence.
        $resumable = ImportRun::query()
            ->with('actor:id,name')
            ->where('run_kind', ImportRunKind::Discover->value)
            ->where('status', ImportRunStatus::Running->value)
            ->whereNotNull('started_at')
            ->latest('id')
            ->first();

        return Inertia::render('admin/import/index', [
            'runs' => AdminCatalogPresenter::paginated(
                $runs,
                fn (ImportRun $run): array => AdminCatalogPresenter::importRunRow($run),
            ),
            'defaults' => self::defaults(),
            'resumable' => $resumable === null
                ? null
                : AdminCatalogPresenter::importRunRow($resumable),
            'tmdb_configured' => $tmdb->isConfigured(),
        ]);
    }

    /**
     * Le détail d'un balayage : résumé, filtre figé, compteurs, curseur, et la
     * liste paginée des films que CE balayage a fait entrer — servie par
     * `index(import_run_id)` sur `movie`.
     */
    public function show(ImportRun $importRun, TmdbClient $tmdb): Response
    {
        $importRun->load('actor:id,name');

        $movies = Movie::query()
            ->with('projection')
            ->where('import_run_id', $importRun->id)
            ->orderBy('id')
            ->paginate(CatalogIndexRequest::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/import/show', [
            'run' => AdminCatalogPresenter::importRunDetail($importRun),
            'movies' => AdminCatalogPresenter::paginated(
                $movies,
                fn (Movie $movie): array => AdminCatalogPresenter::movieRow($movie),
            ),
            'can_resume' => AdminCatalogPresenter::isResumable($importRun),
            // Sans clé TMDB, la reprise échouera : l'écran d'import le dit
            // déjà pour ses deux voies, la fiche d'un balayage le doit aussi.
            // Deux écrans qui se contredisent sur le même état font douter du
            // plus juste des deux.
            'tmdb_configured' => $tmdb->isConfigured(),
        ]);
    }

    /**
     * Les valeurs qui pré-remplissent et bornent les deux formulaires.
     *
     * Elles viennent toutes de la configuration — `config('catalog.import_filter')`
     * par {@see ImportFilter::default()} et `config('catalog.import')` pour les
     * deux plafonds — et jamais d'un littéral d'écran. `language_choices` est une
     * liste de SUGGESTIONS : la validation accepte n'importe quel code de deux
     * lettres, et c'est ce qui rend un balayage élargi possible, donc traçable.
     *
     * @return array{
     *     min_vote_count: int,
     *     languages: list<string>,
     *     language_choices: list<string>,
     *     min_release_year: int,
     *     pages_min: int,
     *     pages_max: int,
     *     pages_default: int,
     *     paste_max_ids: int,
     * }
     */
    public static function defaults(): array
    {
        $filter = ImportFilter::default();

        /** @var list<string> $choices */
        $choices = [];

        foreach (Config::array('catalog.import.language_choices', []) as $language) {
            if (is_string($language) && ! in_array($language, $choices, true)) {
                $choices[] = $language;
            }
        }

        foreach ($filter->languages as $language) {
            if (! in_array($language, $choices, true)) {
                $choices[] = $language;
            }
        }

        return [
            'min_vote_count' => $filter->minVoteCount,
            'languages' => $filter->languages,
            'language_choices' => $choices,
            'min_release_year' => $filter->minReleaseYear,
            'pages_min' => self::pagesMin(),
            'pages_max' => self::pagesMax(),
            'pages_default' => self::pagesMin(),
            'paste_max_ids' => self::pasteMaxIds(),
        ];
    }
}
