<?php

namespace App\Actions\Curation;

use App\Enums\AnswerKeyKind;
use App\Enums\ContentAvailability;
use App\Models\AnswerKey;
use App\Models\Movie;
use App\Models\MovieGroup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Regrouper deux films en une même œuvre, ou retirer un film de son groupe —
 * spec 20 § 9.4, ligne 23 de la matrice des capacités (`can:curate,movie`).
 *
 * `movie_group` est **manuel** : jamais alimenté par TMDB, jamais montré à un
 * joueur ; sa seule conséquence est l'exclusion mutuelle dans un tirage
 * (spec 30). À ne jamais confondre avec `collection`, la saga, dont les films
 * doivent au contraire pouvoir tomber ensemble.
 *
 * Trois gestes, une transaction chacun, les films touchés verrouillés :
 *
 * - **avec un film** (`with_movie_id`) : si aucun des deux n'est groupé, un
 *   groupe naît — libellé saisi, ou à défaut « Titre A (année) / Titre B
 *   (année) » tronqué à 120 caractères ({@see self::defaultLabel()}), note
 *   facultative, `created_by_id` — et les deux y sont rattachés ; si l'un
 *   des deux l'est, l'autre rejoint son groupe ; s'ils le sont déjà ensemble,
 *   rien ne change ;
 * - **dans un groupe** (`group_id`) : le film le rejoint ;
 * - **retirer** (`$leave`, demandé en toutes lettres) : « Retirer du
 *   groupe », `group_id = null`, et un groupe réduit à moins de deux films
 *   est supprimé dans la même transaction — son dernier film en sort avec
 *   lui. Ni film, ni groupe, ni retrait demandé : refus traduit, jamais un
 *   retrait par défaut — un identifiant laissé vide ne supprime pas un
 *   groupe et sa note.
 *
 * **Ordre des verrous**, le même pour tous les gestes : les films d'abord,
 * dans l'ordre de leur clé, puis le groupe. Retirer un film verrouille donc
 * AUSSI les autres films de son groupe avant le groupe
 * ({@see self::lockWithMembers()}) : sinon un retrait et un regroupement
 * croisés sur le même groupe s'attendraient l'un l'autre.
 *
 * **Jamais de fusion implicite** : un film déjà groupé ailleurs ne change pas
 * de groupe en silence — il faut d'abord l'en retirer (refus traduit
 * `admin.movie.group.already_grouped` ou `both_grouped`). Déplacer un film
 * défait une décision « même œuvre » qu'un curateur a prise, et ce geste doit
 * être vu.
 *
 * Aucune ligne `admin_action` : ce n'est pas un geste engageant,
 * `movie_group.created_by_id` le trace. Aucune reprojection non plus : le
 * groupe n'entre ni dans `answer_key`, ni dans `movie_projection`.
 */
final class SetMovieGroup
{
    /** Un groupe est né des deux films. */
    public const string CREATED = 'created';

    /** Un film a rejoint un groupe existant. */
    public const string JOINED = 'joined';

    /** Le film est sorti de son groupe. */
    public const string LEFT = 'left';

    /** Rien à faire : l'état demandé est déjà l'état en base. */
    public const string UNCHANGED = 'unchanged';

    /**
     * Les refus d'un regroupement avec un film ({@see self::pairRefusal()}),
     * partagés par le geste et par la recherche de la voie manuelle — la
     * fiche les traduit avant d'ouvrir la confirmation.
     */
    public const string REFUSAL_SELF = 'self';

    public const string REFUSAL_MISSING = 'missing';

    public const string REFUSAL_WITHDRAWN = 'withdrawn';

    public const string REFUSAL_BOTH_GROUPED = 'both_grouped';

    /**
     * La traduction de chaque refus, sous le champ `with_movie_id`.
     *
     * @var array<string, string>
     */
    private const array MESSAGE_OF_REFUSAL = [
        self::REFUSAL_SELF => 'admin.movie.group.self',
        self::REFUSAL_MISSING => 'admin.movie.group.other_missing',
        self::REFUSAL_WITHDRAWN => 'admin.movie.group.other_withdrawn',
        self::REFUSAL_BOTH_GROUPED => 'admin.movie.group.both_grouped',
    ];

