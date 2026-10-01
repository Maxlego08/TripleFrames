<?php

namespace App\Support\Catalog;

use App\Enums\ThemeKind;
use App\Enums\TmdbTagKind;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\MovieTmdbTag;
use App\Models\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * **Le seul évaluateur d'appartenance film ↔ thème du dépôt** (spec 30 § 13,
 * D43 du 01/10).
 *
 * La règle d'un thème (§ 12.1) se lit localement — `movie_tmdb_tag` pour le
 * genre et le studio, des colonnes de `movie` pour le reste —, **sans aucun
 * appel réseau** (règle 6). Sémantique normative :
 *
 * - `rule_value` NULL : aucune règle automatique, le thème ne contient que ses
 *   ajouts manuels, **même nié** ;
 * - entrée nulle (`release_year`, `collection_id`, `movie_difficulty`) ou règle
 *   illisible : le film ne satisfait **jamais** la règle, niée ou non ;
 * - `rule_negated` inverse le résultat dans les autres cas ;
 * - tous les thèmes sont évalués, **publiés ou non** : publier un thème est un
 *   basculement de drapeau, sans recalcul.
 *
 * **Écriture d'une ligne `movie_theme`** ({@see self::write()}, seule méthode
 * d'écriture, partagée par {@see self::syncMovie()} et
 * {@see self::syncTheme()}) : `is_auto` est réécrit librement, `is_active`
 * passe par {@see MovieTheme::resolveIsActive()} sur un `manual_state` **relu
 * sous verrou** dans la transaction de l'écriture — jamais pris d'une lecture
 * antérieure, sans quoi un passage qui a lu la ligne avant qu'un curateur pose
 * `removed` réactiverait le film (mise à jour perdue). Une ligne sans règle ni
 * exception est supprimée par un `DELETE` conditionnel ; une exception
 * `removed` est conservée. L'évaluateur n'écrit **jamais** `manual_state`,
 * `assigned_by_id` ni `assigned_at`, et aucune ligne `admin_action` : ce n'est
 * pas un geste du back-office.
 *
 * Il ne touche ni `answer_key` ni l'ambiguïté d'un préfixe (§ 13.3) : un thème
 * ne reprojette rien.
 */
