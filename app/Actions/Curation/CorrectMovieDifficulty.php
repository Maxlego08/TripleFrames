<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\MovieDifficulty;
use App\Enums\ThemeKind;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\ThemeEvaluator;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Corriger la difficulté d'un film — spec 20 § 9.6, L20-28b ; spec 30 § 13.1
 * et § 14.2.
 *
 * Écrit `movie.movie_difficulty_override` — la seule colonne de difficulté
 * qu'un humain écrit, et celle qui survit au réimport (spec 10 § 3.1, § 9.3) —,
 * puis, **dans la même transaction**, la valeur effective
 * `movie_difficulty = COALESCE(override, derived)` et la réévaluation des
 * thèmes de nature `difficulty` du film
 * (`ThemeEvaluator::syncMovie($movie, ThemeKind::Difficulty)`). `NULL` rend la
 * main à la dérivation.
 *
 * **Ne relance jamais la dérivation** : la correction ne change pas la
 * population des déciles (spec 30 § 14.2). La valeur effective est écrite par
 * une seule instruction qui relit `movie_difficulty_derived` en base, sous le
 * verrou du film : une dérivation concurrente (spec 30 § 14.1 point 6, elle
 * aussi en `COALESCE`) ne peut ni écraser la correction ni être écrasée par
 * une valeur lue plus tôt.
 *
 * Journal `movie.difficulty_corrected` (D66 du 07/10), seulement si la
 * correction change : `from`, `to`. Garde `MoviePolicy::curate`, rejouée sous
 * le verrou.
 */
final class CorrectMovieDifficulty
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly ThemeEvaluator $themes,
    ) {}

    /**
     * Rend `true` si la correction a changé.
     *
     * @throws AuthorizationException le film est devenu incurable (retiré) entre la garde et le verrou
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, ?MovieDifficulty $override): bool
    {
        $changed = DB::transaction(function () use ($movie, $curator, $override): bool {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $before = $locked->movie_difficulty_override;

            if ($before === $override) {
                return false;
            }

            // `COALESCE(override, derived)` dit en une instruction portable :
            // une correction est sa propre valeur effective ; son retrait
            // relit la dérivation EN BASE — SQLite évaluerait un COALESCE sur
            // l'ancienne correction, MySQL sur la nouvelle.
            Movie::query()->whereKey($locked->id)->update([
                'movie_difficulty_override' => $override?->value,
                'movie_difficulty' => $override === null ? DB::raw('movie_difficulty_derived') : $override->value,
                'updated_at' => now(),
            ]);

            $locked->refresh();

            // Synchrone, dans la transaction du geste (spec 30 § 13.2).
            $this->themes->syncMovie($locked, ThemeKind::Difficulty);

            $this->journal->record(
                $curator,
                AdminActionType::MovieDifficultyCorrected,
                $locked->id,
                details: AdminActionDetails::difficultyCorrected($before, $override),
            );

            return true;
        });

        $movie->refresh();

        return $changed;
    }
}