    /** `movie_group.label` est un `string(120)` (spec 10 § 3.3). */
    public const int LABEL_MAX_LENGTH = 120;

    /** `movie_group.note` est un `string(500)` (spec 10 § 3.3). */
    public const int NOTE_MAX_LENGTH = 500;

    /** En deçà, un groupe n'a plus rien à exclure : il disparaît. */
    public const int MIN_MOVIES = 2;

    /**
     * Le libellé pré-rempli : deux films, du plus ancien au plus récent.
     * Données de back-office, **jamais localisées** (spec 10 § 3.3) : un
     * format, pas une phrase.
     */
    private const string LABEL_FORMAT = '%s / %s';

    private const string LABEL_MOVIE_FORMAT = '%s (%d)';

    /** La marque de troncature du libellé (§ 9.4). */
    private const string LABEL_ELLIPSIS = '…';

    /**
     * Regroupe, rejoint ou retire ; rend l'issue (`CREATED`, `JOINED`,
     * `LEFT`, `UNCHANGED`). Un seul des trois gestes : `$withMovieId`,
     * `$groupId`, ou `$leave`.
     *
     * @param  bool  $leave  retirer le film de son groupe — jamais déduit d'un envoi vide
     * @param  string|null  $label  libellé d'un groupe NOUVEAU ; ignoré sinon
     * @param  string|null  $note  justification d'un groupe NOUVEAU ; ignorée sinon
     *
     * @throws AuthorizationException le film a été retiré entre la garde et le verrou
     * @throws ValidationException aucun geste nommé ; film ou groupe introuvable, retiré, ou déjà groupé ailleurs — sans aucune écriture
     * @throws InvalidArgumentException plusieurs gestes à la fois — la requête l'interdit déjà
     * @throws Throwable
     */
    public function handle(
        Movie $movie,
        User $curator,
        ?int $withMovieId,
        ?int $groupId,
        bool $leave = false,
        ?string $label = null,
        ?string $note = null,
    ): string {
        $gestures = ($withMovieId !== null ? 1 : 0) + ($groupId !== null ? 1 : 0) + ($leave ? 1 : 0);

        if ($gestures === 0) {
            throw ValidationException::withMessages([
                'with_movie_id' => __('admin.movie.group.movie_required'),
            ]);
        }

        if ($gestures > 1) {
            throw new InvalidArgumentException('Un seul geste de regroupement à la fois : un film, un groupe, ou le retrait.');
        }

        return DB::transaction(function () use ($movie, $curator, $withMovieId, $groupId, $label, $note): string {
            $locked = $withMovieId === null && $groupId === null
                ? $this->lockWithMembers($movie)
                : $this->lockMovies([$movie->id, $withMovieId ?? $movie->id]);

            $self = $locked->get($movie->id);

            if (! $self instanceof Movie) {
                $self = Movie::query()->whereKey($movie->id)->firstOrFail();
            }

            Gate::forUser($curator)->authorize('curate', $self);

            if ($withMovieId !== null) {
                return $this->pair($self, $withMovieId, $locked->get($withMovieId), $curator, $label, $note);
            }

            if ($groupId !== null) {
                return $this->join($self, $groupId);
            }

            return $this->leave($self);
        });
    }

    /**
     * Pourquoi `$self` ne se regroupe pas avec le film `$withMovieId`
     * (`$other`, `null` s'il n'existe pas), ou `null` s'il le peut — deux
     * films déjà ensemble compris : le geste ne change alors rien.
     *
     * Le geste la relit sous verrou ; la fiche la lit à la recherche de la
     * voie manuelle, pour ne jamais ouvrir une confirmation vouée au refus.
     *
     * @return self::REFUSAL_*|null
     */
    public static function pairRefusal(Movie $self, int $withMovieId, ?Movie $other, User $curator): ?string
    {
        if ($withMovieId === $self->id) {
            return self::REFUSAL_SELF;
        }

        if (! $other instanceof Movie) {
            return self::REFUSAL_MISSING;
        }

        // Le second film change lui aussi : il doit se travailler encore.
        if (! Gate::forUser($curator)->allows('curate', $other)) {
            return self::REFUSAL_WITHDRAWN;
        }

        // Jamais de fusion implicite : deux groupes ne se fondent pas en un.
        if ($self->group_id !== null && $other->group_id !== null && $self->group_id !== $other->group_id) {
            return self::REFUSAL_BOTH_GROUPED;
        }

        return null;
    }

