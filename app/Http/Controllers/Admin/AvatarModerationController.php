<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RemoveAvatar;
use App\Actions\Admin\UnhideAvatar;
use App\Actions\Room\ReportSeatAvatar;
use App\Avatars\AccountImage;
use App\Http\Controllers\Avatar\AvatarFileController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AvatarModerationRequest;
use App\Http\Requests\Admin\AvatarRemoveRequest;
use App\Http\Requests\Admin\AvatarUnhideRequest;
use App\Models\Report;
use App\Models\User;
use App\Support\Admin\AdminCatalogPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * L'écran « Avatars » — ligne 45 de la matrice, spec 20 § 12.5, règle : spec
 * 40 § 11.6, § 11.7 et § 12.6 (D49 et D51 du 01/10). Administrateur seul.
 *
 * Deux natures d'image personnelle, choisies par le paramètre `image`
 * (`upload` par défaut, `provider`) : une ligne par compte qui porte cette
 * image ou son masquage, plus récents en tête ; filtres « masqués » et
 * « signalés » (au moins un signalement dans la fenêtre courante). Deux
 * gestes : lever, retirer. L'image passe par `admin.avatars.image`, qui la
 * sert même masquée, sous `no-store`. Aucune adresse e-mail : la fiche du
 * compte, consignée comme lecture sensible, en est le seul accès.
 */
class AvatarModerationController extends Controller
{
    public function index(AvatarModerationRequest $request): Response
    {
        $filter = $request->filter();
        $image = $request->accountImage();

        $users = User::query()
            ->select([...AccountImage::columns(), 'name', 'updated_at'])
            ->where(function (Builder $query) use ($image): void {
                $query->whereNotNull($image->pathColumn())->orWhereNotNull($image->hiddenColumn());
            })
            ->when($filter === 'hidden', fn (Builder $query) => $query->whereNotNull($image->hiddenColumn()))
            ->when($filter === 'reported', fn (Builder $query) => $query->whereExists(
                fn (QueryBuilder $exists) => self::reportsInWindow($exists, $image),
            ))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(AvatarModerationRequest::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/avatars/index', [
            'avatars' => AdminCatalogPresenter::paginated($users, fn (User $user): array => self::row($user, $image)),
            'filters' => ['filter' => $filter, 'image' => $image->value],
            'options' => [
                'filter' => AvatarModerationRequest::FILTERS,
                'image' => array_map(static fn (AccountImage $case): string => $case->value, AccountImage::cases()),
            ],
            'counts' => [
                'uploaded' => User::query()->whereNotNull($image->pathColumn())->count(),
                'hidden' => User::query()->whereNotNull($image->hiddenColumn())->count(),
            ],
        ]);
    }

    /**
     * L'image d'un compte, MÊME masquée, pour l'administrateur seul : jamais
     * cacheable, jamais indexée.
     */
    public function image(AvatarModerationRequest $request, User $user): HttpResponse
    {
        $path = $request->accountImage()->path($user);

        if ($path === null) {
            throw new NotFoundHttpException;
        }

        return AvatarFileController::respond($path, 'no-store, private');
    }

    public function unhide(AvatarUnhideRequest $request, User $user, UnhideAvatar $unhide): RedirectResponse
    {
        $unhide->handle(self::actor($request), $user, $request->reason(), $request->accountImage());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.avatars.unhidden')]);

        return back(fallback: route('admin.avatars.index'));
    }

    public function remove(AvatarRemoveRequest $request, User $user, RemoveAvatar $remove): RedirectResponse
    {
        $remove->handle(self::actor($request), $user, $request->reason(), $request->accountImage());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.avatars.removed')]);

        return back(fallback: route('admin.avatars.index'));
    }

    /**
     * Une ligne de l'écran : l'état, l'image, la fenêtre de signalements.
     *
     * @return array{id: int, name: string, state: string, image_url: string|null, reports: int, last_reported_at: string|null}
     */
    private static function row(User $user, AccountImage $image): array
    {
        $lastReport = Report::query()
            ->where('target_type', $image->reportTarget()->value)
            ->where('target_user_id', $user->id)
            ->max('created_at');

        $state = match (true) {
            $image->path($user) === null => 'removed',
            $image->hiddenAt($user) !== null => 'hidden',
            default => 'visible',
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'state' => $state,
            'image_url' => $image->path($user) === null
                ? null
                : route('admin.avatars.image', ['user' => $user->id, 'image' => $image->value], absolute: false),
            'reports' => ReportSeatAvatar::reportersSince($user, $image),
            'last_reported_at' => is_string($lastReport) ? Date::parse($lastReport)->toIso8601String() : null,
        ];
    }

    /** Au moins un signalement de l'image dans sa fenêtre courante. */
    private static function reportsInWindow(QueryBuilder $query, AccountImage $image): void
    {
        $from = 'users.'.$image->reportsFromColumn();

        $query->select(DB::raw(1))
            ->from('report')
            ->whereColumn('report.target_user_id', 'users.id')
            ->where('report.target_type', $image->reportTarget()->value)
            ->where(function (QueryBuilder $window) use ($from): void {
                $window->whereNull($from)->orWhereColumn('report.created_at', '>=', $from);
            });
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('L’écran « Avatars » exige le middleware auth.');
        }

        return $user;
    }
}
