<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\FrameLevel;
use App\Enums\FrameProcessingState;
use App\Http\Controllers\Controller;
use App\Models\Frame;
use App\Models\ImportRun;
use App\Models\Movie;
use App\Models\MovieProjection;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\CurationQueue;
use App\Support\Curation\CurationStatus;
use App\Support\Curation\ReviewList;
use App\Support\Curation\ReviewQueue;
use App\Support\Draw\PoolReporter;
use App\Support\Tmdb\TmdbClient;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La supervision du catalogue — **aucune écriture**.
 *
 * Les mesures de catalogue viennent des colonnes de `movie` et de
 * `movie_projection`, jamais d'un agrégat sur `frame` : la projection existe
 * précisément pour que le vivier ne coûte aucun `GROUP BY` sur la banque
 * d'images (§ 3.2, § 12). Les compteurs d'IMAGES à revoir, à re-revoir,
 * rejetées et en échec (spec 20 § 8.6) se lisent, eux, sur `frame` : ils
 * décrivent le travail de revue, que la projection ne porte pas.
 *
 * Quatre pièges tenus ici, et pas ailleurs :
 *
 * 1. `ONLY_FULL_GROUP_BY` est actif en développement et pas en test — une
 *    requête verte en SQLite peut échouer en MySQL. **Chaque `GROUP BY` nomme
 *    donc toutes ses colonnes non agrégées**, et les compteurs sont des
 *    agrégats purs, sans `GROUP BY` du tout.
 * 2. La couverture de niveaux se teste par `levels_mask & 21 = 21`, arithmétique
 *    entière portable, et jamais par `BIT_COUNT`, inexistant en SQLite. Le 21
 *    lui-même vient de {@see MovieProjection::publishableLevelsMask()}, pour
 *    qu'aucun littéral ne traîne.
 * 3. **Le vivier par `N` n'est pas compté ici** (C2, n° 26) : il vient du
 *    constructeur unique de la spec 30, {@see PoolReporter::catalogueWorksByFramesPerRound()},
 *    compté en œuvres, `N` de `RoomSettingsBounds::MIN_FRAMES_PER_ROUND` à
 *    `MAX_FRAMES_PER_ROUND`. Un second comptage — l'ancien `GROUP BY
 *    levels_count` sur des bornes écrites en dur — finirait par dire au
 *    curateur un autre nombre que le lobby.
 * 4. Aucun formatage de nombre côté serveur : `Number::format` lève une
 *    `RuntimeException` dans cet environnement (ni `intl`, ni `gd`, ni `exif`).
 *    Les compteurs partent en entiers, la mise en forme est un fait d'écran.
 */
class DashboardController extends Controller
{
    /**
     * Les quatre compteurs d'exception, en SQL portable et en **chaîne
     * littérale** : aucune portion de cette expression ne se compose à
     * l'exécution, et aucune valeur d'origine utilisateur n'en approche.
     *
     * `sum(case when …)` plutôt que quatre `count(*) … where` : un seul
     * aller-retour, et les quatre nombres sont mesurés sur exactement le même
     * jeu de lignes. Les trois motifs sont toujours lus AVEC
     * `is_import_exception` — un motif seul n'est pas une entrée par exception.
     *
     * Partagé avec la facette de la liste du catalogue, qui la mesure sur son
     * jeu filtré courant privé de la seule facette `exception`.
     */
    public const string EXCEPTION_SELECT =
        'coalesce(sum(case when movie.is_import_exception = 1 then 1 else 0 end), 0) as exception_total, '
        .'coalesce(sum(case when movie.is_import_exception = 1 and movie.exception_for_language = 1 then 1 else 0 end), 0) as exception_language, '
        .'coalesce(sum(case when movie.is_import_exception = 1 and movie.exception_for_vote_count = 1 then 1 else 0 end), 0) as exception_vote_count, '
        .'coalesce(sum(case when movie.is_import_exception = 1 and movie.exception_for_release_year = 1 then 1 else 0 end), 0) as exception_release_year';

    /**
     * Le nombre de films de la tête de la file et de la liste des écartés :
     * une taille d'écran, jamais une valeur de jeu. La liste entière vit
     * derrière le lien de chaque bloc.
     */
    public const int LIST_SIZE = 10;

    /**
     * La couverture d'images, en agrégats purs — donc sans `GROUP BY`, donc
     * hors de portée d'`ONLY_FULL_GROUP_BY`. Littérale pour la même raison que
     * ci-dessus ; les colonnes sont QUALIFIÉES parce que la mesure part de
     * `movie` et joint `movie_projection` à gauche, et `variants_total` est
     * coalescé : un film sans ligne de projection tombe dans « sans aucune
     * image », là où une comparaison à `null` l'aurait fait disparaître. La
     * moitié bit à bit, elle, est paramétrée depuis
     * {@see MovieProjection::publishableLevelsMask()} pour qu'aucun 21 ne
     * traîne en dur.
     */
    private const string COVERAGE_SELECT =
        'coalesce(sum(movie_projection.level_1_variants), 0) as level_1_sum, '
        .'coalesce(sum(case when movie_projection.level_1_variants > 0 then 1 else 0 end), 0) as level_1_movies, '
        .'coalesce(sum(case when movie_projection.level_1_variants = 1 then 1 else 0 end), 0) as level_1_single, '
        .'coalesce(sum(movie_projection.level_2_variants), 0) as level_2_sum, '
        .'coalesce(sum(case when movie_projection.level_2_variants > 0 then 1 else 0 end), 0) as level_2_movies, '
        .'coalesce(sum(case when movie_projection.level_2_variants = 1 then 1 else 0 end), 0) as level_2_single, '
        .'coalesce(sum(movie_projection.level_3_variants), 0) as level_3_sum, '
        .'coalesce(sum(case when movie_projection.level_3_variants > 0 then 1 else 0 end), 0) as level_3_movies, '
        .'coalesce(sum(case when movie_projection.level_3_variants = 1 then 1 else 0 end), 0) as level_3_single, '
        .'coalesce(sum(movie_projection.level_4_variants), 0) as level_4_sum, '
        .'coalesce(sum(case when movie_projection.level_4_variants > 0 then 1 else 0 end), 0) as level_4_movies, '
        .'coalesce(sum(case when movie_projection.level_4_variants = 1 then 1 else 0 end), 0) as level_4_single, '
        .'coalesce(sum(movie_projection.level_5_variants), 0) as level_5_sum, '
        .'coalesce(sum(case when movie_projection.level_5_variants > 0 then 1 else 0 end), 0) as level_5_movies, '
        .'coalesce(sum(case when movie_projection.level_5_variants = 1 then 1 else 0 end), 0) as level_5_single, '
        .'coalesce(sum(movie_projection.variants_total), 0) as variants_total, '
        .'coalesce(sum(case when coalesce(movie_projection.variants_total, 0) = 0 then 1 else 0 end), 0) as without_frames';

    /**
     * Supervision du catalogue, de la file de curation et des derniers
     * balayages.
     */
    public function index(TmdbClient $tmdb, PoolReporter $pool): Response
    {
        $availability = $this->countsBy('availability', array_column(ContentAvailability::cases(), 'value'));
        $contentFlag = $this->countsBy('content_flag', array_column(ContentFlag::cases(), 'value'));

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'movies_total' => array_sum($availability),
                'availability' => $availability,
                'content_flag' => $contentFlag,
                'exceptions' => $this->exceptionCounts(),
                'pool' => $this->poolWorks($pool),
                'coverage' => $this->coverage(),
                'curation' => $this->curationCounts(),
                'frames' => $this->frameCounts(),
            ],
            'queue' => $this->queueHead(),
            'set_aside' => $this->setAside(),
            'runs' => $this->latestRuns(),
            'tmdb_configured' => $tmdb->isConfigured(),
        ]);
    }

    /**
     * `select <colonne>, count(*) … group by <colonne>` — la colonne non
     * agrégée est nommée des deux côtés, sans quoi la requête passerait en
     * SQLite et tomberait sous `ONLY_FULL_GROUP_BY`.
     *
     * Les cas absents de la table valent zéro : une tuile vide est une
     * information, une tuile manquante est un trou.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    private function countsBy(string $column, array $keys): array
    {
        $counts = array_fill_keys($keys, 0);

        $rows = Movie::query()->toBase()
            ->select($column)
            ->selectRaw('count(*) as movies')
            ->groupBy($column)
            ->get();

        foreach ($rows as $row) {
            $key = $row->{$column};

            if (is_string($key) && array_key_exists($key, $counts)) {
                $counts[$key] = (int) $row->movies;
            }
        }

        return $counts;
    }

    /**
     * Le comptage PAR MOTIF exigé par la décision 11, sur le catalogue entier.
     *
     * Les trois motifs sont toujours lus **avec** `is_import_exception` : un
     * motif seul n'est pas une entrée par exception, et afficher deux nombres
     * dont l'un compte autre chose que l'autre est le plus sûr moyen de rendre
     * la colonne inutile.
     *
     * @return array{total: int, language: int, vote_count: int, release_year: int}
     */
    private function exceptionCounts(): array
    {
        $row = Movie::query()->toBase()
            ->selectRaw(self::EXCEPTION_SELECT)
            ->first();

        return [
            'total' => (int) ($row->exception_total ?? 0),
            'language' => (int) ($row->exception_language ?? 0),
            'vote_count' => (int) ($row->exception_vote_count ?? 0),
            'release_year' => (int) ($row->exception_release_year ?? 0),
        ];
    }

    /**
     * Supervision « œuvres jouables à `N` » (spec 20 § 8.6, contrat C2) : le
     * vivier CATALOGUE — ni thème, ni clause de salon —, compté en œuvres, un
     * `N` par entrée, dans l'ordre croissant, bornes de `RoomSettingsBounds`.
     *
     * C'est un **plafond**, jamais le vivier d'un salon : l'écran le dit en
     * toutes lettres — sinon un curateur lirait 47 sur ce tableau et 7 dans un
     * lobby, sans cause visible entre deux écrans.
     *
     * @return list<array{frames_per_round: int, works: int}>
     */
    private function poolWorks(PoolReporter $pool): array
    {
        $entries = [];

        foreach ($pool->catalogueWorksByFramesPerRound() as $framesPerRound => $works) {
            $entries[] = ['frames_per_round' => $framesPerRound, 'works' => $works];
        }

        return $entries;
    }

    /**
     * La couverture d'images, en une seule passe d'agrégats purs — donc sans
     * `GROUP BY`, donc hors de portée d'`ONLY_FULL_GROUP_BY`.
     *
     * Elle se mesure depuis `movie` par une jointure GAUCHE, et non depuis
     * `movie_projection` : la spec 10 § 3.2 prévoit nommément la ligne de
     * projection manquante — « un film restauré avant `catalog:reproject` n'en
     * a pas ». Compter le bloc 1 sur `movie` et le bloc 3 sur
     * `movie_projection` donnerait deux dénominateurs sur le même écran, et
     * les films à faire reprojeter — exactement ceux dont il faut s'alarmer —
     * disparaîtraient du compteur « sans aucune image » au lieu d'y tomber.
     *
     * `single_variant_levels` compte des COUPLES (film, niveau) à variante
     * unique, et non des films : c'est le signal de back-office « ce niveau
     * n'a qu'une image, tout le salon la verra à chaque tirage ».
     *
     * @return array{
     *     levels: list<array{level: int, variants: int, movies: int}>,
     *     variants_total: int,
     *     covers_publishable: int,
     *     without_frames: int,
     *     single_variant_levels: int,
     * }
     */
    private function coverage(): array
    {
        $mask = MovieProjection::publishableLevelsMask();

        $row = Movie::query()->toBase()
            ->leftJoin('movie_projection', 'movie_projection.movie_id', '=', 'movie.id')
            ->selectRaw(self::COVERAGE_SELECT)
            ->selectRaw(
                'coalesce(sum(case when (coalesce(movie_projection.levels_mask, 0) & ?) = ? then 1 else 0 end), 0) as covers_publishable',
                [$mask, $mask],
            )
            ->first();

        $levels = [];
        $singleVariantLevels = 0;

        foreach (FrameLevel::cases() as $level) {
            $alias = 'level_'.$level->value;

            $levels[] = [
                'level' => $level->value,
                'variants' => (int) ($row->{$alias.'_sum'} ?? 0),
                'movies' => (int) ($row->{$alias.'_movies'} ?? 0),
            ];

            $singleVariantLevels += (int) ($row->{$alias.'_single'} ?? 0);
        }

        return [
            'levels' => $levels,
            'variants_total' => (int) ($row->variants_total ?? 0),
            'covers_publishable' => (int) ($row->covers_publishable ?? 0),
            'without_frames' => (int) ($row->without_frames ?? 0),
            'single_variant_levels' => $singleVariantLevels,
        ];
    }

    /**
     * Films prêts à publier, publiés incomplets et écartés (spec 20 § 8.6) —
     * un agrégat pur, les trois prédicats lus dans {@see CurationStatus}, seul
     * porteur de leur texte, que le filtre du catalogue emploie aussi : chaque
     * compteur mène à la liste qu'il annonce.
     *
     * @return array<string, int>
     */
    private function curationCounts(): array
    {
        $query = Movie::query()->toBase()
            ->leftJoin('movie_projection', 'movie_projection.movie_id', '=', 'movie.id');

        foreach (CurationStatus::cases() as $status) {
            [$condition, $bindings] = $status->condition();

            $query->selectRaw("coalesce(sum(case when {$condition} then 1 else 0 end), 0) as {$status->value}", $bindings);
        }

        $row = $query->first();

        $counts = [];

        foreach (CurationStatus::cases() as $status) {
            $counts[$status->value] = (int) ($row->{$status->value} ?? 0);
        }

        return $counts;
    }

    /**
     * Les images à revoir, à re-revoir et rejetées — les trois listes de la
     * file de revue, prédicats de {@see ReviewQueue}, seul porteur (§ 7.3) —,
     * puis les images en échec de traitement : ni écartées, ni verrouillées
     * par un administrateur, dans l'ordre de lecture de `FrameCurationState`,
     * où l'écartée et la verrouillée l'emportent sur l'échec.
     *
     * @return array<string, int>
     */
    private function frameCounts(): array
    {
        $counts = [];
        $lists = (new ReviewQueue)->counts();

        foreach (ReviewList::cases() as $list) {
            $counts[$list->value] = $lists[$list->value] ?? 0;
        }

        $counts['failed'] = Frame::query()
            ->where('processing_state', FrameProcessingState::Failed->value)
            ->whereNotIn('availability', [ContentAvailability::Suspended->value, ContentAvailability::Withdrawn->value])
            ->where(function (Builder $query): void {
                $query->where('availability', '<>', ContentAvailability::Unpublished->value)
                    ->orWhereNotNull('first_published_at');
            })
            ->count();

        return $counts;
    }

    /**
     * La tête de la file de curation (§ 4.1), dans l'ordre de la file : les
     * films entamés d'abord, puis les autres par votes décroissants. La file
     * entière vit sur son propre écran.
     *
     * @return list<array<string, mixed>>
     */
    private function queueHead(): array
    {
        $rows = [];
        $rank = 1;

        foreach ((new CurationQueue)->head(self::LIST_SIZE) as $movie) {
            $rows[] = AdminCatalogPresenter::curationQueueRow($movie, CurationQueue::touchedAt($movie), $rank++);
        }

        return $rows;
    }

    /**
     * Les films écartés (§ 4.2) les plus récents, motif compris ; la liste
     * entière est le filtre « écartés » du catalogue.
     *
     * @return list<array<string, mixed>>
     */
    private function setAside(): array
    {
        [$condition, $bindings] = CurationStatus::SetAside->condition();

        $movies = Movie::query()
            ->select('movie.*')
            ->leftJoin('movie_projection', 'movie_projection.movie_id', '=', 'movie.id')
            ->whereRaw($condition, $bindings)
            ->with('projection')
            ->orderByDesc('movie.availability_changed_at')
            ->orderByDesc('movie.id')
            ->limit(self::LIST_SIZE)
            ->get();

        $rows = [];

        foreach ($movies as $movie) {
            $rows[] = AdminCatalogPresenter::setAsideRow($movie);
        }

        return $rows;
    }

    /**
     * Les cinq derniers balayages. `actor` est chargé d'avance : cinq lignes
     * qui iraient chercher leur auteur une par une seraient déjà un N+1, et la
     * discipline vaut d'être tenue là où elle ne coûte rien.
     *
     * @return list<array<string, mixed>>
     */
    private function latestRuns(): array
    {
        $runs = ImportRun::query()
            ->with('actor:id,name')
            ->latest('id')
            ->limit(5)
            ->get();

        $rows = [];

        foreach ($runs as $run) {
            $rows[] = AdminCatalogPresenter::importRunRow($run);
        }

        return $rows;
    }
}
