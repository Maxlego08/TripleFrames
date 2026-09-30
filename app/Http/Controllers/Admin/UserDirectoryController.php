<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminActionSubject;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserDirectoryRequest;
use App\Models\AdminAction;
use App\Models\FrameReview;
use App\Models\ImportRun;
use App\Models\LinkedAccount;
use App\Models\User;
use App\Support\Admin\AdminAccountPresenter;
use App\Support\Admin\AdminCatalogPresenter;
use App\Support\Admin\AdminJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'annuaire des comptes et la fiche d'un compte, **en lecture** — spec 20
 * § 2.8, ligne 40 de la matrice des capacités, administrateur seul.
 *
 * Tous les comptes y figurent, joueurs compris, pierres tombales signalées :
 * l'annuaire est l'endroit d'où l'on retrouve un compte avant d'agir. Les deux
 * gestes qu'il offre — changer le rôle, corriger le nom réel — ont leur route,
 * leur garde et leur limiteur chez {@see AccessController} ; la fiche n'en
 * envoie que les booléens `abilities`, qui masquent un bouton et n'autorisent
 * rien.
 *
 * **Ce que la fiche ne montre jamais** : les configurations sauvegardées,
 * strictement privées y compris d'un administrateur (`CLAUDE.md` § 5), ni
 * aucun secret — mot de passe, second facteur, jeton de session, chemin de la
 * copie locale d'une photo, adresse d'un compte de fournisseur. Les faits de
 * partie d'un compte appartiennent à son historique (`40`), pas à cet écran.
 *
 * La recherche libre est un `LIKE` sans index, comme celle du catalogue :
 * assumé à l'échelle d'une table de comptes.
 *
 * **Deux lectures sensibles** (D41 du 30/09) : chaque visite de l'annuaire
 * écrit `accounts.directory_viewed`, chaque visite d'une fiche `user.viewed`
 * — une ligne par visite qui compte ({@see AdminJournal::countsAsVisit()}),
 * jamais pour un rechargement partiel ni un préchargement. Ni la recherche
 * libre ni les filtres ne sont recopiés : une adresse cherchée deviendrait une
 * donnée personnelle permanente.
 */
class UserDirectoryController extends Controller
{
    /**
     * Les gestes que l'historique d'une fiche montre — ceux que liste le type
     * `AdminAccountActionType` côté écran. Les lectures sensibles visent aussi
     * un compte, mais ne sont pas des gestes : elles vivent au journal.
     *
     * @var list<AdminActionType>
     */
    public const array HISTORY_ACTIONS = [
        AdminActionType::RoleChanged,
        AdminActionType::UserRealNameChanged,
        AdminActionType::AvatarHidden,
        AdminActionType::AvatarUnhidden,
    ];

