<?php

namespace App\Actions\Curation;

use App\Enums\ContentReportResolution;
use App\Models\ContentReport;
use App\Models\User;
use App\Support\ContentReport\ContentReportClosure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Dépublier le film d'un signalement depuis la file (D63 du 07/10, spec 20
 * § 11.6) : le geste même de la fiche film ({@see UnpublishMovie}, motif
 * obligatoire, `movie.unpublished` au journal, policy repassée sous verrou),
 * puis la clôture de **tous** les signalements ouverts du film — portées film
 * et image — en `resolved` / `movie_unpublished`, dans la même transaction.
 */
final class UnpublishReportedMovie
{
    public function __construct(private readonly UnpublishMovie $unpublish) {}

    /**
     * Le nombre de signalements clos.
     *
     * @throws AuthorizationException|Throwable
     */
    public function handle(ContentReport $report, User $curator, string $reason): int
    {
        return DB::transaction(function () use ($report, $curator, $reason): int {
            $this->unpublish->handle($report->movie, $curator, $reason);

            return count(ContentReportClosure::close(
                static fn (Builder $open): Builder => $open->where('movie_id', $report->movie_id),
                ContentReportResolution::MovieUnpublished,
                $curator,
            ));
        });
    }
}
