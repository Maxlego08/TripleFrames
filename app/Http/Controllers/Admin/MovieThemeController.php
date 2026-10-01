<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\SetMovieThemeMembership;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MovieThemeUpdateRequest;
use App\Models\Movie;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Throwable;

/**
 * Ajouter un film à un ou plusieurs thèmes, l'en retirer, ou annuler
 * l'exception — spec 20
 * § 9.6, ligne 28 de la matrice (`can:curate,movie`,
 * `throttle:admin-curation` ; D43 du 01/10).
 *
 * Tout thème, publié ou non. Le geste est journalisé (`movie.theme_set`)
 * quand l'exception change ; toast selon l'issue.
 */
class MovieThemeController extends Controller
{
    /**
     * @throws Throwable
     */
    public function update(MovieThemeUpdateRequest $request, Movie $movie, SetMovieThemeMembership $membership): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        if ($request->isBatchAddition()) {
            $themes = Theme::query()
                ->whereKey($request->themeIds())
                ->orderBy('id')
                ->get();

            $changed = $membership->handleAdditions($curator, $movie, $themes);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => $changed === 0
                    ? __('admin.movie.themes.flash.unchanged')
                    : trans_choice('admin.movie.themes.flash.added_many', $changed, ['count' => $changed]),
            ]);

            return back();
        }

        $theme = Theme::query()->findOrFail($request->themeId());

        $outcome = $membership->handle($curator, $movie, $theme, $request->state());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($outcome) {
                SetMovieThemeMembership::ADDED => __('admin.movie.themes.flash.added'),
                SetMovieThemeMembership::REMOVED => __('admin.movie.themes.flash.removed'),
                SetMovieThemeMembership::CLEARED => __('admin.movie.themes.flash.cleared'),
                SetMovieThemeMembership::UNCHANGED => __('admin.movie.themes.flash.unchanged'),
            },
        ]);

        return back();
    }
}
