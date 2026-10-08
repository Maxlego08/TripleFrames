<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\ApplyRetroactiveGrid;
use App\Enums\FrameLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExclusionGridRetroactiveRequest;
use App\Models\Movie;
use App\Models\User;
use App\Support\Curation\RetroactiveGrid;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Le geste rétroactif de grille (spec 20 § 7.7, ligne 33, L20-25),
 * administrateur seul (`can:applyRetroactiveGrid,App\Models\Frame`).
 *
 * - **`show`** : l'écran de confirmation — la version courante, les niveaux
 *   visés, et, par film, le nombre d'images que le geste dépublierait ; les
 *   films publiés qu'il rendrait incomplets sont nommés **avant**
 *   confirmation, chacun avec son `N` jouable maximal après le geste. Sous une
 *   version non rétroactive, l'écran le dit (`unavailable`) et n'offre rien.
 * - **`store`** : le geste, par {@see ApplyRetroactiveGrid} — une
 *   transaction par film, une ligne `frame.grid_unpublished` par image.
 */
class ExclusionGridRetroactiveController extends Controller
{
    public function show(RetroactiveGrid $grid): Response
    {
        $preview = $grid->preview();

        $movies = $preview === []
            ? collect()
            : Movie::query()
                ->whereKey(array_column($preview, 'movie_id'))
                ->get(['id', 'title_original', 'release_year', 'availability'])
                ->keyBy('id');

        $rows = [];

        foreach ($preview as $row) {
            $movie = $movies->get($row['movie_id']);

            if (! $movie instanceof Movie) {
                continue;
            }

            $rows[] = [
                'id' => $movie->id,
                'title_original' => $movie->title_original,
                'release_year' => $movie->release_year,
                'availability' => $movie->availability->value,
                'frames' => $row['frames'],
                'becomes_incomplete' => $row['becomes_incomplete'],
                'playable_up_to' => $row['playable_up_to'],
            ];
        }

        return Inertia::render('admin/exclusion-grid/retroactive', [
            'available' => $grid->isAvailable(),
            'version' => $grid->version,
            'levels' => array_map(static fn (FrameLevel $level): int => $level->value, $grid->levels),
            'movies' => $rows,
            'frames_total' => array_sum(array_column($rows, 'frames')),
            'reason_max_length' => ExclusionGridRetroactiveRequest::REASON_MAX_LENGTH,
            'reference_length' => ExclusionGridRetroactiveRequest::REFERENCE_LENGTH,
        ]);
    }

    /**
     * @throws Throwable
     */
    public function store(ExclusionGridRetroactiveRequest $request, RetroactiveGrid $grid, ApplyRetroactiveGrid $apply): RedirectResponse
    {
        if (! $grid->isAvailable()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('admin.exclusion_grid.retroactive.unavailable')]);

            return to_route('admin.exclusion_grid.retroactive.show');
        }

        /** @var User $admin */
        $admin = $request->user();

        $count = $apply->handle($admin, $request->reason(), $request->takedownRequest());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans_choice('admin.exclusion_grid.retroactive.done', $count, ['count' => $count]),
        ]);

        return to_route('admin.exclusion_grid.retroactive.show');
    }
}
