<?php

namespace App\Support\Curation;

use App\Enums\ContentAvailability;
use App\Enums\ContentFlag;
use App\Enums\ImportSource;
use App\Models\Movie;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

/**
 * La file des films non curés — spec 20 § 4.1, seul porteur de son périmètre,
 * de son ordre et de ses filtres. **Lecture seule.**
 *
 * **Périmètre** : films `draft` hors `import_source = demo`. Un film publié,
 * dépublié, écarté (§ 4.2), suspendu ou retiré n'y est jamais : écarter un
 * film incurable, c'est précisément l'en sortir.
 *
 * **Ordre** (question 13) :
 *
 * 1. d'abord les films **entamés** — au moins une frame non écartée (écartée =
 *    `unpublished` jamais publiée, EN20-1) —, du plus récemment touché au plus
 *    ancien, « touché » valant `MAX(frame.updated_at)` des frames du film, lu
 *    par le préfixe `movie_id` de `frame_movie_level_idx`. Ajouter une image,
 *    la recadrer, la revoir ou changer son niveau réécrit la ligne `frame`,
 *    donc remonte le film sans effet de bord : `Frame` ne déclare aucun
 *    `$touches`, et le battement de débit n'écrit que
 *    `movie.curation_active_seconds`. La reprise après interruption est
 *    naturelle : l'état est en base ;
 * 2. puis les films non entamés, par `vote_count` décroissant ;
 * 3. `movie.id` croissant départage, toujours — sans lui, deux films à égalité
 *    s'échangeraient de place d'une page à l'autre et « film suivant »
 *    deviendrait une loterie.
 *
 * Le tri sur `movie_difficulty` est écarté tant que la spec 30 n'a pas livré
 * sa dérivation, nulle pour tout film réel (n° 17).
 *
 * **Une seule expression** porte les deux premiers critères : l'instant de
 * dernière retouche, NUL pour un film non entamé. Trié en décroissant, un NUL
 * vient après toute date en SQLite comme en MySQL (le NUL y est la plus petite
 * valeur) : les films entamés passent devant, du plus récent au plus ancien,
 * et les autres suivent, départagés par les votes. Aucun `GROUP BY` — deux
 * sous-requêtes corrélées, hors de portée d'`ONLY_FULL_GROUP_BY` —, aucune
 * comparaison de dates écrite en SQL, aucune lecture dans un JSON.
 *
 * **Filtres** (§ 4.1, D11 du 23/09) : voie d'entrée (`discover` = entré par le
 * balayage, `exception` = entré par la voie manuelle, `is_import_exception`),
 * motif d'exception, drapeau de contenu. Ils servent à composer le lot pilote,
 * stratifié par voie (§ 10.3) ; ils ne changent jamais l'ordre.
 *
 * **Aucune réservation au J1** (un seul curateur, D4 du 23/09) : la
 * réservation souple du J2 viendra sauter ici les films réservés par un autre.
 */
