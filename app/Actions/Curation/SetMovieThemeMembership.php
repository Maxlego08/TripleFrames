<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ThemeMembershipState;
use App\Models\Movie;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\ValueObjects\Admin\AdminActionDetails;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * L'appartenance manuelle d'un film à un thème — spec 20 § 9.6, spec 30
 * § 13.1, ligne 28 de la matrice (`can:curate,movie` ; D43 du 01/10).
 *
 * **Le seul écrivain de l'exception** (`movie_theme.manual_state`,
 * `assigned_by_id`, `assigned_at`) : le geste de la fiche film passe par
 * {@see self::handle()}, le collage avec thèmes (spec 20 § 3.3) par
 * {@see self::applyPasteAddition()}, et l'un comme l'autre écrivent par la
 * même porte privée que {@see self::apply()}. L'évaluateur
 * (`ThemeEvaluator`) n'y touche jamais.
 *
 * - **Ligne relue sous verrou** (`lockForUpdate`) dans la transaction de
 *   l'écriture, jamais prise d'une lecture antérieure : un `syncTheme()`
 *   concurrent qui réécrit `is_auto` ne perd pas l'exception, et l'exception
 *   ne perd pas son `is_auto` (critique C1).
 * - **Collision d'insertion rejouée une fois** : si l'évaluateur, un import
 *   ou un autre geste crée la ligne entre la lecture et l'insertion, la
 *   ligne est relue sous verrou et la décision prise de nouveau (C2).
 * - `is_active = MovieTheme::resolveIsActive(is_auto, manual_state)`, seule
 *   dérivation admise. Une ligne créée par l'exception naît `is_auto` faux :
 *   la règle la corrigera au prochain passage de l'évaluateur, sans toucher
 *   à l'exception.
 * - Annuler l'exception (`null`) rend la main à la règle : `assigned_by_id`
 *   et `assigned_at` reviennent à NULL, et la ligne disparaît si la règle ne
 *   la porte pas (`is_auto` faux).
 *
 * **Non-écrasement au collage** (critique C6) : un `removed` posé par un
 * curateur n'est jamais changé en `added`, et une ligne déjà active par la
 * seule règle n'est pas touchée — un `added` superflu figerait
 * l'appartenance, qu'une correction de la règle ne lèverait plus.
 *
 * Aucune reprojection : un thème ne touche ni `answer_key` ni l'ambiguïté
 * d'un préfixe (spec 30 § 13.3).
 */
final class SetMovieThemeMembership
{
    /** L'exception `added` est posée. */
    public const string ADDED = 'added';

    /** L'exception `removed` est posée. */
    public const string REMOVED = 'removed';

    /** L'exception est annulée : la règle décide seule. */
    public const string CLEARED = 'cleared';

    /** Rien à faire : l'exception demandée est déjà en base. */
    public const string UNCHANGED = 'unchanged';

    /** Collage : l'exception `added` a été posée. */
    public const string PASTE_APPLIED = 'applied';

    /** Collage : un curateur avait retiré le film de ce thème ; rien n'est écrit. */
    public const string PASTE_KEPT_REMOVED = 'kept_removed';

    /** Collage : le film est déjà dans le thème, par la règle ou par un ajout ; rien n'est écrit. */
    public const string PASTE_ALREADY_ACTIVE = 'already_active';

