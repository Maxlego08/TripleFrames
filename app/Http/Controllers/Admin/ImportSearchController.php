<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportSearchRequest;
use App\Models\Movie;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbErrorKind;
use App\Support\Tmdb\TmdbException;
use App\Support\Tmdb\TmdbMovieSummary;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La recherche TMDB et l'import unitaire — spec 20 § 3.4 (question 19).
 *
 * **Une route dédiée, à son propre limiteur** (`throttle:admin-tmdb-search`,
 * `catalog.curation.rate_limits.search` par minute et par utilisateur) : la
 * recherche appelle TMDB DANS la requête — un appel, une page —, là où les
 * écritures d'import ne le font jamais. Elle rend l'écran d'import entier
 * ({@see ImportController::screenProps()}) plus `search_results`.
 *
 * Chaque résultat porte son **état local** : déjà au catalogue, avec sa
 * disponibilité, ou retiré — réimport bloqué (spec 10 A10). « Importer »
 * poste UN identifiant sur `admin.import.ids` : c'est un collage, donc
 * toujours marqué exception, même si le film satisfait le filtre de goût
 * (spec 10 § 9.2), et soumis au verrou d'un seul collage ouvert à la fois.
 *
 * **Une panne n'est jamais une page d'erreur** (décision 9, § 3.6) : elle
 * devient un état — quota atteint, TMDB indisponible, clé absente — que
 * l'écran traduit et rend rejouable d'un bouton. Aucun DTO de
 * `App\Support\Tmdb` ne quitte ce contrôleur (`TmdbBoundaryTest`).
 */
class ImportSearchController extends Controller
{
    /** Des résultats à afficher. */
    public const string READY = 'ready';

    /** TMDB n'a rien trouvé. */
    public const string EMPTY = 'empty';

    /** Quota TMDB atteint sur cet appel interactif : « Réessayer ». */
    public const string RATE_LIMITED = 'rate_limited';

    /** Toute autre panne : « Réessayer », le détail part au journal. */
    public const string FAILED = 'failed';

    /** Aucune clé TMDB configurée : rien à réessayer. */
    public const string NOT_CONFIGURED = 'not_configured';

    /**
     * L'écran d'import, et les résultats de la recherche `q`.
     */
    public function index(ImportSearchRequest $request, TmdbClient $tmdb): Response
    {
        $query = $request->searchQuery();

        return Inertia::render('admin/import/index', [
            ...ImportController::screenProps($request, $tmdb),
            'search_results' => fn (): array => $this->results($tmdb, $query),
        ]);
    }

    /**
     * La première page de résultats, marquée contre le catalogue local.
     *
     * @return array{query: string, status: string, error_key: string|null, total_results: int, items: list<array<string, mixed>>}
     */
    private function results(TmdbClient $tmdb, string $query): array
    {
        if (! $tmdb->isConfigured()) {
            return self::state($query, self::NOT_CONFIGURED, 'admin.error.tmdb_disabled');
        }

        try {
            $page = $tmdb->search($query);
        } catch (TmdbException $exception) {
            Log::warning('Recherche TMDB du back-office en échec.', [
                'kind' => $exception->kind->value,
                'status' => $exception->httpStatus,
            ]);

            return match ($exception->kind) {
                TmdbErrorKind::RateLimited => self::state($query, self::RATE_LIMITED, 'admin.tmdb.error.rate_limited_interactive'),
                TmdbErrorKind::NotConfigured => self::state($query, self::NOT_CONFIGURED, 'admin.error.tmdb_disabled'),
                TmdbErrorKind::NotFound => self::state($query, self::EMPTY),
                TmdbErrorKind::Unauthorized, TmdbErrorKind::ServerError, TmdbErrorKind::Transport,
                TmdbErrorKind::Malformed, TmdbErrorKind::UnexpectedStatus, TmdbErrorKind::TooLarge => self::state($query, self::FAILED, 'admin.import.search.failed'),
            };
        }

        // Défense en profondeur : TMDB filtre déjà le contenu adulte à la
        // source (`include_adult=false`), et l'import le refuserait de toute
        // façon. Il n'est même pas proposé.
        $summaries = array_values(array_filter(
            $page->results,
            static fn (TmdbMovieSummary $summary): bool => ! $summary->adult,
        ));

        if ($summaries === []) {
            return self::state($query, self::EMPTY);
        }

        $known = Movie::query()
            ->whereIn('tmdb_id', array_map(static fn (TmdbMovieSummary $summary): int => $summary->tmdbId, $summaries))
            ->get(['id', 'tmdb_id', 'availability'])
            ->keyBy('tmdb_id');

        $items = [];

        foreach ($summaries as $summary) {
            $movie = $known->get($summary->tmdbId);

            $items[] = [
                'tmdb_id' => $summary->tmdbId,
                'title' => $summary->title,
                'title_original' => $summary->originalTitle,
                'release_year' => $summary->releaseYear(),
                'original_language' => $summary->originalLanguage,
                'vote_count' => $summary->voteCount,
                'catalog' => $movie instanceof Movie
                    ? ['movie_id' => $movie->id, 'availability' => $movie->availability->value]
                    : null,
            ];
        }

        return [
            ...self::state($query, self::READY),
            'total_results' => $page->totalResults,
            'items' => $items,
        ];
    }

    /**
     * Un état sans résultat : la recherche, l'état, et la clé du message.
     *
     * @return array{query: string, status: string, error_key: string|null, total_results: int, items: list<array<string, mixed>>}
     */
    private static function state(string $query, string $status, ?string $errorKey = null): array
    {
        return [
            'query' => $query,
            'status' => $status,
            'error_key' => $errorKey,
            'total_results' => 0,
            'items' => [],
        ];
    }
}
