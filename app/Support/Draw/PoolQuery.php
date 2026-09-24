<?php

namespace App\Support\Draw;

use App\Models\Movie;
use App\Models\Round;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use stdClass;

/**
 * LE constructeur de requête du vivier (spec 30 § 3, contrat C2).
 *
 * **Aucun autre prédicat de vivier n'existe dans `app/`.** Le compteur du lobby,
 * la garde et le tirage du lancement, le solo, la supervision et les leurres
 * l'appellent tous : un lobby qui annonce 47 films et une garde qui n'en trouve
 * que 7, sans cause visible entre deux écrans, est exactement la panne qu'une
 * fonction unique rend impossible (spec 10 § 12 point (1)).
 *
 * Le prédicat, toutes clauses en ET (§ 3.3) :
 * 1. **catalogue** — `published` ET `clear`, par {@see Movie::inPool()} ;
 * 2. **éligibilité à `N`** — `movie_projection.levels_count >= N`, jamais le
 *    masque 1-3-5 (garde de transition de la publication, pas de jeu, § 2.5) ;
 * 3. **thèmes** — `EXISTS movie_theme` actif sur les thèmes demandés ∩ publiés,
 *    en UNION ; aucun thème effectif = branche sans thème, jamais un `IN ()` ;
 * 4. **non-répétition** — `NOT EXISTS round` démarrée dans la fenêtre du salon,
 *    manche annulée comprise, sur le FILM et jamais sur le `movie_group` ;
 * 5. **exclusions** — films et groupes, chaque clause omise quand sa liste est vide.
 *
 * Forme SQL (§ 3.6) : `EXISTS` / `NOT EXISTS`, jointure interne sur
 * `movie_projection` toujours présente, tables non aliasées, **aucun
 * `GROUP BY`** (`ONLY_FULL_GROUP_BY` est actif sous MySQL et pas sous SQLite),
 * aucun agrégat sur `frame`, **aucune lecture dans un JSON** : `themeIds` arrive
 * désérialisé par {@see PoolScope}. Le même résultat dans les deux moteurs.
 *
 * L'unité de compte est l'**œuvre** (§ 3.4) : un film sans `group_id`, ou un
 * `movie_group` entier, qui compte pour un. `PoolReport::$count`,
 * `game.draw_pool_size` et `DrawResult::$poolSize` s'expriment dans cette unité.
 *
 * Aucun verrou, aucune écriture, aucune transaction ouverte : appelé dans la
 * transaction de lancement, il lit l'instantané qu'elle a fixé (§ 6.5).
 *
 * **Lié `scoped` dans le conteneur** (`AppServiceProvider`) : une instance par
 * requête HTTP ou par job, que partagent le rapport et le tirage d'un même
 * lancement. Les thèmes publiés y sont lus au plus une fois, et une
 * dépublication vaut à l'instance suivante sans aucune invalidation (§ 3.1).
 */
