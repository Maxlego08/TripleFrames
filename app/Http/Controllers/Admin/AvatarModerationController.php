<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RemoveAvatar;
use App\Actions\Admin\UnhideAvatar;
use App\Actions\Room\ReportSeatAvatar;
use App\Enums\ReportTarget;
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
 * 40 § 11.6 et § 11.7 (D49 du 01/10). Administrateur seul.
 *
 * Aucune adresse e-mail : la fiche du compte, consignée comme lecture
 * sensible, en est le seul accès. Une ligne par compte qui porte une image
 * téléversée ou un masquage, plus
 * récents en tête ; filtres « masqués » et « signalés » (au moins un
 * signalement dans la fenêtre courante). Deux gestes : lever, retirer. L'image
 * passe par `admin.avatars.image`, qui la sert même masquée, sous `no-store`.
 */
class AvatarModerationController extends Controller
{
    public function index(AvatarModerationRequest $request): Response
    {
        $filter = $request->filter();

        $users = User::query()
            ->select(['id', 'name', 'avatar_upload_path', 'avatar_upload_hidden_at', 'avatar_upload_reports_from', 'anonymized_at', 'updated_at'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('avatar_upload_path')->orWhereNotNull('avatar_upload_hidden_at');
            })
            ->when($filter === 'hidden', fn (Builder $query) => $query->whereNotNull('avatar_upload_hidden_at'))
            ->when($filter === 'reported', fn (Builder $query) => $query->whereExists(self::reportsInWindow(...)))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(AvatarModerationRequest::PER_PAGE)
            ->withQueryString();

        return Inertia::render('admin/avatars/index', [
            'avatars' => AdminCatalogPresenter::paginated($users, fn (User $user): array => self::row($user)),
            'filters' => ['filter' => $filter],
            'options' => ['filter' => AvatarModerationRequest::FILTERS],
            'counts' => [
                'uploaded' => User::query()->whereNotNull('avatar_upload_path')->count(),
                'hidden' => User::query()->whereNotNull('avatar_upload_hidden_at')->count(),
            ],
        ]);
    }

    /**
     * L'image d'un compte, MÊME masquée, pour l'administrateur seul : jamais
     * cacheable, jamais indexée.
     */
    public function image(User $user): HttpResponse
    {
        if ($user->avatar_upload_path === null) {
            throw new NotFoundHttpException;
        }

        return AvatarFileController::respond($user->avatar_upload_path, 'no-store, private');
    }

    public function unhide(AvatarUnhideRequest $request, User $user, UnhideAvatar $unhide): RedirectResponse
    {
        $unhide->handle(self::actor($request), $user, $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.avatars.unhidden')]);

        return back(fallback: route('admin.avatars.index'));
    }

    public function remove(AvatarRemoveRequest $request, User $user, RemoveAvatar $remove): RedirectResponse
    {
        $remove->handle(self::actor($request), $user, $request->reason());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.avatars.removed')]);

        return back(fallback: route('admin.avatars.index'));
    }

    /**
     * Une ligne de l'écran : l'état, l'image, la fenêtre de signalements.
     *
     * @return array{id: int, name: string, state: string, image_url: string|null, reports: int, last_reported_at: string|null}
     */
    private static function row(User $user): array
    {
        $lastReport = Report::query()
            ->where('target_type', ReportTarget::UploadedAvatar->value)
            ->where('target_user_id', $user->id)
            ->max('created_at');

        $state = match (true) {
            $user->avatar_upload_path === null => 'removed',
            $user->avatar_upload_hidden_at !== null => 'hidden',
            default => 'visible',
        };

        return [
            'id' => $user->id,
            'name' => $user->name,
            'state' => $state,
            'image_url' => $user->avatar_upload_path === null ? null : route('admin.avatars.image', ['user' => $user->id], absolute: false),
            'reports' => ReportSeatAvatar::reportersSince($user),
            'last_reported_at' => is_string($lastReport) ? Date::parse($lastReport)->toIso8601String() : null,
        ];
    }

    /** Au moins un signalement de l'image dans sa fenêtre courante. */
    private static function reportsInWindow(QueryBuilder $query): void
    {
        $query->select(DB::raw(1))
            ->from('report')
            ->whereColumn('report.target_user_id', 'users.id')
            ->where('report.target_type', ReportTarget::UploadedAvatar->value)
            ->where(function (QueryBuilder $window): void {
                $window->whereNull('users.avatar_upload_reports_from')
                    ->orWhereColumn('report.created_at', '>=', 'users.avatar_upload_reports_from');
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
