<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\RoundIncidentReason;
use App\Jobs\Game\WithdrawContentFromLiveRounds;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\SeenFrame;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\AnswerKeyProjector;
use App\Support\Catalog\MovieProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Suspendre un film en un clic — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:suspend,movie`, administrateur seul), régime conservatoire du
 * § 11.1 : réversible, aucun examen préalable, fichiers conservés.
 *
 * **Une transaction**, le film verrouillé puis ses images :
 *
 * - le film passe `suspended`, `availability_changed_at`, motif facultatif
 *   sur `availability_reason` ;
 * - **seules ses images `published` suivent** en `suspended` (E10-23,
 *   n° 18) : une image `draft` ou `unpublished` garde son état, qui n'est
 *   stocké nulle part ailleurs, et ne peut pas être publiée tant que le film
 *   est suspendu (la revue refuse une image d'un film suspendu) ;
 * - les `seen_frame` des images suivies sont **supprimées explicitement**
 *   (spec 10 § 4.3) ;
 * - ligne `movie.suspended`, avec la demande de retrait liée s'il y en a une
 *   — **aucune ligne par image** (invariant 9 : c'est ce qui fait reconnaître
 *   la cascade à la levée) ;
 * - `MovieProjector::recompute` et `AnswerKeyProjector::recomputeAmbiguity()`
 *   sur les formes du film : sa sortie du catalogue publié peut lever des
 *   ambiguïtés (spec 10 § 3.2 règle 2, § 3.5).
 *
 * Sortie du vivier **à la seconde** : le vivier ne lit que `published`, et
 * un lobby peut se rebloquer à sa prochaine vérification. Après commit,
 * {@see WithdrawContentFromLiveRounds} annule et remplace la manche en cours
 * de ce film (`movie_suspended`, spec 60 § 15.4).
 */
final class SuspendMovie
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
        private readonly AnswerKeyProjector $answerKeys,
    ) {}

    /**
     * Rend le nombre d'images suspendues par la cascade.
     *
     * @throws AuthorizationException le film est déjà suspendu ou retiré, ou le compte n'est pas administrateur
     * @throws Throwable
     */
    public function handle(Movie $movie, User $admin, ?string $reason, ?TakedownRequest $takedown = null): int
    {
        $suspended = DB::transaction(function () use ($movie, $admin, $reason, $takedown): int {
            $locked = Movie::query()->whereKey($movie->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($admin)->authorize('suspend', $locked);

            $now = Date::now()->toImmutable();

            /** @var list<int> $frameIds */
            $frameIds = array_values(array_map(
                static fn (mixed $id): int => (int) $id,
                Frame::query()
                    ->where('movie_id', $locked->id)
                    ->where('availability', ContentAvailability::Published->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all(),
            ));

            if ($frameIds !== []) {
                Frame::query()->whereKey($frameIds)->update([
                    'availability' => ContentAvailability::Suspended->value,
                    'availability_changed_at' => $now,
                ]);

                SeenFrame::query()->whereIn('frame_id', $frameIds)->delete();
            }

            $locked->forceFill([
                'availability' => ContentAvailability::Suspended,
                'availability_changed_at' => $now,
                'availability_reason' => $reason,
            ])->save();

            $this->journal->record($admin, AdminActionType::MovieSuspended, $locked->id, $reason, $takedown);

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, § 3.5).
            $this->projector->recompute($locked);
            $this->answerKeys->recomputeAmbiguity(PublishMovie::formsOf($locked));

            // Après commit seulement (`ShouldQueueAfterCommit`) : une
            // suspension annulée n'annule aucune manche.
            WithdrawContentFromLiveRounds::dispatch($locked->id, null, RoundIncidentReason::MovieSuspended);

            return count($frameIds);
        });

        $movie->refresh();

        return $suspended;
    }
}
