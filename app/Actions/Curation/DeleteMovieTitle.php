<?php

namespace App\Actions\Curation;

use App\Enums\ContentOrigin;
use App\Enums\Locale;
use App\Models\Movie;
use App\Models\MovieTitle;
use App\Models\User;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Retirer le titre d'un film dans une locale activée — spec 20 § 9.1, ligne
 * 22 de la matrice des capacités (`can:curate,movie`).
 *
 * **Seule une ligne `curator` se retire.** Une ligne `tmdb` se CORRIGE, elle
 * ne se supprime pas : la resynchronisation suivante la recréerait, et le
 * curateur croirait avoir retiré un titre qui revient seul. Le refus est une
 * erreur traduite (`admin.movie.titles.destroy.not_curator`), jamais un 403.
 *
 * Retirer une correction qui remplaçait un titre TMDB laisse la locale sans
 * titre jusqu'à la resynchronisation suivante, qui la repourvoit. **Rien
 * n'est recopié d'une autre langue** : l'absence de la ligne est
 * l'information (spec 10 § 3.4), et la file « titres manquants » la lit.
 *
 * Une transaction, le film verrouillé : la ligne, le masque de couverture des
 * titres et les clés de réponse, reprojetées par différence avec le recompte
 * de leur ambiguïté.
 */
final class DeleteMovieTitle
{
    public function __construct(
        private readonly MovieProjector $projector,
        private readonly AnswerKeyProjector $answerKeys,
    ) {}

    /**
     * @throws AuthorizationException le film a été retiré entre la garde et le verrou
     * @throws ValidationException aucune ligne, ou une ligne TMDB — sans aucune écriture
     * @throws Throwable
     */
    public function handle(Movie $movie, User $curator, Locale $locale): void
    {
        DB::transaction(function () use ($movie, $curator, $locale): void {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('curate', $locked);

            $row = MovieTitle::query()
                ->where('movie_id', $locked->id)
                ->where('locale', $locale->value)
                ->first();

            if (! $row instanceof MovieTitle) {
                throw ValidationException::withMessages([
                    'title' => __('admin.movie.titles.destroy.missing'),
                ]);
            }

            if ($row->origin !== ContentOrigin::Curator) {
                throw ValidationException::withMessages([
                    'title' => __('admin.movie.titles.destroy.not_curator'),
                ]);
            }

            $row->delete();

            $this->projector->recompute($locked);
            $this->answerKeys->project($locked);
        });

        $movie->refresh();
    }
}
