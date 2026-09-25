<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\PublishMovie;
use App\Actions\Curation\SetMovieGroup;
use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CatalogIndexRequest;
use App\Http\Requests\Admin\MovieTitleUpdateRequest;
use App\Models\Alias;
use App\Models\AnswerKey;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\MovieCertification;
use App\Models\MovieGroup;
use App\Models\MovieProjection;
use App\Models\MovieTheme;
use App\Models\MovieTitle;
use App\Models\MovieTmdbTag;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Catalog\AmbiguityPreview;
use App\Support\Catalog\AnswerKeyNormalizer;
use App\Support\Catalog\TextTarget;
use App\Support\Curation\CurationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le catalogue et la fiche d'un film, **en lecture** : aucune méthode
 * d'écriture ici.
 *
 * Publier, dépublier, écarter, cocher « contenu vérifié » (spec 20 § 4.3,
 * § 4.4, § 8), puis corriger un titre, suspendre ou retirer : chacun de ces
 * gestes a sa propre route, son contrôleur et son seuil — curateur pour la
 * publication, administrateur seul pour la suspension et le retrait
 * juridique. La fiche n'en envoie que les booléens `abilities`, qui masquent
 * un bouton et n'autorisent rien.
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
    /** Paramètre du rechargement partiel qui demande l'aperçu d'un texte : le texte saisi. */
    public const string PREVIEW_TEXT_PARAMETER = 'preview_text';

    /** Paramètre du même rechargement : `title` ou `alias` ({@see TextTarget}). */
    public const string PREVIEW_TARGET_PARAMETER = 'preview_target';

    /** La largeur d'un titre comme d'un alias : un texte plus long serait refusé à l'envoi. */
    public const int PREVIEW_TEXT_MAX_LENGTH = MovieTitleUpdateRequest::TITLE_MAX_LENGTH;

    /** Paramètre du rechargement partiel de la voie manuelle du regroupement : l'identifiant saisi. */
    public const string GROUP_WITH_PARAMETER = 'group_with';

    /**
     * Refus propre à la recherche de la voie manuelle : les deux films sont
     * déjà ensemble — le geste ne changerait rien, la fiche le dit.
     */
    public const string GROUP_REFUSAL_SAME_GROUP = 'same_group';

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
    public function show(Request $request, Movie $movie, AmbiguityPreview $ambiguity): Response
    {
        $movie->load([
            'projection',
            'collection:id,name',
            'group',
            'group.createdBy:id,name',
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
            // Les locales ACTIVÉES : un titre éditable chacune (§ 9.1) — les
            // lignes d'autres locales de catalogue restent en lecture seule —,
            // et leur couverture lue dans `title_locale_mask` (§ 9.3).
            'title_locales' => $this->titleLocales($projection),
            'titles' => $this->titles($movie),
            'aliases' => $this->aliases($movie),
            // Les formes acceptées, en lecture seule (§ 9.2) : ce qui rend un
            // alias vérifiable par un non-technicien.
            'answer_keys' => $this->answerKeys($movie),
            // Le regroupement « même œuvre » et ses candidats exacts (§ 9.4).
            'group' => $this->group($movie),
            'group_exact_candidates' => $this->groupCandidates($movie),
            // La voie manuelle du regroupement : le film désigné par son
            // identifiant, servi au seul rechargement partiel qui ouvre la
            // même confirmation que celle d'un candidat — libellé pré-rempli,
            // modifiable (§ 9.4). En lecture seule.
            'group_manual_candidate' => Inertia::optional(
                fn (): ?array => $this->manualGroupCandidate($request, $movie),
            ),
            // L'aperçu d'un titre ou d'un alias saisi, servi au seul
            // rechargement partiel qui ouvre sa confirmation (§ 9.1, § 9.2) :
            // en lecture seule, comme l'aperçu de publication.
            'text_preview' => Inertia::optional(
                fn (): ?array => $this->textPreview($request, $movie, $ambiguity),
            ),
            'certifications' => $this->certifications($movie),
            'tags' => $this->tags($movie),
            'themes' => $this->themes($movie),
            'frames' => $this->frames($movie),
            'import_run' => $movie->importRun === null
                ? null
                : AdminCatalogPresenter::importRunRow($movie->importRun),
            // Les conditions de publication, pour le bouton « Publier le film »,
            // inactif avec la condition manquante nommée (§ 8.1) : la même
            // lecture que la garde de `PublishMovie`, qui la rejoue sous verrou.
            'publication' => PublishMovie::conditions($movie, $projection),
            // L'aperçu d'ambiguïté, servi au seul rechargement partiel qui
            // ouvre la confirmation de publication (§ 8.2) : en lecture seule,
            // et son empreinte repart avec la publication.
            'publication_preview' => Inertia::optional(
                fn (): array => $ambiguity->forPublication($movie)->toArray(),
            ),
            // Ne sert qu'à afficher un bouton (spec 20 § 4.3) : chaque geste
            // garde sa policy à l'écriture. `curate` ouvre l'éditeur de la
            // banque, les titres, les alias et le regroupement ; les gestes
            // administrateur arrivent au jalon 2.
            'abilities' => [
                'curate' => Gate::allows('curate', $movie),
                'publish' => Gate::allows('publish', $movie),
                'unpublish' => Gate::allows('unpublish', $movie),
                'verifyContent' => Gate::allows('verifyContent', $movie),
            ],
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
     *     missing_title: list<string>,
     *     curation_status: list<string>,
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
            // Les bornes de `N` des réglages de salon, jamais un littéral
            // (règle 2, n° 26).
            'playable_at' => range(
                RoomSettingsBounds::MIN_FRAMES_PER_ROUND,
                RoomSettingsBounds::MAX_FRAMES_PER_ROUND,
            ),
            'missing_title' => array_column(Locale::cases(), 'value'),
            'curation_status' => array_column(CurationStatus::cases(), 'value'),
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

        $missingTitle = $request->missingTitle();

        if ($missingTitle !== null) {
            // La file « titres manquants » (§ 9.3) : le bit de la locale est nul
            // dans `title_locale_mask` — arithmétique entière portable — et le
            // masque est à la version COURANTE. Un masque périmé, ou une ligne
            // de projection absente, ne se lit jamais comme valide : le film
            // n'y entre pas, `catalog:reproject` l'y fera entrer.
            $query->where('movie_projection.title_mask_version', Locale::MASK_VERSION)
                ->whereRaw('(movie_projection.title_locale_mask & ?) = 0', [$missingTitle->maskBit()]);
        }

        $curationStatus = $request->curationStatus();

        if ($curationStatus !== null) {
            [$condition, $bindings] = $curationStatus->condition();

            $query->whereRaw($condition, $bindings);
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
     * colonne annonce — et non sur l'auto-incrément, dont l'invariant
     * « `movie.id` croît avec la date d'entrée » est faux dès qu'un
     * `created_at` est posé à la main.
     *
     * @param  Builder<Movie>  $query
     * @return Builder<Movie>
     */
    private function sorted(Builder $query, CatalogIndexRequest $request): Builder
    {
        $direction = $request->direction();

        // Titres manquants : les films publiés d'abord (§ 9.3) — ce sont eux
        // qu'un joueur voit sans titre dans sa langue —, puis le tri choisi.
        if ($request->missingTitle() !== null) {
            $query->orderByRaw(
                'case when movie.availability = ? then 0 else 1 end',
                [ContentAvailability::Published->value],
            );
        }

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
            ->with('createdBy:id,name')
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
     * Les locales activées et leur couverture (§ 9.3) : présente ou absente,
     * **lue dans `movie_projection.title_locale_mask`** — le masque que le
     * tirage des leurres interroge —, et `null` quand le masque n'est pas à
     * la version courante (`Locale::MASK_VERSION`) ou que la projection
     * manque : un masque périmé ne se lit jamais comme valide.
     *
     * @return list<array{locale: string, covered: bool|null}>
     */
    private function titleLocales(?MovieProjection $projection): array
    {
        $mask = $projection !== null && $projection->hasCurrentTitleMask()
            ? $projection->title_locale_mask
            : null;

        $rows = [];

        foreach (Locale::cases() as $locale) {
            $rows[] = [
                'locale' => $locale->value,
                'covered' => $mask === null ? null : ($mask & $locale->maskBit()) !== 0,
            ];
        }

        return $rows;
    }

    /**
     * Les formes acceptées du film (§ 9.2), dans l'ordre des natures de
     * `AnswerKeyKind` — titres, alias, puis formes dérivées —, puis par forme.
     *
     * @return list<array{form: string, kind: string, is_ambiguous: bool}>
     */
    private function answerKeys(Movie $movie): array
    {
        $order = array_flip(array_column(AnswerKeyKind::cases(), 'value'));

        $keys = AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->get(['normalized', 'key_kind', 'is_ambiguous'])
            ->sort(static fn (AnswerKey $a, AnswerKey $b): int => [$order[$a->key_kind->value], (string) $a->normalized]
                <=> [$order[$b->key_kind->value], (string) $b->normalized]);

        $rows = [];

        foreach ($keys as $key) {
            $rows[] = AdminCatalogPresenter::answerKey($key);
        }

        return $rows;
    }

    /**
     * Le groupe du film et ses films, du plus ancien au plus récent ; `null`
     * pour un film sans groupe.
     *
     * @return array<string, mixed>|null
     */
    private function group(Movie $movie): ?array
    {
        $group = $movie->group;

        if (! $group instanceof MovieGroup) {
            return null;
        }

        $members = Movie::query()
            ->where('group_id', $group->id)
            ->orderBy('release_year')
            ->orderBy('id')
            ->get(['id', 'title_original', 'release_year', 'availability']);

        return AdminCatalogPresenter::movieGroup($group, $members);
    }

    /**
     * Les candidats exacts au regroupement (§ 9.4) : les films au titre
     * normalisé identique, lus par `answer_key_norm_movie_uq`.
     *
     * @return list<array<string, mixed>>
     */
    private function groupCandidates(Movie $movie): array
    {
        $rows = [];

        foreach (SetMovieGroup::exactCandidates($movie) as $candidate) {
            $rows[] = AdminCatalogPresenter::groupCandidate($movie, $candidate);
        }

        return $rows;
    }

    /**
     * Le film désigné par `group_with` pour la voie manuelle du regroupement
     * (§ 9.4) — un remake au titre différent, qu'aucun candidat exact ne
     * propose —, présenté comme un candidat : identité, groupe éventuel,
     * libellé pré-rempli. Ou le refus que le geste opposerait
     * ({@see SetMovieGroup::pairRefusal()}), plus `same_group` quand les deux
     * films sont déjà ensemble : la fiche ne confirme jamais un geste vain.
     *
     * `requested` rend le texte cherché : seule la réponse à la DERNIÈRE
     * demande fait foi. `null` quand la demande est vide.
     *
     * @return array{requested: string, candidate: array<string, mixed>|null, refusal: string|null}|null
     */
    private function manualGroupCandidate(Request $request, Movie $movie): ?array
    {
        $requested = trim((string) $request->string(self::GROUP_WITH_PARAMETER));

        if ($requested === '') {
            return null;
        }

        /** @var User $curator */
        $curator = $request->user();

        $id = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $other = $id === false ? null : Movie::query()->with('group:id,label')->find($id);

        $refusal = $id === false
            ? SetMovieGroup::REFUSAL_MISSING
            : SetMovieGroup::pairRefusal($movie, $id, $other, $curator);

        if ($refusal === null && $other instanceof Movie && $movie->group_id !== null && $other->group_id === $movie->group_id) {
            $refusal = self::GROUP_REFUSAL_SAME_GROUP;
        }

        return [
            'requested' => $requested,
            'candidate' => $refusal === null && $other instanceof Movie
                ? AdminCatalogPresenter::groupCandidate($movie, $other)
                : null,
            'refusal' => $refusal,
        ];
    }

    /**
     * L'aperçu d'un texte saisi — titre ou alias —, désigné par
     * `preview_text` et `preview_target` (§ 9.1, § 9.2), en lecture seule :
     *
     * - `form` : sa forme normalisée, celle qu'`answer_key` porterait — vide
     *   quand le texte ne contient ni lettre ni chiffre ;
     * - `accepted_as` : pour un ALIAS, la nature EXACTE sous laquelle ce film
     *   accepte déjà cette forme, ou `null` — l'écran avertit d'un alias
     *   redondant (§ 9.2) ;
     * - `promoted_from` : pour un ALIAS, la forme dérivée — préfixe ou
     *   sous-titre d'un titre du film, et son ambiguïté — qu'il rendrait
     *   exacte, donc toujours acceptée (spec 10 § 3.5 : toute nature exacte
     *   l'emporte) ; `null` sinon. Un tel alias n'est jamais redondant ;
     * - `ambiguity` : sur un film publié, ce que le texte rendrait ambigu
     *   ({@see AmbiguityPreview::forText()}) ; `null` sur un film non publié,
     *   dont aucune forme ne pèse dans le recompte.
     *
     * Un titre n'a ni `accepted_as` ni `promoted_from` : le corriger n'ajoute
     * rien, il remplace — seule son ambiguïté se prévisualise (§ 9.1).
     *
     * `null` quand la demande est incomplète.
     *
     * @return array{target: string, text: string, form: string, accepted_as: string|null, promoted_from: array{kind: string, is_ambiguous: bool}|null, ambiguity: list<array<string, mixed>>|null}|null
     */
    private function textPreview(Request $request, Movie $movie, AmbiguityPreview $ambiguity): ?array
    {
        $target = TextTarget::tryFrom((string) $request->string(self::PREVIEW_TARGET_PARAMETER));
        $text = mb_substr(trim((string) $request->string(self::PREVIEW_TEXT_PARAMETER)), 0, self::PREVIEW_TEXT_MAX_LENGTH);

        if ($target === null || $text === '') {
            return null;
        }

        $form = AnswerKeyNormalizer::normalize($text);

        // Au plus une clé par forme et par film (`answer_key_norm_movie_uq`),
        // de la nature la plus forte.
        $accepted = $target !== TextTarget::Alias || $form === ''
            ? null
            : AnswerKey::query()
                ->where('movie_id', $movie->id)
                ->where('normalized', $form)
                ->first(['key_kind', 'is_ambiguous']);

        return [
            'target' => $target->value,
            'text' => $text,
            'form' => $form,
            'accepted_as' => $accepted !== null && $accepted->key_kind->isExact()
                ? $accepted->key_kind->value
                : null,
            'promoted_from' => $accepted !== null && ! $accepted->key_kind->isExact()
                ? ['kind' => $accepted->key_kind->value, 'is_ambiguous' => $accepted->is_ambiguous]
                : null,
            'ambiguity' => $movie->availability === ContentAvailability::Published
                ? $ambiguity->forText($movie, $text, $target)->lines
                : null,
        ];
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
