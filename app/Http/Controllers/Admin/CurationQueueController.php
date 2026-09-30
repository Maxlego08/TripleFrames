<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentFlag;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CurationQueueRequest;
use App\Models\Movie;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\CurationQueue;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La file de curation et « film suivant » — spec 20 § 4.1, ligne 3 de la
 * matrice des capacités (`can:viewAny,App\Models\Movie`).
 *
 * **Aucune écriture.** La file est une lecture ordonnée de {@see CurationQueue},
 * seul porteur de son périmètre, de son ordre et de ses filtres ; « film
 * suivant » n'est qu'une redirection vers l'éditeur de la banque d'images
 * (`admin.catalog.bank`), qui garde sa propre policy (`curate`).
 */
class CurationQueueController extends Controller
{
    /**
     * La file, une page à la fois, avec ses filtres et le reste à curer par
     * voie d'entrée.
     */
    public function index(CurationQueueRequest $request): Response
    {
        $movies = $request->queue()
            ->paginate(CurationQueueRequest::PER_PAGE)
            ->withQueryString();

        // Le rang dans la file ENTIÈRE filtrée, pas dans la page : « 26 » en
        // tête de la page 2 dit au curateur où il en est.
        $rank = $movies->firstItem() ?? 1;

        return Inertia::render('admin/curation/index', [
            'movies' => AdminCatalogPresenter::paginated(
                $movies,
                function (Movie $movie) use (&$rank): array {
                    return AdminCatalogPresenter::curationQueueRow($movie, CurationQueue::touchedAt($movie), $rank++);
                },
            ),
            'filters' => $request->filters(),
            'totals' => CurationQueue::totalsByEntry(),
            'options' => self::options(),
        ]);
    }

    /**
     * « Film suivant » (§ 4.1) : l'éditeur du premier film de la file autre que
     * le film courant, filtres conservés. File vide : retour à la file, avec
     * le message `admin.curation.empty` — jamais une page blanche ni une erreur.
     * Strate épuisée (un filtre posé) : `admin.curation.empty_stratum`, qui
     * invite à effacer les filtres plutôt qu'à importer — d'autres films
     * attendent peut-être hors de la strate.
     */
    public function next(CurationQueueRequest $request): RedirectResponse
    {
        $movie = $request->queue()->next($request->current());

        if (! $movie instanceof Movie) {
            $key = $request->filterQuery() === [] ? 'admin.curation.empty' : 'admin.curation.empty_stratum';

            Inertia::flash('toast', ['type' => 'info', 'message' => __($key)]);

            return to_route('admin.curation.index', $request->filterQuery());
        }

        return to_route('admin.catalog.bank', ['movie' => $movie->id, ...$request->filterQuery()]);
    }

    /**
     * Les listes blanches des filtres, relues de {@see CurationQueue} : un choix
     * offert est, par construction, un choix accepté.
     *
     * @return array{entry: list<string>, motive: list<string>, content_flag: list<string>}
     */
    private static function options(): array
    {
        return [
            'entry' => CurationQueue::ENTRIES,
            'motive' => array_keys(CurationQueue::MOTIVE_COLUMNS),
            'content_flag' => array_column(ContentFlag::cases(), 'value'),
        ];
    }
}
