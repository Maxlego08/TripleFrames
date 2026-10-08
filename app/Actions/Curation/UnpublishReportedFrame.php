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
 * Dépublier l'image d'un signalement depuis la file (D63 du 07/10, spec 20
 * § 11.6) : le geste même de la banque d'images ({@see UnpublishFrame},
 * motif facultatif, `frame.unpublished` au journal, projection recalculée),
 * puis la clôture des signalements ouverts de cette image en `resolved` /
 * `frame_unpublished`, dans la même transaction. Un signalement du film
 * entier n'a pas d'image : 404.
 */
final class UnpublishReportedFrame
{
    public function __construct(private readonly UnpublishFrame $unpublish) {}

    /**
     * Le nombre de signalements clos.
     *
     * @throws AuthorizationException|NotFoundHttpException|Throwable
     */
    public function handle(ContentReport $report, User $curator, ?string $reason): int
    {
        $frame = $report->frame;

        if (! $frame instanceof Frame) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($report, $frame, $curator, $reason): int {
            $this->unpublish->handle($frame, $curator, $reason);

            return count(ContentReportClosure::close(
                static fn (Builder $open): Builder => $open->where('target_key', $report->target_key),
                ContentReportResolution::FrameUnpublished,
                $curator,
            ));
        });
    }
}