    /**
     * Tentatives de la transaction du geste sur un interblocage MySQL : le
     * verrou d'intervalle d'une ligne absente peut croiser l'insertion d'un
     * `syncTheme()`. Le geste est idempotent, et sa transaction est la plus
     * externe : Laravel le rejoue.
     */
    private const int DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly AdminJournal $journal,
    ) {}

    /**
     * Le geste de la fiche film : verrouille le film, relit `curate` sous le
     * verrou (un film retiré entre-temps refuse tout geste), écrit
     * l'exception et, si elle change, la ligne `movie.theme_set`.
     *
     * @return self::ADDED|self::REMOVED|self::CLEARED|self::UNCHANGED
     *
     * @throws AuthorizationException le film ne se cure plus (retiré)
     * @throws Throwable
     */
    public function handle(User $curator, Movie $movie, Theme $theme, ?ThemeMembershipState $state): string
    {
        return DB::transaction(function () use ($curator, $movie, $theme, $state): string {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $previous = $this->apply($locked, $theme, $state, $curator->id, CarbonImmutable::now());

            if ($previous === $state) {
                return self::UNCHANGED;
            }

            $this->journal->record(
                $curator,
                AdminActionType::MovieThemeSet,
                $locked->id,
                details: AdminActionDetails::movieThemeSet($theme->key, $previous?->value, $state?->value),
            );

            return match ($state) {
                ThemeMembershipState::Added => self::ADDED,
                ThemeMembershipState::Removed => self::REMOVED,
                null => self::CLEARED,
            };
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Ajoute un film à plusieurs thèmes dans une seule transaction : le film
     * et l'autorisation ne sont relus qu'une fois, puis chaque changement
     * garde sa propre ligne `movie.theme_set`.
     *
     * @param  iterable<Theme>  $themes
     * @return int Nombre d'exceptions effectivement ajoutées.
     *
     * @throws AuthorizationException le film ne se cure plus (retiré)
     * @throws Throwable
     */
    public function handleAdditions(User $curator, Movie $movie, iterable $themes): int
    {
        $orderedThemes = collect($themes)
            ->unique(static fn (Theme $theme): int => $theme->id)
            ->sortBy(static fn (Theme $theme): int => $theme->id)
            ->values();

        return DB::transaction(function () use ($curator, $movie, $orderedThemes): int {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $changed = 0;
            $at = CarbonImmutable::now();

            foreach ($orderedThemes as $theme) {
                $state = ThemeMembershipState::Added;
                $previous = $this->apply($locked, $theme, $state, $curator->id, $at);

                if ($previous === $state) {
                    continue;
                }

                $this->journal->record(
                    $curator,
                    AdminActionType::MovieThemeSet,
                    $locked->id,
                    details: AdminActionDetails::movieThemeSet($theme->key, $previous?->value, $state->value),
                );
                $changed++;
            }

            return $changed;
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Écrit l'exception `$state` — sans verrou du film, sans autorisation ni
     * journal : l'appelant les porte, dans sa transaction ouverte.
     *
     * @return ThemeMembershipState|null l'exception AVANT l'écriture ; égale à `$state`, rien n'a été écrit
     */
    public function apply(Movie $movie, Theme $theme, ?ThemeMembershipState $state, ?int $actorId, CarbonImmutable $at): ?ThemeMembershipState
    {
        return $this->withLockedRow($movie->id, $theme->id, function (?MovieTheme $row) use ($movie, $theme, $state, $actorId, $at): ?ThemeMembershipState {
            $previous = $row?->manual_state;

            if ($previous !== $state) {
                $this->write($movie->id, $theme->id, $row, $state, $actorId, $at);
            }

            return $previous;
        });
    }

    /**
     * L'exception `added` d'un collage avec thèmes (spec 20 § 3.3), sous les
     * deux règles de non-écrasement : un `removed` reste `removed`, une ligne
     * déjà active (par la règle ou par un ajout) n'est pas touchée. Dans la
     * transaction de l'import du film, après l'évaluation automatique.
     *
     * @return self::PASTE_APPLIED|self::PASTE_KEPT_REMOVED|self::PASTE_ALREADY_ACTIVE
     */
    public function applyPasteAddition(Movie $movie, Theme $theme, ?int $actorId, CarbonImmutable $at): string
    {
        return $this->withLockedRow($movie->id, $theme->id, function (?MovieTheme $row) use ($movie, $theme, $actorId, $at): string {
            if ($row?->manual_state === ThemeMembershipState::Removed) {
                return self::PASTE_KEPT_REMOVED;
            }

            if ($row !== null && $row->is_active) {
                return self::PASTE_ALREADY_ACTIVE;
            }

            $this->write($movie->id, $theme->id, $row, ThemeMembershipState::Added, $actorId, $at);

            return self::PASTE_APPLIED;
        });
    }

    /**
     * Rend la ligne relue sous verrou — ou `null` — à `$decide`, dans la
     * transaction de l'appelant ; sur une collision d'insertion (que
     * {@see self::write()} isole dans un point de sauvegarde), relit et
     * décide de nouveau, une fois.
     *
     * @template TResult
     *
     * @param  Closure(MovieTheme|null): TResult  $decide
     * @return TResult
     */
    private function withLockedRow(int $movieId, int $themeId, Closure $decide): mixed
    {
        $attempt = fn (): mixed => $decide(
            MovieTheme::query()
                ->where('movie_id', $movieId)
                ->where('theme_id', $themeId)
                ->lockForUpdate()
                ->first(),
        );

        try {
            return $attempt();
        } catch (UniqueConstraintViolationException) {
            // Un chemin concurrent a créé la ligne entre la lecture et
            // l'insertion : elle est relue sous verrou. Rejouée une fois.
            return $attempt();
        }
    }

    /**
     * La seule écriture de l'exception. `$row` est la ligne relue sous
     * verrou, ou `null` si elle n'existe pas.
     */
    private function write(int $movieId, int $themeId, ?MovieTheme $row, ?ThemeMembershipState $state, ?int $actorId, CarbonImmutable $at): void
    {
        if ($row === null) {
            if ($state === null) {
                return;
            }

            $row = new MovieTheme;
            $row->movie_id = $movieId;
            $row->theme_id = $themeId;
            $row->is_auto = false;
            $row->manual_state = $state;
            $row->is_active = MovieTheme::resolveIsActive(false, $state);
            $row->assigned_by_id = $actorId;
            $row->assigned_at = $at;

            // Dans un point de sauvegarde : une collision sur `movie_theme_uq`
            // n'annule que l'insertion, jamais la transaction de l'appelant.
            DB::transaction(static function () use ($row): void {
                $row->save();
            });

            return;
        }

        if ($state === null && ! $row->is_auto) {
            MovieTheme::query()->whereKey($row->id)->delete();

            return;
        }

        $row->manual_state = $state;
        $row->is_active = MovieTheme::resolveIsActive($row->is_auto, $state);
        $row->assigned_by_id = $state === null ? null : $actorId;
        $row->assigned_at = $state === null ? null : $at;
        $row->save();
    }
}
