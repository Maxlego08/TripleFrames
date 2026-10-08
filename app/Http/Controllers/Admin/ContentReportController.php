<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\DismissContentReports;
use App\Actions\Curation\SuspendReportedFrame;
use App\Actions\Curation\SuspendReportedMovie;
use App\Actions\Curation\UnpublishReportedFrame;
use App\Actions\Curation\UnpublishReportedMovie;
use App\Enums\ContentAvailability;
use App\Enums\ContentReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentReportDismissRequest;
use App\Http\Requests\Admin\ContentReportIndexRequest;
use App\Http\Requests\Admin\FrameUnpublishRequest;
use App\Http\Requests\Admin\MovieUnpublishRequest;
use App\Http\Requests\Admin\SuspensionRequest;
use App\Models\ContentReport;
use App\Models\Frame;
use App\Models\Movie;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\CoverageLossPreview;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * La file des signalements de contenu par les joueurs (D63 du 07/10, spec 20
 * § 11.6) : curateur et au-delà, groupée par CIBLE — l'image, sinon le film —,
 * les cibles qui ont reçu un signalement le plus récemment d'abord.
 *
 * Trois gestes, chacun clôt les signalements ouverts de sa cible dans la
 * transaction du geste : dépublier le film (motif obligatoire), dépublier
 * l'image (motif facultatif, avertissement de perte de couverture), ignorer.
 * Les deux dépublications sont les gestes mêmes du catalogue et de la banque
 * d'images, journalisés par eux ; ignorer écrit `content_report.dismissed`.
 * **Au J2** (D66 du 07/10, n° 34), deux gestes de plus, **administrateur
 * seul** : suspendre le film ou l'image (`SuspendMovie`, `SuspendFrame`,
 * § 11.2), dont les policies sont repassées sous verrou — un curateur, que
 * `resolve` laisse passer la route, reçoit 403 de l'action.
 *
 * Les routes adressent un groupe par l'un de ses signalements, le plus
 * ancien encore ouvert : un geste vaut pour toute la cible.
 */
final class ContentReportController extends Controller
{
    public const int PER_PAGE = 50;

    /** Commentaires les plus récents montrés par cible. */
    public const int LATEST_COMMENTS = 5;

    /** Filtres de la file : les cibles ouvertes (défaut) ou l'historique des cibles closes. */
    public const array FILTERS = ['open', 'closed'];

    public function index(ContentReportIndexRequest $request, CoverageLossPreview $coverageLoss): Response
    {
        $filter = $request->filter();
        $statuses = $filter === 'open'
            ? [ContentReportStatus::Open->value]
            : [ContentReportStatus::Resolved->value, ContentReportStatus::Dismissed->value];

        $groups = ContentReport::query()
            ->whereIn('status', $statuses)
            ->select([
                'target_key',
                'movie_id',
                'frame_id',
                DB::raw('count(*) as reports_count'),
                DB::raw('min(id) as first_id'),
                DB::raw('max(id) as last_id'),
                DB::raw('min(created_at) as first_reported_at'),
                DB::raw('max(created_at) as last_reported_at'),
            ])
            ->groupBy('target_key', 'movie_id', 'frame_id')
            ->orderByDesc('last_reported_at')
            ->orderByDesc('last_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, ContentReport> $rows */
        $rows = collect($groups->items());
        $targetKeys = $rows->pluck('target_key')->all();

        $reports = ContentReport::query()
            ->whereIn('status', $statuses)
            ->whereIn('target_key', $targetKeys)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'target_key', 'reason', 'comment', 'status', 'resolution', 'resolved_at', 'created_at'])
            ->groupBy('target_key');

        $movies = Movie::query()
            ->whereIn('id', $rows->pluck('movie_id')->unique()->all())
            ->get(['id', 'title_original', 'release_year', 'availability'])
            ->keyBy('id');

