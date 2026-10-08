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
 * Suspendre le film d'un signalement depuis la file (spec 20 § 11.6, D66 du
 * 07/10, n° 34 ; reste de L20-39) : le geste même de la fiche film
 * ({@see SuspendMovie}, **administrateur seul** — `MoviePolicy::suspend`
 * repassée sous verrou —, motif facultatif, `movie.suspended` au journal,
 * annulation active après commit), puis la clôture de **tous** les
 * signalements ouverts du film — portées film et image — en `resolved` /
 * `movie_suspended`, dans la même transaction. Laissés ouverts, ils
 * n'auraient plus que « Ignorer » pour sortir, la dépublication étant
 * refusée sur une cible suspendue.
 */
final class SuspendReportedMovie
{
    public function __construct(private readonly SuspendMovie $suspend) {}

    /**
     * Le nombre de signalements clos.
     *
     * @throws AuthorizationException|Throwable
     */
    public function handle(ContentReport $report, User $admin, ?string $reason): int
    {
        return DB::transaction(function () use ($report, $admin, $reason): int {
            $this->suspend->handle($report->movie, $admin, $reason);

            return count(ContentReportClosure::close(
                static fn (Builder $open): Builder => $open->where('movie_id', $report->movie_id),
                ContentReportResolution::MovieSuspended,
                $admin,
            ));
        });
    }
}
