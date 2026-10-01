<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\DismissNearMiss;
use App\Actions\Curation\PromoteNearMiss;
use App\Enums\ContentAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NearMissPromoteRequest;
use App\Models\NearMiss;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Curation\NearMissAggregator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** File de formulations récurrentes proposées comme alias. */
final class NearMissController extends Controller
{
    public const int PER_PAGE = 50;

    public function index(): Response
    {
        $suggestions = NearMiss::query()
            ->whereNull('dismissed_at')
            ->whereHas('movie', static function (Builder $movie): void {
                $movie->where('availability', '!=', ContentAvailability::Withdrawn->value);
            })
            ->with('movie:id,title_original,release_year,availability')
            ->orderByDesc('distinct_rounds')
            ->orderByDesc('occurrences')
            ->orderBy('best_distance')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/near-misses/index', [
            'suggestions' => AdminCatalogPresenter::paginated(
                $suggestions,
                static fn (NearMiss $nearMiss): array => [
                    'id' => $nearMiss->id,
                    'normalized_text' => $nearMiss->normalized_text,
                    'occurrences' => $nearMiss->occurrences,
                    'distinct_rounds' => $nearMiss->distinct_rounds,
                    'best_distance' => $nearMiss->best_distance,
                    'first_seen_on' => $nearMiss->first_seen_on->toDateString(),
                    'last_seen_on' => $nearMiss->last_seen_on->toDateString(),
                    'movie' => [
                        'id' => $nearMiss->movie->id,
                        'title_original' => $nearMiss->movie->title_original,
                        'release_year' => $nearMiss->movie->release_year,
                    ],
                ],
            ),
        ]);
    }

    public function refresh(NearMissAggregator $aggregator): RedirectResponse
    {
        $count = $aggregator->refresh();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.near_misses.flash.refreshed', ['count' => $count]),
        ]);

        return to_route('admin.near_misses.index');
    }

    /** @throws Throwable */
    public function promote(
        NearMissPromoteRequest $request,
        NearMiss $nearMiss,
        PromoteNearMiss $promote,
    ): RedirectResponse {
        /** @var User $curator */
        $curator = $request->user();

        $promote->handle($nearMiss, $curator, $request->aliasLocale(), $request->aliasText());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.near_misses.flash.promoted'),
        ]);

        return to_route('admin.near_misses.index');
    }

    /** @throws Throwable */
    public function dismiss(Request $request, NearMiss $nearMiss, DismissNearMiss $dismiss): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $dismiss->handle($nearMiss, $curator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.near_misses.flash.dismissed'),
        ]);

        return to_route('admin.near_misses.index');
    }
}
