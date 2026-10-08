<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentAvailability;
use App\Enums\ImportRunKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieResyncRequest;
use App\Jobs\Catalog\RunCatalogImport;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\ImportLauncher;
use App\Support\Catalog\MovieImporter;
use App\Support\Catalog\ResyncDiff;
use App\Support\Tmdb\TmdbClient;
use App\Support\Tmdb\TmdbException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La resynchronisation TMDB depuis l'écran (spec 20 § 3.7, ligne 26, L20-24).
 *
 * - **`show`** : la sélection, film par film — chacun éligible ou écarté avec
 *   son motif (retiré, de démonstration, sans identifiant TMDB) — et, pour un
 *   film seul, l'**écran de différences** : la fiche TMDB relue maintenant
 *   (appel interactif, comme la recherche), appliquée puis annulée par
 *   {@see MovieImporter::previewResync()}, bornée à la liste close du § 9.3.
 *   Une certification restrictive y est annoncée, avec la proposition
 *   « Dépublier » — jamais prononcée par la resynchronisation.
 * - **`store`** : ouvre un balayage `resync` sous verrou et le met en file,
 *   un film ou un lot — **aucun appel TMDB dans une requête POST** (règle
 *   des trois voies d'import, `RunCatalogImport`). Le résumé est l'écran du
 *   balayage. Chaque film dont une valeur change écrit `movie.resynced`.
 *
 * Le filtre d'import n'est jamais réappliqué ; le catalogue de démonstration
 * n'est jamais candidat, un film retiré non plus (son `tmdb_id` est le blocage
 * de réimport, spec 10 A10).
 */
class MovieResyncController extends Controller
{
    /** Motifs d'exclusion d'un film de la sélection, clés de `admin.resync.ineligible.*`. */
    public const string INELIGIBLE_WITHDRAWN = 'withdrawn';

    public const string INELIGIBLE_DEMO = 'demo';

    public const string INELIGIBLE_NO_TMDB = 'no_tmdb';

    public function show(MovieResyncRequest $request, MovieImporter $importer, TmdbClient $tmdb): Response
    {
        $movies = $this->selection($request->movieIds());

        $eligible = array_values(array_filter(
            $movies,
            static fn (Movie $movie): bool => self::ineligibility($movie) === null,
        ));

        $preview = count($movies) === 1 && count($eligible) === 1
            ? $this->preview($eligible[0], $importer, $tmdb)
            : null;

        $openRun = ImportLauncher::openRun(ImportRunKind::Resync);

        return Inertia::render('admin/catalog/resync', [
            'movies' => array_map(static fn (Movie $movie): array => [
                'id' => $movie->id,
                'tmdb_id' => $movie->tmdb_id,
                'title_original' => $movie->title_original,
                'release_year' => $movie->release_year,
                'availability' => $movie->availability->value,
                'content_flag' => $movie->content_flag->value,
                'ineligible' => self::ineligibility($movie),
            ], $movies),
            'eligible_count' => count($eligible),
            'max_movies' => MovieResyncRequest::maxMovies(),
            'preview' => $preview,
            'tmdb_configured' => $tmdb->isConfigured(),
            'open_run' => $openRun === null ? null : ['id' => $openRun->id],
        ]);
    }

    public function store(MovieResyncRequest $request, TmdbClient $tmdb): RedirectResponse
    {
        if (! $tmdb->isConfigured()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.error.tmdb_disabled')]);

            return back();
        }

        /** @var list<int> $tmdbIds */
        $tmdbIds = [];

        foreach ($this->selection($request->movieIds()) as $movie) {
            if (self::ineligibility($movie) === null && $movie->tmdb_id !== null) {
                $tmdbIds[] = $movie->tmdb_id;
            }
        }

        if ($tmdbIds === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.resync.none_eligible')]);

            return back();
        }

        /** @var User $actor */
        $actor = $request->user();

        $run = ImportLauncher::openResync($actor);

        if ($run === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.resync.busy')]);

            return back();
        }

        dispatch(RunCatalogImport::resync($run, $tmdbIds));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.resync.started')]);

        return to_route('admin.import.show', ['importRun' => $run->id]);
    }

    /**
     * Le motif qui écarte un film de la resynchronisation, ou `null`.
     */
    public static function ineligibility(Movie $movie): ?string
    {
        return match (true) {
            $movie->availability === ContentAvailability::Withdrawn => self::INELIGIBLE_WITHDRAWN,
            ! $movie->import_source->isResyncable() => self::INELIGIBLE_DEMO,
            $movie->tmdb_id === null => self::INELIGIBLE_NO_TMDB,
            default => null,
        };
    }

    /**
     * Les films de la sélection, dans l'ordre reçu.
     *
     * @param  list<int>  $ids
     * @return list<Movie>
     */
    private function selection(array $ids): array
    {
        $found = Movie::query()->whereKey($ids)->get()->keyBy('id');

        $movies = [];

        foreach ($ids as $id) {
            $movie = $found->get($id);

            if ($movie instanceof Movie) {
                $movies[] = $movie;
            }
        }

        return $movies;
    }

    /**
     * L'écran de différences d'un film : la fiche TMDB relue, appliquée puis
     * annulée. Une panne de TMDB, un identifiant inconnu ou une clé absente
     * rendent un état nommé, jamais une page d'erreur (§ 3.6).
     *
     * @return array<string, mixed>
     */
    private function preview(Movie $movie, MovieImporter $importer, TmdbClient $tmdb): array
    {
        if (! $tmdb->isConfigured() || $movie->tmdb_id === null) {
            return ['status' => 'not_configured'];
        }

        try {
            $detail = $tmdb->movie($movie->tmdb_id);
        } catch (TmdbException $exception) {
            Log::warning('Resynchronisation : lecture TMDB impossible.', [
                'movie_id' => $movie->id,
                'kind' => $exception->kind->value,
            ]);

            return ['status' => 'unavailable'];
        }

        if ($detail === null) {
            return ['status' => 'not_found'];
        }

        $diff = $importer->previewResync($movie, $detail);

        return [
            'status' => 'ready',
            ...$this->presentDiff($diff),
            'propose_unpublish' => $diff->contentFlagBlocked
                && $movie->availability === ContentAvailability::Published
                && Gate::allows('unpublish', $movie),
        ];
    }

    /**
     * @return array{rows: list<array{field: string, changed: bool, before: list<string>, after: list<string>}>, changed: list<string>, content_flag_blocked: bool, reappeared_aliases: list<string>, curator_title_locales: list<string>, certifications_read_at: string|null}
     */
    private function presentDiff(ResyncDiff $diff): array
    {
        return [
            'rows' => $diff->rows(),
            'changed' => $diff->changed,
            'content_flag_blocked' => $diff->contentFlagBlocked,
            'reappeared_aliases' => $diff->reappearedAliases,
            'curator_title_locales' => $diff->before->curatorTitleLocales,
            'certifications_read_at' => $diff->before->certificationsReadAt,
        ];
    }
}
