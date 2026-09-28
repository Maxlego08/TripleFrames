<?php

namespace App\Actions\Admin;

use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Attribuer ou retirer `curator` ou `admin` — spec 20 § 2.8, ligne 34 de la
 * matrice des capacités (`can:updateRole,user`).
 *
 * **Une transaction, deux verrous, toujours dans cet ordre** : d'abord les
 * lignes `users` des administrateurs non anonymisés, par identifiant
 * croissant, puis la cible. Deux administrateurs qui se rétrograderaient l'un
 * l'autre au même instant se sérialisent sur le premier verrou : le second lit
 * un décompte à jour, et le dernier administrateur reste indéboulonnable
 * (`admin.access.errors.last_admin`) sur tout chemin qui passe par ici.
 *
 * L'acteur doit encore figurer parmi les administrateurs verrouillés : un
 * compte rétrogradé entre la garde de la route et le verrou ne signe plus
 * rien.
 *
 * Refus traduits, relus sous verrou, jamais des 403, dans cet ordre :
 *
 * - **rôle inchangé** : `role.changed` porte deux rôles différents
 *   (invariant 7 du contrat C14) ;
 * - **dernier administrateur** : le retirer laisserait le projet sans
 *   personne pour nommer les suivants ;
 * - **son propre rôle** : un administrateur ne change jamais le sien ; un
 *   autre administrateur le fait, et le journal garde la trace de qui ;
 * - **adresse non vérifiée** à l'attribution d'un rôle privilégié : le groupe
 *   `/admin` porte `verified`, et un compte sans adresse ne peut ni recevoir
 *   un courriel de sécurité ni enrôler son second facteur ;
 * - **nom réel manquant** à l'attribution (D12 du 23/09) : l'administrateur le
 *   saisit dans le même formulaire.
 *
 * **Le nom réel est conservé à la rétrogradation** (lecture retenue) : il reste
 * `#[Hidden]`, il resservira à une nouvelle attribution, et seule
 * l'anonymisation le vide. Un nom réel déjà porté n'est jamais réécrit ici :
 * le corriger est un autre geste, journalisé à part
 * ({@see CorrectRealName}).
 *
 * La ligne `role.changed` est écrite par l'écrivain unique, dans la
 * transaction du rôle, signée du nom réel de l'acteur.
 */
final class ChangeUserRole
{
    public function __construct(private readonly AdminJournal $journal) {}

    /**
     * Change le rôle de la cible ; rend le rôle qu'elle portait avant.
     *
     * @throws AuthorizationException l'acteur n'est plus administrateur, ou la cible a été anonymisée, entre la garde et le verrou
     * @throws ValidationException un refus d'état traduit
     * @throws Throwable
     */
    public function handle(User $actor, User $target, UserRole $role, ?string $realName, ?string $reason): UserRole
    {
        $before = DB::transaction(function () use ($actor, $target, $role, $realName, $reason): UserRole {
            $admins = self::lockAdministrators();

            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! in_array($actor->id, $admins, true)) {
                throw new AuthorizationException;
            }

            Gate::forUser($actor)->authorize('updateRole', $locked);

            $this->refuseIfInvalid($actor, $locked, $role, $realName, $admins);

            $before = $locked->role;

            if ($role->atLeast(UserRole::Curator) && self::lacksRealName($locked) && $realName !== null) {
                $locked->real_name = $realName;
            }

            $locked->role = $role;
            $locked->save();

            $this->journal->record(
                $actor,
                AdminActionType::RoleChanged,
                $locked->id,
                $reason,
                roleBefore: $before,
                roleAfter: $role,
            );

            return $before;
        });

        $target->refresh();

        return $before;
    }

    /**
     * Verrouille les lignes `users` des administrateurs non anonymisés, par
     * identifiant croissant, et rend leurs identifiants. Premier verrou de
     * tout geste d'accès : c'est lui qui sérialise le décompte du dernier
     * administrateur. À appeler dans une transaction.
     *
     * @return list<int>
     */
    public static function lockAdministrators(): array
    {
        return array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            User::query()
                ->where('role', UserRole::Admin)
                ->whereNull('anonymized_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all(),
        ));
    }

    /** Vrai quand le compte ne porte aucun nom réel utilisable. */
    public static function lacksRealName(User $user): bool
    {
        return trim((string) $user->real_name) === '';
    }

    /**
     * Les refus d'état, dans l'ordre où l'écran les explique.
     *
     * @param  list<int>  $admins  les administrateurs non anonymisés, verrouillés
     *
     * @throws ValidationException
     */
    private function refuseIfInvalid(User $actor, User $locked, UserRole $role, ?string $realName, array $admins): void
    {
        if ($locked->role === $role) {
            throw self::refusal('role', 'admin.access.errors.unchanged');
        }

        // Avant le refus de soi-même : l'administrateur seul qui tente de se
        // rétrograder doit lire la VRAIE raison — il n'y a personne d'autre
        // pour le faire à sa place. L'acteur figurant parmi les
        // administrateurs verrouillés, le décompte ne vaut un que lorsqu'il
        // se vise lui-même ; la garde n'en dépend pas, et vaut pour tout
        // appelant futur.
        if ($locked->role === UserRole::Admin && $role !== UserRole::Admin && count($admins) <= 1) {
            throw self::refusal('role', 'admin.access.errors.last_admin');
        }

        if ($locked->is($actor)) {
            throw self::refusal('role', 'admin.access.errors.self');
        }

        if ($role->atLeast(UserRole::Curator)) {
            if ($locked->email === null || $locked->email_verified_at === null) {
                throw self::refusal('role', 'admin.access.errors.email_unverified');
            }

            if (self::lacksRealName($locked) && $realName === null) {
                throw self::refusal('real_name', 'admin.access.errors.real_name_required');
            }
        }
    }

    private static function refusal(string $field, string $key): ValidationException
    {
        $message = __($key);

        return ValidationException::withMessages([
            $field => [is_string($message) ? $message : $key],
        ]);
    }
}
