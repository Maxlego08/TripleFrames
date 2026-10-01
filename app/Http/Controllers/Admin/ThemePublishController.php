<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\PublishTheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemePublishRequest;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/**
 * Publier ou dépublier un thème — spec 20 § 9.6, ligne 28 de la matrice
 * (`can:publish,theme`, `throttle:admin-curation`). Un refus — libellé
 * manquant, thème sous le seuil d'œuvres — revient en erreur traduite sous
 * `is_published`, jamais en 403 ; dépublier est toujours permis.
 */
class ThemePublishController extends Controller
{
    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(ThemePublishRequest $request, Theme $theme, PublishTheme $publish): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $outcome = $publish->handle($curator, $theme, $request->published());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($outcome) {
                PublishTheme::PUBLISHED => __('admin.themes.flash.published', ['key' => $theme->key]),
                PublishTheme::UNPUBLISHED => __('admin.themes.flash.unpublished', ['key' => $theme->key]),
                default => __('admin.themes.flash.unchanged'),
            },
        ]);

        return to_route('admin.themes.index');
    }
}
