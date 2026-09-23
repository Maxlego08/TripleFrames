<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Models\Alias;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Support\Admin\AdminCatalogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le catalogue, en **lecture seule**.
 *
 * Publier, dépublier, suspendre, retirer, corriger un titre, cocher « contenu
 * vérifié » : chacun de ces gestes a son propre seuil — curateur pour la
 * publication, administrateur seul pour la suspension et le retrait juridique —
 * et tous sont tranchés par la spec 20. Aucune méthode d'écriture n'est écrite
 * ici, et aucune policy fantôme ne les annonce.
 *
 * La liste est **paginée et jointe**, jamais chargée puis filtrée en PHP, et
 * elle ne fait jamais N+1 : la projection de chaque film est chargée d'avance
 * en une requête, et la jointure qui sert le tri et le filtre « jouable à N »
 * est une 1:1 stricte, donc sans duplication de ligne.
 *
 * **Honnêteté sur les index** (§ 3.1) : `movie` n'indexe volontairement ni
 * `is_import_exception`, ni `release_year`, ni `movie_difficulty`, ni
 * `import_source`, ni `created_at` ; et `content_flag` employé SEUL n'est pas
 * un préfixe gauche de `movie_pool_idx (availability, content_flag, id)`, donc
 * pas davantage servi. La recherche libre est un `LIKE` sans index et sans
 * fulltext. Les tris sur `levels_count` et `variants_total` passent par un
 * filesort sur le jeu filtré : la requête pilote est `movie`,
 * `movie_projection` est joint par sa clé primaire, et
 * `movie_projection_levels_idx` n'est donc PAS emprunté pour cet `ORDER BY`.
 * Tout cela est assumé à l'échelle de quelques centaines de lignes, et cette
 * échelle est celle du catalogue entier — le § 3.1 possède les index, et il a
 * refusé ceux-là.
 */