    /**
     * Le libellé pré-rempli d'un groupe de deux films : « Titre A (année) /
     * Titre B (année) », du plus ancien au plus récent, puis tronqué par
     * `Str::limit($label, 119, '…')` — la colonne est un `string(120)` alors
     * qu'un `title_original` peut compter 255 caractères, et MySQL strict
     * refuserait l'insertion (§ 9.4). Éditable avant validation.
     */
    public static function defaultLabel(Movie $first, Movie $second): string
    {
        $movies = [$first, $second];

        usort($movies, static fn (Movie $a, Movie $b): int => [$a->release_year ?? PHP_INT_MAX, $a->id]
            <=> [$b->release_year ?? PHP_INT_MAX, $b->id]);

        $parts = array_map(
            static fn (Movie $movie): string => $movie->release_year === null
                ? $movie->title_original
                : sprintf(self::LABEL_MOVIE_FORMAT, $movie->title_original, $movie->release_year),
            $movies,
        );

        return Str::limit(
            sprintf(self::LABEL_FORMAT, $parts[0], $parts[1]),
            self::LABEL_MAX_LENGTH - 1,
            self::LABEL_ELLIPSIS,
        );
    }

    /**
     * Les **candidats exacts** au regroupement (§ 9.4) : les autres films qui
     * portent une forme **exacte de titre** identique — titre original,
     * translittération ou titre localisé, jamais un alias ni une forme
     * dérivée —, lue dans `answer_key` par `answer_key_norm_movie_uq`. C'est
     * le cas des homonymes et des remakes au même titre (Old Boy). Un film
     * retiré n'en est pas : il ne se travaille plus.
     *
     * Le back-office **suggère**, il ne regroupe jamais seul.
     *
     * @return EloquentCollection<int, Movie>
     */
    public static function exactCandidates(Movie $movie): EloquentCollection
    {
        $kinds = self::titleKindValues();

        /** @var list<string> $forms */
        $forms = AnswerKey::query()
            ->where('movie_id', $movie->id)
            ->whereIn('key_kind', $kinds)
            ->where('normalized', '!=', '')
            ->pluck('normalized')
            ->map(static fn (mixed $form): string => (string) $form)
            ->values()
            ->all();

        if ($forms === []) {
            return new EloquentCollection;
        }

        /** @var list<int> $ids */
        $ids = AnswerKey::query()
            ->whereIn('normalized', $forms)
            ->whereIn('key_kind', $kinds)
            ->where('movie_id', '!=', $movie->id)
            ->pluck('movie_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new EloquentCollection;
        }

        return Movie::query()
            ->with('group:id,label')
            ->whereKey($ids)
            ->where('availability', '!=', ContentAvailability::Withdrawn->value)
            ->orderBy('release_year')
            ->orderBy('id')
            ->get();
    }

    /**
     * Les natures exactes de TITRE — `title_original`, `title_latin`,
     * `title` : un alias ne désigne pas l'œuvre, il valide une réponse.
     *
     * @return list<string>
     */
    private static function titleKindValues(): array
    {
        return [
            AnswerKeyKind::TitleOriginal->value,
            AnswerKeyKind::TitleLatin->value,
            AnswerKeyKind::Title->value,
        ];
    }

    /**
     * Regrouper le film avec un autre.
     *
     * @throws ValidationException
     */
    private function pair(
        Movie $self,
        int $withMovieId,
        ?Movie $other,
        User $curator,
        ?string $label,
        ?string $note,
    ): string {
        $refusal = self::pairRefusal($self, $withMovieId, $other, $curator);

        if ($refusal !== null || ! $other instanceof Movie) {
            throw ValidationException::withMessages([
                'with_movie_id' => __(self::MESSAGE_OF_REFUSAL[$refusal ?? self::REFUSAL_MISSING]),
            ]);
        }

        if ($self->group_id !== null && $self->group_id === $other->group_id) {
            return self::UNCHANGED;
        }

        if ($self->group_id !== null || $other->group_id !== null) {
            $group = MovieGroup::query()
                ->lockForUpdate()
                ->findOrFail($self->group_id ?? $other->group_id);

            $newcomer = $self->group_id === null ? $self : $other;
            $newcomer->group_id = $group->id;
            $newcomer->save();

            return self::JOINED;
        }

        $group = new MovieGroup;
        $group->label = $label ?? self::defaultLabel($self, $other);
        $group->note = $note;
        $group->created_by_id = $curator->id;
        $group->save();

        foreach ([$self, $other] as $member) {
            $member->group_id = $group->id;
            $member->save();
        }

        return self::CREATED;
    }