final readonly class PoolQuery
{
    /**
     * Les films du vivier : `movie ⋈ movie_projection`, triés par `movie.id`
     * croissant, **`movie.*` seulement**.
     *
     * `movie_projection` porte ses propres `created_at` et `updated_at` : sans
     * `select`, la jointure hydraterait des `Movie` aux horodatages de la
     * projection. Les appelants (leurres, spec 70) filtrent sur
     * `movie_projection.*` sans le sélectionner.
     *
     * @return Builder<Movie>
     */
    public function movies(PoolScope $scope): Builder
    {
        return $this->pool($scope)
            ->select('movie.*')
            ->orderBy('movie.id');
    }

    /**
     * Le vivier compté en œuvres, sans `GROUP BY` :
     * `COUNT(DISTINCT CASE WHEN group_id IS NULL THEN id END) + COUNT(DISTINCT group_id)`.
     */
    public function countWorks(PoolScope $scope): int
    {
        $works = $this->pool($scope)
            ->toBase()
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN movie.group_id IS NULL THEN movie.id END)'
                .' + COUNT(DISTINCT movie.group_id) AS works',
            )
            ->value('works');

        return is_numeric($works) ? (int) $works : 0;
    }

    /**
     * Les films du vivier réduits à ce que le tirage lit, triés par `movieId`
     * croissant — une requête, sous le même `WHERE` que {@see self::movies()}.
     *
     * @return list<PoolCandidate>
     */
    public function candidates(PoolScope $scope): array
    {
        $rows = $this->pool($scope)
            ->toBase()
            ->select(['movie.id', 'movie.group_id', 'movie_projection.levels_mask'])
            ->orderBy('movie.id')
            ->get()
            ->all();

        return array_values(array_map(
            static fn (stdClass $row): PoolCandidate => new PoolCandidate(
                movieId: (int) $row->id,
                groupId: $row->group_id === null ? null : (int) $row->group_id,
                levelsMask: (int) $row->levels_mask,
            ),
            $rows,
        ));
    }

    /**
     * Identifiants des thèmes publiés, croissants — alimente
     * `RoomSettings::normalize($raw, $version, $availableThemeIds)`.
     *
     * Même ensemble que {@see self::publishedThemeIdsByKey()}, par construction :
     * les deux sortent de la même lecture.
     *
     * @return list<int>
     */
    public function publishedThemeIds(): array
    {
        $ids = array_values($this->publishedThemes());
        sort($ids);

        return $ids;
    }

    /**
     * `theme.key` → `theme.id` des thèmes publiés, triés par `sort_order` puis
     * `key` — alimente `RoomSettingsEditor` (R-12). **Seules les clés quittent le
     * serveur** (spec 10 § 1.1) : `theme.id` n'apparaît dans aucune charge utile.
     *
     * @return array<string, int>
     */
    public function publishedThemeIdsByKey(): array
    {
        return $this->publishedThemes();
    }

    /**
     * Thèmes effectifs de la clause 3 : `themeIds ∩ publishedThemeIds()`,
     * croissants. Une sélection vide rend `[]` **sans lire les thèmes publiés** —
     * le cas par défaut, le seul que l'écran du jalon 1 produise.
     *
     * Exposé pour le rapport de vivier, dont la cause `themeKeys` exige
     * `effective ≠ []` (§ 4.3) : la règle d'intersection reste écrite ici seule.
     *
     * @return list<int>
     */
    public function effectiveThemeIds(PoolScope $scope): array
    {
        if ($scope->themeIds === []) {
            return [];
        }

        return array_values(array_filter(
            $this->publishedThemeIds(),
            static fn (int $themeId): bool => in_array($themeId, $scope->themeIds, true),
        ));
    }

    /**
     * Vrai si un thème demandé n'est plus publié : il est **élagué à la lecture**
     * et ses lignes `movie_theme` actives ne filtrent plus rien (§ 3.3 clause 3).
     *
     * Un booléen, jamais une liste d'identifiants : c'est lui que porte
     * `PoolReport::$themesPruned`.
     */
    public function themesPruned(PoolScope $scope): bool
    {
        if ($scope->themeIds === []) {
            return false;
        }

        return array_diff($scope->themeIds, $this->publishedThemeIds()) !== [];
    }

    /**
     * Le prédicat du vivier, sans sélection ni tri.
     *
     * @return Builder<Movie>
     */
    private function pool(PoolScope $scope): Builder
    {
        $query = Movie::query()
            ->join('movie_projection', 'movie_projection.movie_id', '=', 'movie.id')
            ->inPool();

        if ($scope->framesPerRound !== null) {
            $query->where('movie_projection.levels_count', '>=', $scope->framesPerRound);
        }

        $effectiveThemeIds = $this->effectiveThemeIds($scope);

        if ($effectiveThemeIds !== []) {
            $query->whereExists(static function (QueryBuilder $themes) use ($effectiveThemeIds): void {
                $themes->selectRaw('1')
                    ->from('movie_theme')
                    ->whereColumn('movie_theme.movie_id', 'movie.id')
                    ->whereIn('movie_theme.theme_id', $effectiveThemeIds)
                    ->where('movie_theme.is_active', true);
            });
        }

        if ($scope->noRepeatMovies
            && $scope->roomId !== null
            && $scope->memorySince !== null
            && $scope->playedUntil !== null) {
            $roomId = $scope->roomId;
            // Au format EXACT de la colonne `timestamp(3)`, jamais à celui de la
            // grammaire qui tronque à la seconde (voir RoomMemoryWindow).
            $since = (new Round)->fromDateTime($scope->memorySince);
            $until = (new Round)->fromDateTime($scope->playedUntil);

            $query->whereNotExists(static function (QueryBuilder $played) use ($roomId, $since, $until): void {
                $played->selectRaw('1')
                    ->from('round')
                    ->where('round.room_id', $roomId)
                    ->whereColumn('round.movie_id', 'movie.id')
                    ->where('round.started_at', '>=', $since)
                    ->where('round.started_at', '<=', $until);
            });
        }

        if ($scope->excludedMovieIds !== []) {
            $query->whereNotIn('movie.id', $scope->excludedMovieIds);
        }

        if ($scope->excludedGroupIds !== []) {
            $excludedGroupIds = $scope->excludedGroupIds;

            $query->where(static function (Builder $grouped) use ($excludedGroupIds): void {
                $grouped->whereNull('movie.group_id')
                    ->orWhereNotIn('movie.group_id', $excludedGroupIds);
            });
        }

        return $query;
    }

    /**
     * Les thèmes publiés, `theme.key` → `theme.id`, triés par `sort_order` puis
     * `key` — lus **une seule fois par instance**, à la première lecture qui en a
     * besoin ; une sélection de thèmes vide ne les lit jamais (§ 3.3 clause 3).
     *
     * La mémoire est celle de `once()`, indexée par l'instance (`WeakMap`) : elle
     * meurt avec elle, donc avec la requête ou le job (liaison `scoped`), et la
     * classe reste `readonly` — une propriété non initialisée puis affectée hors
     * du constructeur est refusée par l'analyse statique.
     *
     * Le tri se fait en PHP, octet par octet sur `key` : une collation MySQL
     * insensible à la casse et aux signes rangerait `genre.sci-fi` et
     * `genre.science` autrement que SQLite.
     *
     * @return array<string, int>
     */
    private function publishedThemes(): array
    {
        return once(static function (): array {
            $themes = Theme::query()
                ->where('is_published', true)
                ->get(['id', 'key', 'sort_order'])
                ->all();

            usort($themes, static fn (Theme $a, Theme $b): int => ($a->sort_order <=> $b->sort_order)
                ?: strcmp($a->key, $b->key));

            $byKey = [];

            foreach ($themes as $theme) {
                $byKey[$theme->key] = $theme->id;
            }

            return $byKey;
        });
    }
}
