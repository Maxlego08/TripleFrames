<?php

namespace App\Actions\Admin;

use App\Enums\AdminActionType;
use App\Models\User;
use App\Support\Admin\AdminJournal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Corriger le nom réel d'un compte privilégié — spec 20 § 2.8, ligne 34 de la
 * matrice des capacités (`can:updateRealName,user`), faute de frappe comprise.
 *
 * Le nom réel signe les preuves du projet (D12 du 23/09) : il ne change jamais
 * sans trace. La ligne `user.real_name_changed` (EN20-3) est écrite par
 * l'écrivain unique, dans la transaction de la correction, signée du nom réel
 * de l'administrateur **à l'instant du geste** — y compris quand il corrige le
 * sien : la ligne porte le nom sous lequel le geste a été fait.
 *
 * **La correction ne vaut que pour les gestes suivants** : les instantanés
 * déjà figés — `frame_review.reviewer_name`, `admin_action.actor_name` — ne
 * sont jamais réécrits (E10-22). Rien ici ne les touche.
 *
 * La cible est verrouillée, puis la garde rejouée sous le verrou : un compte
 * rétrogradé ou anonymisé entre l'affichage et l'envoi n'a plus de nom réel à
 * corriger, et un acteur rétrogradé entre-temps ne signe plus rien. Un nom
 * identique au nom en place est un refus traduit, jamais une ligne de journal
 * vide de sens.
 */
final class CorrectRealName
{
    public function __construct(private readonly AdminJournal $journal) {}

    /**
     * @throws AuthorizationException la cible n'est plus un compte privilégié non anonymisé
     * @throws ValidationException le nom saisi est déjà celui du compte
     * @throws Throwable
     */
    public function handle(User $actor, User $target, string $realName, ?string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $realName, $reason): void {
            // Même ordre de verrous que {@see ChangeUserRole} — administrateurs
            // par identifiant croissant, puis la cible — : deux gestes d'accès
            // croisés ne s'attendent jamais l'un l'autre en sens inverse.
            $admins = ChangeUserRole::lockAdministrators();

            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if (! in_array($actor->id, $admins, true)) {
                throw new AuthorizationException;
            }

            Gate::forUser($actor)->authorize('updateRealName', $locked);

            if ($locked->real_name === $realName) {
                $message = __('admin.access.errors.real_name_unchanged');

                throw ValidationException::withMessages([
                    'real_name' => [is_string($message) ? $message : 'admin.access.errors.real_name_unchanged'],
                ]);
            }

            $locked->real_name = $realName;
            $locked->save();

            $this->journal->record($actor, AdminActionType::UserRealNameChanged, $locked->id, $reason);
        });

        $target->refresh();
    }
}