final readonly class ThemeEvaluator
{
    /** Films lus par lot dans {@see self::syncTheme()}. */
    private const int CHUNK = 500;

    /**
     * Tentatives d'un lot de {@see self::syncTheme()} sur un interblocage
     * MySQL. Le lot verrouille les lignes d'un thème sur 500 films pendant
     * qu'un import verrouille celles d'un film sur tous les thèmes : les
     * verrous d'intervalle de deux insertions croisées peuvent s'interbloquer.
     * Le lot est idempotent et sa transaction est la plus externe dans le job
     * comme dans `catalog:themes`, donc Laravel le rejoue au lieu de faire
     * échouer le job, qui n'a qu'un essai.
     */
    private const int DEADLOCK_ATTEMPTS = 3;

    /** Le décompte de la décennie : `[rule_value, rule_value + 10)`. */
    private const int DECADE_YEARS = 10;

    /**
     * Clé d'une étiquette, `tag_kind:tmdb_tag_id` (`genre:16`, `company:420`) :
     * la forme de `$tagKeys` de {@see self::matches()}.
     */
    public static function tagKey(TmdbTagKind $kind, int $tmdbTagId): string
    {
        return $kind->value.':'.$tmdbTagId;
    }

    /**
     * Les clés d'étiquette d'un film, depuis ses lignes `movie_tmdb_tag`.
     *
     * @param  iterable<MovieTmdbTag>  $tags
     * @return array<string, true>
     */
    public static function tagKeysOf(iterable $tags): array
    {
        $keys = [];

        foreach ($tags as $tag) {
            $keys[self::tagKey($tag->tag_kind, $tag->tmdb_tag_id)] = true;
        }

        return $keys;
    }

    /**
     * La règle pure du § 12.1, sans base.
     *
     * @param  array<string, true>  $tagKeys  clés `tag_kind:tmdb_tag_id` des étiquettes du film
     */
    public function matches(Theme $theme, Movie $movie, array $tagKeys): bool
    {
        $satisfied = $this->ruleSatisfied($theme, $movie, $tagKeys);

        if ($satisfied === null) {
            return false;
        }

        return $theme->rule_negated ? ! $satisfied : $satisfied;
    }

    /**
     * Tous les thèmes — ou ceux d'une nature — pour un film, dans la
     * transaction de l'appelant (celle qui écrit le film à l'import).
     *
     * La liste des thèmes est relue à **chaque** appel, jamais mise en cache
     * pour la durée d'un balayage (§ 13.2) : un thème créé pendant un import
     * est vu par le film suivant.
     *
     * @param  array<string, true>|null  $tagKeys  fournies par l'importeur depuis l'appel de détail ; lues en base sinon
     */
    public function syncMovie(Movie $movie, ?ThemeKind $kind = null, ?array $tagKeys = null): void
    {
        /** @var EloquentCollection<int, Theme> $themes */
        $themes = Theme::query()
            ->when($kind !== null, static fn (Builder $query): Builder => $query->where('theme_kind', $kind))
            ->orderBy('id')
            ->get(['id', 'theme_kind', 'rule_value', 'rule_negated']);

        if ($themes->isEmpty()) {
            return;
        }

        $tagKeys ??= self::tagKeysOf(MovieTmdbTag::query()->where('movie_id', $movie->id)->get());

        DB::transaction(function () use ($movie, $themes, $tagKeys): void {
            /** @var array<int, MovieTheme> $locked */
            $locked = MovieTheme::query()
                ->where('movie_id', $movie->id)
                ->whereIn('theme_id', $themes->modelKeys())
                ->lockForUpdate()
                ->get()
                ->keyBy('theme_id')
                ->all();

            foreach ($themes as $theme) {
                $this->write($movie->id, $theme->id, $this->matches($theme, $movie, $tagKeys), $locked[$theme->id] ?? null);
            }
        });
    }

    /**
     * Un thème sur tout le catalogue, par lots ; chaque lot écrit dans une
     * transaction courte qui relit ses lignes sous verrou.
     *
     * @return int nombre de lignes créées, modifiées ou supprimées
     */
    public function syncTheme(Theme $theme): int
    {
        $tagKind = match ($theme->theme_kind) {
            ThemeKind::Genre => TmdbTagKind::Genre,
            ThemeKind::Studio => TmdbTagKind::Company,
            ThemeKind::Decade, ThemeKind::Saga, ThemeKind::Language, ThemeKind::Difficulty => null,
        };

        $query = Movie::query()->select([
            'id', 'original_language', 'release_year', 'collection_id', 'movie_difficulty',
        ]);

        if ($tagKind !== null) {
            $query->with(['tmdbTags' => static fn ($tags) => $tags->where('tag_kind', $tagKind->value)]);
        }

        $changed = 0;

        $query->chunkById(self::CHUNK, function (EloquentCollection $movies) use ($theme, $tagKind, &$changed): void {
            /** @var array<int, bool> $auto */
            $auto = [];

            /** @var Movie $movie */
            foreach ($movies as $movie) {
                $tagKeys = $tagKind === null ? [] : self::tagKeysOf($movie->tmdbTags);
                $auto[$movie->id] = $this->matches($theme, $movie, $tagKeys);
            }

            $changed += DB::transaction(function () use ($theme, $auto): int {
                /** @var array<int, MovieTheme> $locked */
                $locked = MovieTheme::query()
                    ->where('theme_id', $theme->id)
                    ->whereIn('movie_id', array_keys($auto))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('movie_id')
                    ->all();

                $written = 0;

                foreach ($auto as $movieId => $isAuto) {
                    $written += $this->write($movieId, $theme->id, $isAuto, $locked[$movieId] ?? null);
                }

                return $written;
            }, self::DEADLOCK_ATTEMPTS);
        });

        return $changed;
    }

    /**
     * Vrai, faux, ou `null` quand la règle ne s'applique pas : règle absente
     * ou illisible, entrée nulle. `null` n'est jamais nié.
     *
     * @param  array<string, true>  $tagKeys
     */
    private function ruleSatisfied(Theme $theme, Movie $movie, array $tagKeys): ?bool
    {
        $rule = $theme->rule_value;

        if ($rule === null) {
            return null;
        }

        return match ($theme->theme_kind) {
            ThemeKind::Genre => self::isIdentifier($rule)
                ? isset($tagKeys[self::tagKey(TmdbTagKind::Genre, (int) $rule)])
                : null,
            ThemeKind::Studio => $this->anyCompany(ThemeRules::studioCompanyIds($rule), $tagKeys),
            ThemeKind::Decade => self::isIdentifier($rule) && $movie->release_year !== null
                ? $movie->release_year >= (int) $rule && $movie->release_year < (int) $rule + self::DECADE_YEARS
                : null,
            ThemeKind::Saga => self::isIdentifier($rule) && $movie->collection_id !== null
                ? $movie->collection_id === (int) $rule
                : null,
            ThemeKind::Language => $movie->original_language === $rule,
            ThemeKind::Difficulty => $movie->movie_difficulty === null
                ? null
                : $movie->movie_difficulty->value === $rule,
        };
    }

    /**
     * L'union des sociétés d'une règle studio ; `null` pour une règle vide.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, true>  $tagKeys
     */
    private function anyCompany(array $companyIds, array $tagKeys): ?bool
    {
        if ($companyIds === []) {
            return null;
        }

        foreach ($companyIds as $companyId) {
            if (isset($tagKeys[self::tagKey(TmdbTagKind::Company, $companyId)])) {
                return true;
            }
        }

        return false;
    }

    private static function isIdentifier(string $rule): bool
    {
        return ctype_digit($rule);
    }

    /**
     * La seule écriture d'une ligne `movie_theme` par l'évaluateur,
     * idempotente. `$row` est la ligne relue **sous verrou** dans la
     * transaction en cours, ou `null` si elle n'existait pas.
     *
     * @return int 1 si la ligne a été créée, modifiée ou supprimée, 0 sinon
     */
    private function write(int $movieId, int $themeId, bool $isAuto, ?MovieTheme $row): int
    {
        if ($row === null) {
            if (! $isAuto) {
                return 0;
            }

            try {
                $this->insert($movieId, $themeId);

                return 1;
            } catch (UniqueConstraintViolationException) {
                // Un chemin concurrent (un import, un job, un geste) a créé la
                // ligne entre la lecture et l'insertion : relue sous verrou,
                // elle passe par la mise à jour. Rejouée une fois, pas plus.
                $row = MovieTheme::query()
                    ->where('movie_id', $movieId)
                    ->where('theme_id', $themeId)
                    ->lockForUpdate()
                    ->first();

                if ($row === null) {
                    $this->insert($movieId, $themeId);

                    return 1;
                }
            }
        }

        if (! $isAuto && $row->manual_state === null) {
            return MovieTheme::query()
                ->whereKey($row->id)
                ->whereNull('manual_state')
                ->delete() > 0 ? 1 : 0;
        }

        $isActive = MovieTheme::resolveIsActive($isAuto, $row->manual_state);

        if ($row->is_auto === $isAuto && $row->is_active === $isActive) {
            return 0;
        }

        $row->is_auto = $isAuto;
        $row->is_active = $isActive;
        $row->save();

        return 1;
    }

    /**
     * Une ligne purement automatique, dans un point de sauvegarde : une
     * collision sur `movie_theme_uq` n'annule que l'insertion, jamais la
     * transaction de l'appelant.
     */
    private function insert(int $movieId, int $themeId): void
    {
        DB::transaction(static function () use ($movieId, $themeId): void {
            $row = new MovieTheme;
            $row->movie_id = $movieId;
            $row->theme_id = $themeId;
            $row->is_auto = true;
            $row->manual_state = null;
            $row->is_active = MovieTheme::resolveIsActive(true, null);
            $row->assigned_by_id = null;
            $row->assigned_at = null;
            $row->save();
        });
    }
}
