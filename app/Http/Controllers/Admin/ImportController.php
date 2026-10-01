<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\CatalogImportValidationRules;
use App\Enums\ImportRunKind;
use App\Enums\ImportRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Http\Requests\Admin\ImportSearchRequest;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\Theme;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Admin\PastePreview;
use App\Support\Admin\SeedList;
use App\Support\Tmdb\TmdbClient;
use App\ValueObjects\Catalog\ImportFilter;
use Illuminate\Http\Request;
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
     * Les deux voies, la liste d'amorçage, le dernier aperçu à blanc de
     * l'auteur, et l'historique paginé des balayages.
     *
     * **Aucun limiteur sur cette route** (spec 20 § 3.4) : c'est elle que
     * l'aperçu d'un collage sonde par rechargement partiel jusqu'à complétude,
     * et `admin-import` est écrit pour les écritures — l'y poser donnerait des
     * 429 en plein aperçu et consommerait le quota des vrais imports.
     */
    public function index(Request $request, TmdbClient $tmdb): Response
    {
        return Inertia::render('admin/import/index', self::screenProps($request, $tmdb));
    }

    /**
     * Le présentateur COMMUN de l'écran d'import : `admin.import.index` le rend
     * seul, `admin.import.search` le rend avec `search_results` en plus. Deux
     * routes, une page, et les mêmes props — sans quoi l'écran de recherche
     * perdrait le journal, la liste d'amorçage ou l'aperçu en cours.
     *
     * Chaque prop coûteuse est une FERMETURE : un rechargement partiel n'évalue
     * que ce qu'il demande. Le sondage de l'aperçu (`only: ['paste_preview']`)
     * ne relit jamais la liste d'amorçage ; le rafraîchissement du journal
     * (`only: ['runs', 'resumable', 'seed_list']`), qui ne tourne que tant
     * qu'un balayage est ouvert, la relit, parce qu'elle porte le verrou du
     * collage : sans elle, la fin d'un collage laisserait les boutons
     * « Importer » inactifs jusqu'à un rechargement manuel.
     *
     * @return array<string, mixed>
     */
    public static function screenProps(Request $request, TmdbClient $tmdb): array
    {
        $userId = (int) $request->user()?->getAuthIdentifier();

        return [
            'runs' => fn (): array => AdminCatalogPresenter::paginated(
                ImportRun::query()
                    ->with('actor:id,name')
                    ->latest('id')
                    ->paginate(self::RUNS_PER_PAGE)
                    ->withQueryString(),
                fn (ImportRun $run): array => AdminCatalogPresenter::importRunRow($run),
            ),
            'defaults' => self::defaults(),
            'resumable' => fn (): ?array => self::resumable(),
            'tmdb_configured' => $tmdb->isConfigured(),
            'seed_list' => fn (): array => SeedList::summary(),
            'paste_preview' => fn (): ?array => self::pastePreview($userId),
            // Les thèmes proposables au collage (D43 du 01/10, spec 20 § 3.3) :
            // tous, publiés ou non, groupés par nature à l'écran. Fermeture :
            // ni le sondage de l'aperçu ni le rafraîchissement du journal ne
            // les relisent.
            'themes' => fn (): array => self::themes(),
            'poll_seconds' => max(1, Config::integer('catalog.curation.poll_seconds')),
        ];
    }

    /**
     * Le balayage `discover` proposé à la reprise.
     *
     * `started_at` non nul : un balayage encore « en file » n'est pas à
     * reprendre, il est à attendre — le job dort déjà dans la file, et
     * `ShouldBeUnique` avalerait un second dispatch en silence.
     *
     * @return array<string, mixed>|null
     */
    private static function resumable(): ?array
    {
        $resumable = ImportRun::query()
            ->with('actor:id,name')
            ->where('run_kind', ImportRunKind::Discover->value)
            ->where('status', ImportRunStatus::Running->value)
            ->whereNotNull('started_at')
            ->latest('id')
            ->first();

        return $resumable === null ? null : AdminCatalogPresenter::importRunRow($resumable);
    }

    /**
     * Tous les thèmes, dans l'ordre du sélecteur du lobby — deux requêtes,
     * thèmes et libellés, quel que soit leur nombre.
     *
     * @return list<array{id: int, key: string, label: string, kind: string, is_published: bool}>
     */
    private static function themes(): array
    {
        $themes = [];

        foreach (Theme::query()->with('labels')->orderBy('sort_order')->orderBy('key')->get() as $theme) {
            $themes[] = AdminCatalogPresenter::availableTheme($theme);
        }

        return $themes;
    }

    /**
     * Le dernier aperçu à blanc de CE compte, ou `null` : la clé du cache porte
     * l'identifiant de l'auteur, si bien qu'aucun autre compte ne le lit.
     *
     * @return array<string, mixed>|null
     */
    private static function pastePreview(int $userId): ?array
    {
        $state = $userId > 0 ? PastePreview::latest($userId) : null;

        return $state === null ? null : PastePreview::toProps($state);
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
     *     paste_max_themes: int,
     *     search_min_length: int,
     *     search_max_length: int,
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
            'paste_max_themes' => self::pasteMaxThemes(),
            'search_min_length' => ImportSearchRequest::QUERY_MIN_LENGTH,
            'search_max_length' => ImportSearchRequest::QUERY_MAX_LENGTH,
        ];
    }
}
