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
use App\Support\Catalog\MovieProjector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Suspendre une image en un clic — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:suspend,frame`, administrateur seul, image publiée seulement).
 *
 * Même schéma que {@see SuspendMovie}, à l'échelle d'une image, dans une
 * transaction (film puis image verrouillés) : passage `suspended`,
 * `availability_changed_at`, `seen_frame` de l'image supprimées, ligne
 * `frame.suspended` (motif facultatif, demande liée s'il y en a une) —
 * c'est elle qui marque l'image comme suspendue **individuellement** : la
 * levée de son film ne la rendra pas —, projection du film recalculée. Un
 * film publié qui perd ainsi sa couverture 1-3-5 reste publié (E10-23).
 *
 * Après commit, {@see WithdrawContentFromLiveRounds} annule la manche dont
 * un palier déjà frappé sert cette image (`frame_unavailable`, spec 60
 * § 15.4) ; sinon la frappe suivante substitue.
 */
final class SuspendFrame
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * @throws AuthorizationException l'image n'est plus publiée, ou le compte n'est pas administrateur
     * @throws Throwable
     */
    public function handle(Frame $frame, User $admin, ?string $reason, ?TakedownRequest $takedown = null): void
    {
        DB::transaction(function () use ($frame, $admin, $reason, $takedown): void {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('movie', $movie);

            Gate::forUser($admin)->authorize('suspend', $locked);

            $locked->forceFill([
                'availability' => ContentAvailability::Suspended,
                'availability_changed_at' => Date::now()->toImmutable(),
            ])->save();

            SeenFrame::query()->where('frame_id', $locked->id)->delete();

            $this->journal->record($admin, AdminActionType::FrameSuspended, $locked->id, $reason, $takedown);

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2).
            $this->projector->recompute($movie);

            WithdrawContentFromLiveRounds::dispatch(null, $locked->id, RoundIncidentReason::FrameUnavailable);
        });

        $frame->refresh();
    }
}
