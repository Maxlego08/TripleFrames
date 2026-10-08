<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\Catalog\MovieProjector;
use App\Support\Curation\SuspensionHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Lever la suspension d'une image — spec 20 § 11.2, ligne 30 de la matrice
 * (`can:unsuspend,frame`, administrateur seul, refusé tant que son film est
 * lui-même suspendu).
 *
 * Même règle de restauration que la cascade de {@see UnsuspendMovie}
 * ({@see SuspensionHistory::restoredFrameState()}, spec 10 § 4.2) :
 * `published` sans nouvelle revue si sa dernière revue passante porte sur
 * ses octets et la version de grille courants, sinon `unpublished`, ou
 * `draft` si elle n'a jamais été publiée. Une transaction : état, ligne
 * `frame.unsuspended`, projection du film recalculée — l'image rendue
 * rentre au comptant de sa couverture.
 */
final class UnsuspendFrame
{
    public function __construct(
        private readonly AdminJournal $journal,
        private readonly MovieProjector $projector,
    ) {}

    /**
     * Rend l'état où revient l'image.
     *
     * @throws AuthorizationException l'image n'est plus suspendue, son film l'est, ou le compte n'est pas administrateur
     * @throws Throwable
     */
    public function handle(Frame $frame, User $admin, ?string $reason): ContentAvailability
    {
        $state = DB::transaction(function () use ($frame, $admin, $reason): ContentAvailability {
            $movie = Movie::query()->whereKey($frame->movie_id)->lockForUpdate()->firstOrFail();
            $locked = Frame::query()->whereKey($frame->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('movie', $movie);

            Gate::forUser($admin)->authorize('unsuspend', $locked);

            $state = SuspensionHistory::restoredFrameState($locked);

            $locked->forceFill([
                'availability' => $state,
                'availability_changed_at' => Date::now()->toImmutable(),
            ])->save();

            $this->journal->record($admin, AdminActionType::FrameUnsuspended, $locked->id, $reason);

            // Synchrone, dans la transaction du geste (spec 10 § 3.2, règle 2).
            $this->projector->recompute($movie);

            return $state;
        });

        $frame->refresh();

        return $state;
    }
}