class CatalogController extends Controller
{
    /**
     * La liste du catalogue, entièrement pilotée par la query string.
     */
    public function index(CatalogIndexRequest $request): Response
    {
        $movies = $this->filtered($request, applyException: true)
            ->paginate(CatalogIndexRequest::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/catalog/index', [
            'movies' => AdminCatalogPresenter::paginated(
                $movies,
                fn (Movie $movie): array => AdminCatalogPresenter::movieRow($movie),
            ),
            'filters' => $request->filters(),
            'facets' => $this->facets($request),
            'options' => self::options(),
        ]);
    }

    /**
     * La fiche film — identité, disponibilité, titres, alias, étiquettes,
     * projection, thèmes, banque d'images et provenance.
     *
     * Un nombre de requêtes **constant**, indépendant du nombre de titres,
     * d'alias, d'étiquettes, de thèmes et d'images : le film et ses relations
     * de tête en un `load()`, puis une requête par liste, chacune préchargeant
     * ce qu'elle affiche. C'est l'absence de N+1 qui est garantie et testée,
     * pas un décompte — un chiffre écrit ici se lirait comme un budget à ne
     * pas dépasser, ou comme la preuve qu'il reste de la marge. **Aucun chemin
     * de fichier ne quitte le serveur** — la banque d'images n'expose que
     * niveau, disponibilité et état de traitement (§ 10).
     */
    public function show(Movie $movie): Response
    {
        $movie->load([
            'projection',
            'collection:id,name',
            'group:id,label',
            'contentVerifiedBy:id,name',
            'curatedBy:id,name',
            'importRun.actor:id,name',
        ]);

        $projection = AdminCatalogPresenter::projectionOf($movie);

        return Inertia::render('admin/catalog/show', [
            'movie' => AdminCatalogPresenter::movieDetail($movie),
            'projection' => $projection === null
                ? null
                : AdminCatalogPresenter::movieProjection($projection),
            'titles' => $this->titles($movie),
            'aliases' => $this->aliases($movie),
            'certifications' => $this->certifications($movie),
            'tags' => $this->tags($movie),
            'themes' => $this->themes($movie),
            'frames' => $this->frames($movie),
            'import_run' => $movie->importRun === null
                ? null
                : AdminCatalogPresenter::importRunRow($movie->importRun),
        ]);
    }

    /**
     * Les listes blanches de l'écran, relues du FormRequest qui les possède :
     * un choix offert est, par construction, un choix accepté.
     *
     * @return array{
     *     availability: list<string>,
     *     content_flag: list<string>,
     *     import_source: list<string>,
     *     playable_at: list<int>,
     *     exception: list<string>,
     *     sort: list<string>,
     *     direction: list<string>,
     * }
     */
    public static function options(): array
    {
        return [
            'availability' => array_column(ContentAvailability::cases(), 'value'),
            'content_flag' => array_column(ContentFlag::cases(), 'value'),
            'import_source' => array_column(ImportSource::cases(), 'value'),
            'playable_at' => range(
                CatalogIndexRequest::PLAYABLE_AT_MIN,
                CatalogIndexRequest::PLAYABLE_AT_MAX,
            ),
            'exception' => CatalogIndexRequest::EXCEPTIONS,
            'sort' => CatalogIndexRequest::SORTS,
            'direction' => CatalogIndexRequest::DIRECTIONS,
        ];
    }

    /**
     * La requête filtrée et triée.
     *
     * `$applyException` est le seul paramètre, et il porte toute la sémantique
     * de la facette : les quatre compteurs d'exception sont mesurés sur le jeu
     * filtré COURANT **privé de la seule facette `exception`**. Sans cela, un
     * curateur filtrant sur « motif langue » lirait quatre compteurs dont trois
     * valent zéro par construction — c'est-à-dire une facette qui ne sert plus
     * à rien.
     *
     * La jointure sur `movie_projection` est une 1:1 stricte (clé primaire
     * `movie_id`) : elle ne duplique aucune ligne, et `select('movie.*')` garde
     * l'hydratation propre là où `created_at` existe des deux côtés.
     *
     * @return Builder<Movie>
     */
    private function filtered(CatalogIndexRequest $request, bool $applyException): Builder
    {
        $query = Movie::query()
            ->select('movie.*')
            ->leftJoin('movie_projection', 'movie_projection.movie_id', '=', 'movie.id')
            ->with('projection');

        $search = $request->search();

        if ($search !== null) {
            $query->where(function (Builder $scoped) use ($search): void {
                $scoped->where('movie.title_original', 'like', '%'.$search.'%')
                    ->orWhere('movie.title_original_latin', 'like', '%'.$search.'%');

                // Égalité sur l'identifiant TMDB, et seulement si la saisie est
                // un nombre : c'est la recherche que fait vraiment un curateur
                // qui colle un identifiant depuis TMDB, et elle est servie par
                // `movie_tmdb_uq`.
                if (ctype_digit($search)) {
                    $scoped->orWhere('movie.tmdb_id', (int) $search);
                }
            });
        }

        foreach (['availability', 'content_flag', 'import_source'] as $column) {
            $value = $request->filters()[$column] ?? null;

            if (is_string($value)) {
                $query->where('movie.'.$column, $value);
            }
        }

        $playableAt = $request->playableAt();

        if ($playableAt !== null) {
            $query->where('movie_projection.levels_count', '>=', $playableAt);
        }

        if ($applyException) {
            $this->applyExceptionFilter($query, $request);
        }

        return $this->sorted($query, $request);
    }

    /**
     * Le filtre exigé par la décision 11. `any` est le fait d'être entré par
     * exception — **jamais dérivable** des trois motifs, d'où la quatrième
     * colonne ; les trois autres valeurs ajoutent leur motif, qui est cumulable
     * avec les autres et n'a donc pas à être exclusif.
     *
     * @param  Builder<Movie>  $query
     */
    private function applyExceptionFilter(Builder $query, CatalogIndexRequest $request): void
    {
        $exception = $request->filters()['exception'];

        if ($exception === null) {
            return;
        }

        $query->where('movie.is_import_exception', true);

        $motive = match ($exception) {
            'language' => 'movie.exception_for_language',
            'vote_count' => 'movie.exception_for_vote_count',
            'release_year' => 'movie.exception_for_release_year',
            default => null,
        };

        if ($motive !== null) {
            $query->where($motive, true);
        }
    }

    /**
     * Le tri, toujours départagé par `movie.id` : sans second critère, deux
     * films de même année s'échangeraient de place d'une page à l'autre et la
     * pagination mentirait.
     *
     * `created_at` trie sur `movie.created_at`, la colonne que la tête de
     * colonne annonce — et non sur l'auto-incrément. L'invariant « `movie.id`
     * croît avec la date d'entrée » ne tient que pour les lignes écrites par
     * l'import : il est faux dès qu'un `created_at` est posé à la main, et le
     * bloc « films non curés » du tableau de bord trie, lui, sur la date. Deux
     * écrans qui ouvrent la même file dans deux ordres différents sont pires
     * qu'un filesort sur quelques centaines de lignes.
     *
     * @param  Builder<Movie>  $query
     * @return Builder<Movie>
     */
    private function sorted(Builder $query, CatalogIndexRequest $request): Builder
    {
        $direction = $request->direction();

        $column = match ($request->sort()) {
            'created_at' => 'movie.created_at',
            'title_original' => 'movie.title_original',
            'release_year' => 'movie.release_year',
            'vote_count' => 'movie.vote_count',
            'levels_count' => 'movie_projection.levels_count',
            'variants_total' => 'movie_projection.variants_total',
            default => 'movie.id',
        };

        $query->orderBy($column, $direction);

        if ($column !== 'movie.id') {
            $query->orderBy('movie.id', $direction);
        }

        return $query;
    }

    /**
     * Les quatre compteurs d'exception, en facette — une requête d'agrégats
     * purs, donc hors de portée d'`ONLY_FULL_GROUP_BY`.
     *
     * @return array{
     *     exception_total: int,
     *     exception_language: int,
     *     exception_vote_count: int,
     *     exception_release_year: int,
     * }
     */
    private function facets(CatalogIndexRequest $request): array
    {
        $row = $this->filtered($request, applyException: false)
            ->reorder()
            ->toBase()
            ->select([])
            ->selectRaw(DashboardController::EXCEPTION_SELECT)
            ->first();

        return [
            'exception_total' => (int) ($row->exception_total ?? 0),
            'exception_language' => (int) ($row->exception_language ?? 0),
            'exception_vote_count' => (int) ($row->exception_vote_count ?? 0),
            'exception_release_year' => (int) ($row->exception_release_year ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function titles(Movie $movie): array
    {
        $titles = MovieTitle::query()
            ->with('editedBy:id,name')
            ->where('movie_id', $movie->id)
            ->orderBy('locale')
            ->get();

        $rows = [];

        foreach ($titles as $title) {
            $rows[] = AdminCatalogPresenter::movieTitle($title);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aliases(Movie $movie): array
    {
        $aliases = Alias::query()
            ->where('movie_id', $movie->id)
            ->orderBy('locale')
            ->orderBy('id')
            ->get();

        $rows = [];

        foreach ($aliases as $alias) {
            $rows[] = AdminCatalogPresenter::movieAlias($alias);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function certifications(Movie $movie): array
    {
        $certifications = MovieCertification::query()
            ->where('movie_id', $movie->id)
            ->orderBy('country')
            ->get();

        $rows = [];

        foreach ($certifications as $certification) {
            $rows[] = AdminCatalogPresenter::movieCertification($certification);
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tags(Movie $movie): array
    {
        $tags = MovieTmdbTag::query()
            ->where('movie_id', $movie->id)
            ->orderBy('tag_kind')
            ->orderBy('tmdb_tag_id')
            ->get();

        $rows = [];

        foreach ($tags as $tag) {
            $rows[] = AdminCatalogPresenter::movieTag($tag);
        }

        return $rows;
    }

    /**
     * Les appartenances thématiques, thème et libellés chargés d'avance : une
     * fiche qui irait chercher le libellé d'un thème par ligne serait un N+1
     * sur un écran ouvert toute la journée.
     *
     * @return list<array<string, mixed>>
     */
    private function themes(Movie $movie): array
    {
        $memberships = MovieTheme::query()
            ->with(['theme:id,key', 'theme.labels'])
            ->where('movie_id', $movie->id)
            ->get();

        $rows = [];

        foreach ($memberships as $membership) {
            $rows[] = AdminCatalogPresenter::movieTheme($membership);
        }

        return $rows;
    }

    /**
     * La banque d'images. Servie par `frame_movie_level_idx (movie_id,
     * frame_level)`, et réduite aux **quatre colonnes que l'écran affiche** :
     * ni chemin, ni empreinte, ni rectangle de recadrage ne quittent le
     * serveur, même en administration (§ 10).
     *
     * @return list<array<string, mixed>>
     */
    private function frames(Movie $movie): array
    {
        $frames = Frame::query()
            ->select(['movie_id', 'frame_level', 'availability', 'processing_state', 'processing_error'])
            ->where('movie_id', $movie->id)
            ->orderBy('frame_level')
            ->get();

        $rows = [];

        foreach ($frames as $frame) {
            $rows[] = AdminCatalogPresenter::movieFrame($frame);
        }

        return $rows;
    }
}
