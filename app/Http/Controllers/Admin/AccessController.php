<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ChangeUserRole;
use App\Actions\Admin\CorrectRealName;
use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessIndexRequest;
use App\Http\Requests\Admin\RealNameUpdateRequest;
use App\Http\Requests\Admin\RoleUpdateRequest;
use App\Models\AdminAction;
use App\Models\User;
use App\Support\Admin\AdminAccountPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * L'écran de gestion des accès — spec 20 § 2.8, ligne 34 de la matrice des
 * capacités, administrateur seul.
 *
 * Il montre les comptes privilégiés non anonymisés, la file « rôle privilégié
 * sans double authentification » (§ 2.4), une recherche par adresse EXACTE
 * pour promouvoir un compte existant et l'historique daté des rôles et des
 * noms réels, lu dans `admin_action` — il n'existe aucune table
 * `role_history` (`10` A15). Deux gestes, chacun sa route, sa garde et le
 * limiteur `admin-curation` : attribuer ou retirer un rôle
 * ({@see ChangeUserRole}), corriger un nom réel ({@see CorrectRealName}).
 *
 * **Deux administrateurs nominatifs avant l'ouverture** (`00` § Gouvernance) :
 * tant que le décompte est inférieur, l'écran le dit. C'est une condition du
 * jalon 2, jamais une garde de code.
 */
class AccessController extends Controller
{
    /**
     * Le nombre d'administrateurs nominatifs exigé avant l'ouverture publique
     * (`00` § Gouvernance) : un seuil de gouvernance, pas une valeur de jeu.
     */
    public const int NOMINATIVE_ADMINS = 2;

    /**
     * Les lignes d'historique montrées par l'écran, les plus récentes : une
     * taille d'écran, dite à l'écran — jamais une troncature silencieuse.
     * L'historique complet d'un compte vit sur sa fiche.
     */
    public const int HISTORY_LIMIT = 50;

    /**
     * Les deux gestes qui écrivent l'historique des accès.
     *
     * @var list<AdminActionType>
     */
    public const array HISTORY_ACTIONS = [
        AdminActionType::RoleChanged,
        AdminActionType::UserRealNameChanged,
    ];

    public function index(AccessIndexRequest $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        $privileged = User::query()
            ->whereIn('role', [UserRole::Curator, UserRole::Admin])
            ->whereNull('anonymized_at')
            ->get()
            // Les administrateurs d'abord, puis par nom réel : l'ordre d'une
            // liste de quelques personnes, trié en mémoire pour rester
            // identique en SQLite et en MySQL.
            ->sortBy([
                fn (User $a, User $b): int => $b->role->level() <=> $a->role->level(),
                fn (User $a, User $b): int => mb_strtolower((string) $a->real_name) <=> mb_strtolower((string) $b->real_name),
                fn (User $a, User $b): int => $a->id <=> $b->id,
            ])
            ->values();

        $admins = $privileged->filter(fn (User $user): bool => $user->role === UserRole::Admin)->count();

        return Inertia::render('admin/access/index', [
            'privileged' => $privileged
                ->map(fn (User $user): array => $this->actionableRow($user, $actor))
                ->all(),
            // La file « rôle privilégié sans double authentification »
            // (§ 2.4), servie par `users_role_last_login_at_index`. Vide par
            // construction tant que la porte `admin.2fa` tient : un compte
            // promu y reste jusqu'à son premier enrôlement.
            'without_two_factor' => User::query()
                ->whereIn('role', [UserRole::Curator, UserRole::Admin])
                ->whereNull('two_factor_confirmed_at')
                ->whereNull('anonymized_at')
                ->orderBy('id')
                ->get()
                ->map(fn (User $user): array => AdminAccountPresenter::row($user))
                ->all(),
            'admins_count' => $admins,
            'second_admin_missing' => $admins < self::NOMINATIVE_ADMINS,
            'candidate' => $this->candidate($request, $actor),
            'history' => $this->history(),
            'history_limit' => self::HISTORY_LIMIT,
        ]);
    }

    /**
     * Attribuer ou retirer un rôle. Retour à l'écran d'où le geste est parti :
     * l'écran des accès ou la fiche du compte.
     *
     * @throws Throwable
     */
    public function update(RoleUpdateRequest $request, User $user, ChangeUserRole $change): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $change->handle($actor, $user, $request->role(), $request->realName(), $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.access.flash.role_changed'),
        ]);

        return back();
    }

    /**
     * Corriger le nom réel d'un compte privilégié.
     *
     * @throws Throwable
     */
    public function updateRealName(RealNameUpdateRequest $request, User $user, CorrectRealName $correct): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $correct->handle($actor, $user, $request->realName(), $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('admin.access.flash.real_name_corrected'),
        ]);

        return back();
    }

    /**
     * Une ligne de compte et ce que l'écran peut y proposer. Les booléens ne
     * servent qu'à afficher un bouton : chaque geste garde sa policy à
     * l'écriture, et l'action rejoue ses refus sous verrou.
     *
     * @return array<string, mixed>
     */
    private function actionableRow(User $user, User $actor): array
    {
        return [
            ...AdminAccountPresenter::row($user),
            'abilities' => [
                'updateRole' => Gate::forUser($actor)->allows('updateRole', $user),
                'updateRealName' => Gate::forUser($actor)->allows('updateRealName', $user),
            ],
            'is_self' => $user->is($actor),
        ];
    }

    /**
     * Le compte trouvé par la recherche d'adresse EXACTE, pierre tombale
     * exclue ; `null` sans recherche. `account` est nul quand aucun compte ne
     * porte l'adresse : l'écran le dit.
     *
     * @return array{email: string, account: array<string, mixed>|null}|null
     */
    private function candidate(AccessIndexRequest $request, User $actor): ?array
    {
        $email = $request->email();

        if ($email === null) {
            return null;
        }

        $account = User::query()
            ->where('email', $email)
            ->whereNull('anonymized_at')
            ->first();

        return [
            'email' => $email,
            'account' => $account === null ? null : $this->actionableRow($account, $actor),
        ];
    }

    /**
     * Les derniers changements de rôle et de nom réel, les plus récents
     * d'abord. Les comptes visés sont chargés en UNE requête : l'historique
     * n'est jamais un N+1.
     *
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $lines = AdminAction::query()
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();

        $subjects = User::query()
            ->whereIn('id', $lines->pluck('subject_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'real_name'])
            ->keyBy('id');

        $rows = [];

        foreach ($lines as $line) {
            $subject = $line->subject_id === null ? null : $subjects->get($line->subject_id);

            $rows[] = AdminAccountPresenter::historyLine($line, $subject instanceof User ? $subject : null);
        }

        return $rows;
    }
}