    /**
     * L'annuaire, entièrement piloté par la query string.
     */
    public function index(UserDirectoryRequest $request, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $users = $this->filtered($request)
            ->paginate(UserDirectoryRequest::PER_PAGE)
            ->withQueryString();

        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::AccountsDirectoryViewed, null);
        }

        return Inertia::render('admin/users/index', [
            'users' => AdminCatalogPresenter::paginated(
                $users,
                fn (User $user): array => AdminAccountPresenter::row($user),
            ),
            'filters' => $request->filters(),
            'options' => self::options(),
            'counts' => $this->counts(),
        ]);
    }

    /**
     * La fiche d'un compte : identité, sécurité, consentements, traces que
     * l'anonymisation conserve, et l'historique du journal qui le vise.
     *
     * Un nombre de requêtes constant, indépendant de la longueur de
     * l'historique.
     */
    public function show(Request $request, User $user, AdminJournal $journal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $history = AdminAction::query()
            ->where('subject_type', AdminActionSubject::User)
            ->where('subject_id', $user->id)
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->orderByDesc('id')
            ->get();

        $lines = [];

        foreach ($history as $line) {
            $lines[] = AdminAccountPresenter::historyLine($line);
        }

        // Après la lecture de l'historique : la visite en cours n'y entre pas.
        if (AdminJournal::countsAsVisit($request)) {
            $journal->recordRead($actor, AdminActionType::UserViewed, $user->id);
        }

        return Inertia::render('admin/users/show', [
            'account' => AdminAccountPresenter::detail($user),
            // Le NOM du fournisseur seulement : l'identifiant et l'adresse du
            // compte de fournisseur ne quittent jamais le serveur.
            'providers' => LinkedAccount::query()
                ->where('user_id', $user->id)
                ->orderBy('provider')
                ->get(['provider'])
                ->map(fn (LinkedAccount $account): string => $account->provider->value)
                ->values()
                ->all(),
            'passkeys_count' => $user->passkeys()->count(),
            // Les preuves que l'anonymisation conserve (spec 10 § 5.5) : un
            // compte qui en porte signe des pièces opposables.
            'traces' => [
                'frame_reviews' => FrameReview::query()->where('reviewer_id', $user->id)->count(),
                // Les gestes signés, jamais les consultations : une lecture
                // n'est pas une preuve opposable.
                'admin_actions' => AdminAction::query()
                    ->where('actor_id', $user->id)
                    ->whereNotIn('action', self::readActions())
                    ->count(),
                'import_runs' => ImportRun::query()->where('actor_id', $user->id)->count(),
            ],
            'history' => $lines,
            // Ne sert qu'à afficher un bouton : chaque geste garde sa policy
            // à l'écriture, et l'action rejoue ses refus sous verrou.
            'abilities' => [
                'updateRole' => Gate::allows('updateRole', $user),
                'updateRealName' => Gate::allows('updateRealName', $user),
            ],
            'is_self' => $user->is($actor),
        ]);
    }

    /**
     * Les cas de lecture sensible, exclus du compteur de preuves signées.
     *
     * @return list<AdminActionType>
     */
    private static function readActions(): array
    {
        return array_values(array_filter(
            AdminActionType::cases(),
            static fn (AdminActionType $action): bool => $action->isRead(),
        ));
    }

    /**
     * Les listes blanches de l'écran, relues du FormRequest qui les possède :
     * un choix offert est, par construction, un choix accepté.
     *
     * @return array{role: list<string>, state: list<string>, sort: list<string>, direction: list<string>}
     */
    public static function options(): array
    {
        return [
            'role' => array_column(UserRole::cases(), 'value'),
            'state' => UserDirectoryRequest::STATES,
            'sort' => UserDirectoryRequest::SORTS,
            'direction' => UserDirectoryRequest::DIRECTIONS,
        ];
    }

    /**
     * La requête filtrée et triée, toujours départagée par `id` : sans second
     * critère, deux comptes créés dans la même seconde s'échangeraient de
     * place d'une page à l'autre.
     *
     * @return Builder<User>
     */
    private function filtered(UserDirectoryRequest $request): Builder
    {
        $query = User::query();

        $search = $request->search();

        if ($search !== null) {
            $query->where(function (Builder $scoped) use ($search): void {
                $scoped->where('email', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('real_name', 'like', '%'.$search.'%');

                // Égalité sur l'identifiant, et seulement si la saisie est un
                // nombre : c'est l'identifiant qu'affichent l'URL d'une fiche
                // et la trace d'un geste.
                if (ctype_digit($search)) {
                    $scoped->orWhere('id', (int) $search);
                }
            });
        }

        $role = $request->role();

        if ($role !== null) {
            $query->where('role', $role);
        }

        $state = $request->state();

        if ($state === 'active') {
            $query->whereNull('anonymized_at');
        } elseif ($state === 'anonymized') {
            $query->whereNotNull('anonymized_at');
        }

        $direction = $request->direction();
        $column = match ($request->sort()) {
            'last_login_at' => 'last_login_at',
            'name' => 'name',
            'email' => 'email',
            default => 'created_at',
        };

        return $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Les comptes non anonymisés, par rôle — un agrégat pur, sans `GROUP BY`,
     * donc hors de portée d'`ONLY_FULL_GROUP_BY`. Des entiers : la mise en
     * forme est un fait d'écran.
     *
     * @return array{total: int, players: int, curators: int, admins: int}
     */
    private function counts(): array
    {
        $row = User::query()
            ->whereNull('anonymized_at')
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when role = ? then 1 else 0 end), 0) as players', [UserRole::Player->value])
            ->selectRaw('coalesce(sum(case when role = ? then 1 else 0 end), 0) as curators', [UserRole::Curator->value])
            ->selectRaw('coalesce(sum(case when role = ? then 1 else 0 end), 0) as admins', [UserRole::Admin->value])
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'players' => (int) ($row->players ?? 0),
            'curators' => (int) ($row->curators ?? 0),
            'admins' => (int) ($row->admins ?? 0),
        ];
    }
}
