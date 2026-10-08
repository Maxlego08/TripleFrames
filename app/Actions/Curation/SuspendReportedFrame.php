<?php

namespace App\Actions\Curation;

use App\Enums\ContentReportResolution;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\User;
use App\Support\ContentReport\ContentReportClosure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Suspendre l'image d'un signalement depuis la file (spec 20 § 11.6, D66 du
 * 07/10, n° 34 ; reste de L20-39) : le geste même de la banque d'images
 * ({@see SuspendFrame}, **administrateur seul** — `FramePolicy::suspend`
 * repassée sous verrou —, motif facultatif, `frame.suspended` au journal),
 * puis la clôture des signalements ouverts de cette image en `resolved` /
 * `frame_suspended`, dans la même transaction. Un signalement du film entier
 * n'a pas d'image : 404.
 */
final class SuspendReportedFrame
{
    public function __construct(private readonly SuspendFrame $suspend) {}

    /**
     * Le nombre de signalements clos.
     *
     * @throws AuthorizationException|NotFoundHttpException|Throwable
     */
    public function handle(ContentReport $report, User $admin, ?string $reason): int
    {
        $frame = $report->frame;

        if (! $frame instanceof Frame) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($report, $frame, $admin, $reason): int {
            $this->suspend->handle($frame, $admin, $reason);

            return count(ContentReportClosure::close(
                static fn (Builder $open): Builder => $open->where('target_key', $report->target_key),
                ContentReportResolution::FrameSuspended,
                $admin,
            ));
        });
    }
}