final readonly class CurationQueue
{
    public const string ENTRY_DISCOVER = 'discover';

    public const string ENTRY_EXCEPTION = 'exception';

    /**
     * Les deux voies d'entrée du filtre — celles du lot pilote
     * (`catalog.curation.pilot.composition.{discover, exception}`).
     *
     * @var list<string>
     */
    public const array ENTRIES = [self::ENTRY_DISCOVER, self::ENTRY_EXCEPTION];

    /**
     * Les trois motifs d'exception, par colonne. Un motif se lit toujours AVEC
     * `is_import_exception` : seul, il n'est pas une entrée par exception.
     *
     * @var array<string, string>
     */
    public const array MOTIVE_COLUMNS = [
        'language' => 'movie.exception_for_language',
        'vote_count' => 'movie.exception_for_vote_count',
        'release_year' => 'movie.exception_for_release_year',
    ];

    /** Alias de l'instant de dernière retouche d'un film entamé, NUL sinon. */
    public const string TOUCHED_AT = 'curation_touched_at';

    /**
     * L'instant de dernière retouche, NUL pour un film non entamé. Texte
     * littéral ; sa seule liaison est la valeur `unpublished` de l'énumération.
     */
    private const string TOUCHED_AT_SELECT =
        'case when exists ('
        .'select 1 from frame as started_frame '
        .'where started_frame.movie_id = movie.id '
        .'and (started_frame.availability <> ? or started_frame.first_published_at is not null)'
        .') then ('
        .'select max(touched_frame.updated_at) from frame as touched_frame '
        .'where touched_frame.movie_id = movie.id'
        .') end as '.self::TOUCHED_AT;

    /**
     * @throws InvalidArgumentException une voie ou un motif hors liste blanche
     */
    public function __construct(
        public ?string $entry = null,
        public ?string $motive = null,
        public ?ContentFlag $contentFlag = null,
    ) {
        if ($entry !== null && ! in_array($entry, self::ENTRIES, true)) {
            throw new InvalidArgumentException("Voie d'entrée inconnue : {$entry}.");
        }

        if ($motive !== null && ! array_key_exists($motive, self::MOTIVE_COLUMNS)) {
            throw new InvalidArgumentException("Motif d'exception inconnu : {$motive}.");
        }
    }

    /**
     * La file, filtrée et ordonnée ; la projection de chaque film chargée
     * d'avance.
     *
     * @return Builder<Movie>
     */
    public function query(): Builder
    {
        $query = self::scope()
            ->select('movie.*')
            ->selectRaw(self::TOUCHED_AT_SELECT, [ContentAvailability::Unpublished->value])
            ->withCasts([self::TOUCHED_AT => 'immutable_datetime'])
            ->with('projection');

        if ($this->entry !== null) {
            $query->where('movie.is_import_exception', $this->entry === self::ENTRY_EXCEPTION);
        }

        if ($this->motive !== null) {
            $query->where('movie.is_import_exception', true)
                ->where(self::MOTIVE_COLUMNS[$this->motive], true);
        }

        if ($this->contentFlag !== null) {
            $query->where('movie.content_flag', $this->contentFlag->value);
        }

        return $query
            ->orderByDesc(self::TOUCHED_AT)
            ->orderByDesc('movie.vote_count')
            ->orderBy('movie.id');
    }

    /**
     * Une page de la file.
     *
     * @return LengthAwarePaginator<int, Movie>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    /**
     * La tête de la file.
     *
     * @return Collection<int, Movie>
     */
    public function head(int $limit): Collection
    {
        return $this->query()->limit($limit)->get();
    }

    /**
     * « Film suivant » (§ 4.1) : le premier film de la file autre que le film
     * courant — `null` quand la file n'en a pas d'autre.
     */
    public function next(?int $currentMovieId = null): ?Movie
    {
        $query = $this->query();

        if ($currentMovieId !== null) {
            $query->where('movie.id', '<>', $currentMovieId);
        }

        return $query->first();
    }

    /**
     * Le nombre de films de la file ENTIÈRE par voie d'entrée, filtres ignorés :
     * ce qui reste à curer dans chaque strate du lot pilote (§ 10.3). Une
     * requête d'agrégats purs, sans `GROUP BY`.
     *
     * @return array{discover: int, exception: int}
     */
    public static function totalsByEntry(): array
    {
        $row = self::scope()
            ->toBase()
            ->selectRaw(
                'coalesce(sum(case when movie.is_import_exception = ? then 1 else 0 end), 0) as discover, '
                .'coalesce(sum(case when movie.is_import_exception = ? then 1 else 0 end), 0) as exception',
                [false, true],
            )
            ->first();

        return [
            self::ENTRY_DISCOVER => (int) ($row->discover ?? 0),
            self::ENTRY_EXCEPTION => (int) ($row->exception ?? 0),
        ];
    }

    /**
     * L'instant de dernière retouche d'un film lu par {@see self::query()},
     * `null` pour un film non entamé.
     */
    public static function touchedAt(Movie $movie): ?CarbonImmutable
    {
        $touchedAt = $movie->getAttribute(self::TOUCHED_AT);

        return $touchedAt instanceof CarbonImmutable ? $touchedAt : null;
    }

    /**
     * Le périmètre, sans ordre ni filtre : brouillons hors démonstration.
     *
     * @return Builder<Movie>
     */
    private static function scope(): Builder
    {
        return Movie::query()
            ->where('movie.availability', ContentAvailability::Draft->value)
            ->where('movie.import_source', '<>', ImportSource::Demo->value);
    }
}
