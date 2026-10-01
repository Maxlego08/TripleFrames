<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Curation\CreateTheme;
use App\Actions\Curation\UpdateTheme;
use App\Enums\ThemeKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ThemeIndexRequest;
use App\Http\Requests\Admin\ThemeStoreRequest;
use App\Http\Requests\Admin\ThemeUpdateRequest;
use App\Models\MovieTheme;
use App\Models\Theme;
use App\Models\User;
use App\Settings\RoomSettingsBounds;
use App\Support\Admin\AdminThemePresenter;
use App\Support\Admin\ThemeRuleOptions;
use App\Support\Draw\PoolReporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * L'écran des thèmes du back-office — spec 20 § 9.6, ligne 28 de la matrice
 * (`ThemePolicy`, curator+ ; J1 depuis D43 du 01/10).
 *
 * Chaque thème, publié ou non, avec sa règle résolue en noms, son nombre
 * d'**œuvres** (`PoolReporter::themeWorks()`, le thème seul au `N` par
 * défaut) et son nombre de films actifs. Une mesure `themeWorks()` par thème
 * est admise (quelques dizaines de thèmes, écran admin) ; le reste de l'écran
 * se lit en un nombre fixe de requêtes.
 *
 * L'écran est une lecture ordinaire du catalogue : aucune ligne au journal.
 * Ses trois gestes — créer, corriger, publier — écrivent la leur dans leur
 * transaction.
 */
class ThemeController extends Controller
{
    public function index(ThemeIndexRequest $request, PoolReporter $pool): Response
    {
        /** @var User $user */
        $user = $request->user();

        $themes = Theme::query()
            ->with('labels')
            ->orderBy('sort_order')
            ->orderBy('key')
            ->get();

        $activeFilms = MovieTheme::query()
            ->toBase()
            ->where('is_active', true)
            ->groupBy('theme_id')
            ->selectRaw('theme_id, COUNT(*) AS films')
            ->pluck('films', 'theme_id')
            ->all();

        $names = AdminThemePresenter::names($themes);
        $presented = [];

        foreach ($themes as $theme) {
            $films = $activeFilms[$theme->id] ?? 0;

            $presented[] = AdminThemePresenter::theme(
                $theme,
                $pool->themeWorks($theme->id),
                is_numeric($films) ? (int) $films : 0,
                $names,
                [
                    'update' => Gate::forUser($user)->allows('update', $theme),
                    'publish' => Gate::forUser($user)->allows('publish', $theme),
                ],
            );
        }

        $ruleKind = $request->ruleKind();
        $prefill = $request->prefillCollection();

        return Inertia::render('admin/themes/index', [
            'themes' => $presented,
            'kinds' => array_values(array_map(
                static fn (ThemeKind $kind): string => $kind->value,
                array_filter(ThemeKind::cases(), static fn (ThemeKind $kind): bool => $kind->isCreatable()),
            )),
            'rule_options' => Inertia::optional(static fn (): ?array => $ruleKind === null ? null : [
                'kind' => $ruleKind->value,
                'options' => ThemeRuleOptions::for($ruleKind),
            ]),
            'prefill' => $prefill === null ? null : [
                'kind' => ThemeKind::Saga->value,
                'collection_id' => $prefill->id,
                'collection_name' => $prefill->name,
            ],
            'publication' => [
                'min_works' => RoomSettingsBounds::DEFAULT_ROUNDS_COUNT,
                'frames_per_round' => RoomSettingsBounds::DEFAULT_FRAMES_PER_ROUND,
            ],
            'abilities' => [
                'create' => Gate::forUser($user)->allows('create', Theme::class),
            ],
        ]);
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function store(ThemeStoreRequest $request, CreateTheme $create): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $theme = $create->handle(
            $curator,
            $request->kind(),
            $request->ruleValue(),
            $request->negated(),
            $request->labels(),
            $request->sortOrder(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.themes.flash.created', ['key' => $theme->key]),
        ]);

        return to_route('admin.themes.index');
    }

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function update(ThemeUpdateRequest $request, Theme $theme, UpdateTheme $update): RedirectResponse
    {
        /** @var User $curator */
        $curator = $request->user();

        $outcome = $update->handle($curator, $theme, $request->changes());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($outcome) {
                UpdateTheme::UPDATED => __('admin.themes.flash.updated', ['key' => $theme->key]),
                default => __('admin.themes.flash.unchanged'),
            },
        ]);

        return to_route('admin.themes.index');
    }
}