    /**
     * Rattacher le film à un groupe existant.
     *
     * @throws ValidationException
     */
    private function join(Movie $self, int $groupId): string
    {
        $group = MovieGroup::query()->lockForUpdate()->find($groupId);

        if (! $group instanceof MovieGroup) {
            throw ValidationException::withMessages([
                'group_id' => __('admin.movie.group.group_missing'),
            ]);
        }

        if ($self->group_id === $group->id) {
            return self::UNCHANGED;
        }

        if ($self->group_id !== null) {
            throw ValidationException::withMessages([
                'group_id' => __('admin.movie.group.already_grouped'),
            ]);
        }

        $self->group_id = $group->id;
        $self->save();

        return self::JOINED;
    }

    /**
     * Les films donnés, verrouillés dans l'ordre de leur clé.
     *
     * @param  list<int>  $ids
     * @return EloquentCollection<int, Movie> indexés par identifiant
     */
    private function lockMovies(array $ids): EloquentCollection
    {
        return Movie::query()
            ->whereKey(array_values(array_unique($ids)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Le film et les autres films de son groupe, verrouillés ENSEMBLE dans
     * l'ordre de leur clé, avant le groupe — l'ordre de {@see self::pair()},
     * qui verrouille ses deux films puis le groupe. Verrouiller le groupe
     * avant ses films laisserait un retrait et un regroupement croisés
     * s'attendre l'un l'autre, et MySQL en tuerait un.
     *
     * Le groupe est celui que la fiche a lu ; si le film en a changé entre
     * cette lecture et le verrou, il est désormais verrouillé, son groupe ne
     * bouge plus, et une seconde passe prend les films du nouveau groupe.
     *
     * @return EloquentCollection<int, Movie> indexés par identifiant
     */
    private function lockWithMembers(Movie $movie): EloquentCollection
    {
        $locked = $this->lockMembersOf($movie->id, $movie->group_id);
        $self = $locked->get($movie->id);

        if ($self instanceof Movie && $self->group_id !== $movie->group_id) {
            $locked = $this->lockMembersOf($movie->id, $self->group_id);
        }

        return $locked;
    }

    /**
     * @return EloquentCollection<int, Movie> indexés par identifiant
     */
    private function lockMembersOf(int $movieId, ?int $groupId): EloquentCollection
    {
        return Movie::query()
            ->where(static function (Builder $query) use ($movieId, $groupId): void {
                $query->whereKey($movieId);

                if ($groupId !== null) {
                    $query->orWhere('group_id', $groupId);
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Retirer le film de son groupe ; un groupe réduit à moins de deux films
     * est supprimé, son dernier film détaché avec lui.
     *
     * Les films du groupe sont déjà verrouillés ({@see self::lockWithMembers()}) ;
     * le groupe l'est ensuite, et ses films se relisent sous ce verrou — la
     * seule relecture qui voie un film entré dans le groupe entre-temps, un
     * rattachement verrouillant le groupe avant d'écrire.
     */
    private function leave(Movie $self): string
    {
        $groupId = $self->group_id;

        if ($groupId === null) {
            return self::UNCHANGED;
        }

        $group = MovieGroup::query()->lockForUpdate()->find($groupId);

        $self->group_id = null;
        $self->save();

        $remaining = Movie::query()
            ->where('group_id', $groupId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($remaining->count() < self::MIN_MOVIES) {
            foreach ($remaining as $member) {
                $member->group_id = null;
                $member->save();
            }

            $group?->delete();
        }

        return self::LEFT;
    }
}