        $frames = Frame::query()
            ->whereIn('id', $rows->pluck('frame_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        $representatives = ContentReport::query()
            ->whereIn('id', $rows->pluck('first_id')->all())
            ->get()
            ->keyBy('id');

        return Inertia::render('admin/content-reports/index', [
            'filter' => $filter,
            'filters' => self::FILTERS,
            'counts' => [
                'open_targets' => ContentReport::query()
                    ->where('status', ContentReportStatus::Open->value)
                    ->distinct()
                    ->count('target_key'),
                'open_reports' => ContentReport::query()
                    ->where('status', ContentReportStatus::Open->value)
                    ->count(),
            ],
            'groups' => AdminCatalogPresenter::paginated(
                $groups,
                function (ContentReport $group) use ($reports, $movies, $frames, $representatives, $coverageLoss, $filter): array {
                    /** @var Movie $movie */
                    $movie = $movies->get($group->movie_id);
                    $frame = $group->frame_id === null ? null : $frames->get($group->frame_id);
                    $frame?->setRelation('movie', $movie);
                    /** @var Collection<int, ContentReport> $targetReports */
                    $targetReports = $reports->get($group->target_key, collect());
                    $representative = $representatives->get($group->getAttribute('first_id'));
                    $open = $filter === 'open';

                    return [
                        'report_id' => (int) $group->getAttribute('first_id'),
                        'scope' => $frame === null ? 'movie' : 'frame',
                        'reports_count' => (int) $group->getAttribute('reports_count'),
                        'first_reported_at' => self::moment($group->getAttribute('first_reported_at')),
                        'last_reported_at' => self::moment($group->getAttribute('last_reported_at')),
                        'reasons' => $targetReports
                            ->countBy(static fn (ContentReport $report): string => $report->reason->value)
                            ->sortDesc()
                            ->all(),
                        'comments' => $targetReports
                            ->filter(static fn (ContentReport $report): bool => $report->comment !== null)
                            ->take(self::LATEST_COMMENTS)
                            ->map(static fn (ContentReport $report): array => [
                                'reason' => $report->reason->value,
                                'comment' => $report->comment,
                                'reported_at' => $report->created_at?->toIso8601String(),
                            ])
                            ->values()
                            ->all(),
                        'resolution' => $open ? null : $targetReports->first()?->resolution?->value,
                        'resolved_at' => $open ? null : $targetReports->first()?->resolved_at?->toIso8601String(),
                        'movie' => [
                            'id' => $movie->id,
                            'title_original' => $movie->title_original,
                            'release_year' => $movie->release_year,
                            'availability' => $movie->availability->value,
                        ],
                        'frame' => $frame instanceof Frame ? [
                            'id' => $frame->id,
                            'frame_level' => $frame->frame_level->value,
                            'availability' => $frame->availability->value,
                            'thumbnail_url' => $frame->game_path !== null && $frame->availability !== ContentAvailability::Withdrawn
                                ? route('admin.catalog.frames.game', ['movie' => $movie->id, 'frame' => $frame->id])
                                : null,
                            'coverage_warning' => $open ? $coverageLoss->forFrame($frame) : null,
                        ] : null,
                        'abilities' => [
                            'unpublish_movie' => $open && Gate::allows('unpublish', $movie),
                            'unpublish_frame' => $open && $frame instanceof Frame && Gate::allows('unpublish', $frame),
                            'dismiss' => $open && $representative instanceof ContentReport && Gate::allows('resolve', $representative),
                            // Administrateur seul (J2, § 11.6) : masqué au curateur.
                            'suspend_movie' => $open && Gate::allows('suspend', $movie),
                            'suspend_frame' => $open && $frame instanceof Frame && Gate::allows('suspend', $frame),
                        ],
                    ];
                },
            ),
        ]);
    }

    /** @throws Throwable */
    public function unpublishMovie(MovieUnpublishRequest $request, ContentReport $contentReport, UnpublishReportedMovie $unpublish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $closed = $unpublish->handle($contentReport, $curator, $request->reason());

        return self::done('admin.content_report.flash.movie_unpublished', $closed);
    }

    /** @throws Throwable */
    public function unpublishFrame(FrameUnpublishRequest $request, ContentReport $contentReport, UnpublishReportedFrame $unpublish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $closed = $unpublish->handle($contentReport, $curator, $request->reason());

        return self::done('admin.content_report.flash.frame_unpublished', $closed);
    }

    /** @throws Throwable */
    public function suspendMovie(SuspensionRequest $request, ContentReport $contentReport, SuspendReportedMovie $suspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $closed = $suspend->handle($contentReport, $admin, $request->reason());

        return self::done('admin.content_report.flash.movie_suspended', $closed);
    }

    /** @throws Throwable */
    public function suspendFrame(SuspensionRequest $request, ContentReport $contentReport, SuspendReportedFrame $suspend): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $closed = $suspend->handle($contentReport, $admin, $request->reason());

        return self::done('admin.content_report.flash.frame_suspended', $closed);
    }

    /** @throws Throwable */
    public function dismiss(ContentReportDismissRequest $request, ContentReport $contentReport, DismissContentReports $dismiss): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $closed = $dismiss->handle($contentReport, $curator, $request->reason());

        return self::done('admin.content_report.flash.dismissed', $closed);
    }

    private static function done(string $messageKey, int $closed): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice($messageKey, $closed, ['count' => $closed]),
        ]);

        return to_route('admin.content-reports.index');
    }

    /** Un agrégat d'horodatage SQL, en ISO-8601. */
    private static function moment(mixed $value): ?string
    {
        return is_string($value) ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }
}
