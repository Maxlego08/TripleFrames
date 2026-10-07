<?php

namespace App\Actions\Curation;

use App\Enums\AdminActionType;
use App\Enums\ContentAvailability;
use App\Enums\ContentReportResolution;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use App\Support\ContentReport\ContentReportClosure;
use App\ValueObjects\Admin\AdminActionDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Ignorer les signalements ouverts d'une cible (D63 du 07/10, spec 20
 * § 11.6) : la cible reste en jeu, rien d'autre ne change.
 *
 * Issue `dismissed` (état `dismissed`), ou `already_handled` (état
 * `resolved`) quand la cible est déjà hors jeu — film ou image qui n'est
 * plus `published`. Journalisé `content_report.dismissed` (sujet : le plus
 * ancien signalement clos, `details` : la cible, l'issue et le nombre clos),
 * motif facultatif, dans la transaction. Rien d'ouvert : aucune écriture,
 * aucune ligne de journal.
 */
final class DismissContentReports
{
    public function __construct(private readonly AdminJournal $journal) {}

    /**
     * Le nombre de signalements clos.
     *
     * @throws AuthorizationException|Throwable
     */
    public function handle(ContentReport $report, User $curator, ?string $reason): int
    {
        return DB::transaction(function () use ($report, $curator, $reason): int {
            $locked = ContentReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($curator)->authorize('resolve', $locked);

            $resolution = self::isOutOfPlay($locked)
                ? ContentReportResolution::AlreadyHandled
                : ContentReportResolution::Dismissed;

            $closed = ContentReportClosure::close(
                static fn (Builder $open): Builder => $open->where('target_key', $locked->target_key),
                $resolution,
                $curator,
            );

            if ($closed === []) {
                return 0;
            }

            $this->journal->record(
                $curator,
                AdminActionType::ContentReportDismissed,
                $closed[0],
                $reason,
                details: AdminActionDetails::contentReportsDismissed($locked->movie_id, $locked->frame_id, $resolution, count($closed)),
            );

            return count($closed);
        });
    }

    /** La cible n'est plus en jeu : film non publié, ou image non publiée. */
    private static function isOutOfPlay(ContentReport $report): bool
    {
        $movie = Movie::query()->findOrFail($report->movie_id);

        if ($movie->availability !== ContentAvailability::Published) {
            return true;
        }

        $frame = $report->frame_id === null ? null : Frame::query()->find($report->frame_id);

        return $frame instanceof Frame && $frame->availability !== ContentAvailability::Published;
    }
}
