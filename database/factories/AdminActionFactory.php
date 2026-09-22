<?php

namespace Database\Factories;

use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\TakedownRequest;
use App\Models\User;
use App\Support\Eloquent\AppendOnlyBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * Fabrique de test de {@see AdminAction} — le journal en **AJOUT SEUL** de tout
 * geste engageant : qui, quoi, sur quoi, pourquoi, quand (§ 8.3).
 *
 * Trois propriétés que la fabrique ne peut pas contourner, et dont elle dépend :
 *
 * 1. **`retention_class` est DÉRIVÉE de l'action à l'insertion**, par la garde
 *    `creating` du modèle ({@see AdminActionType::retentionClass()}), jamais
 *    fournie par un appelant. La fabrique la pose quand même, pour qu'un `make()`
 *    non persisté soit cohérent — mais elle la calcule depuis l'action, jamais à
 *    la main : une trace ne peut jamais être plus courte que l'état qu'elle
 *    justifie, et l'exemption de purge des lignes `permanent` est une propriété de
 *    l'index `(retention_class, created_at)`, pas une clause `WHERE`.
 * 2. **`actor_name` est l'instantané de `users.name` à l'instant du geste**, et il
 *    est exclu de l'anonymisation : sans lui, l'auteur d'un retrait juridique
 *    devient « n° 42 » dès qu'il supprime son compte. {@see self::byActor()} le
 *    recopie depuis le compte plutôt que d'en inventer un autre.
 * 3. **La valeur réservée `system`** n'est acceptée que pour les deux gestes
 *    AUTOMATIQUES (`avatar.hidden`, `nickname.masked`) : la garde `creating` lève
 *    une `LogicException` partout ailleurs. {@see self::system()} n'accepte donc
 *    qu'une action automatique, et le vérifie à la composition de l'état plutôt
 *    qu'à l'insertion.
 *
 * `subject_type` porte un **alias court** et non un nom de classe : ce n'est pas un
 * `morphTo`, et `subject_id` n'a aucune clé étrangère, la cible étant polymorphe —
 * elle n'est jamais détruite, donc la ligne ne pend jamais.
 *
 * La table est en **ajout seul** : {@see AppendOnlyBuilder}
 * ferme la mise à jour de masse, et la garde `updating` ferme l'instance. Une
 * fabrique n'insère que ; c'est le comportement voulu.
 *
 * @extends Factory<AdminAction>
 */
class AdminActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Un changement de rôle : le geste dont la trace est permanente, dont le sujet
     * est un compte, et le seul qui remplisse `role_before` / `role_after` — les
     * deux colonnes typées qui remplacent une table `role_history` inexistante.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $action = AdminActionType::RoleChanged;

        return [
            'actor_id' => User::factory(),
            'actor_name' => fake()->name(),
            'action' => $action,
            'subject_type' => $action->subject(),
            'subject_id' => User::factory(),
            'takedown_request_id' => null,
            'retention_class' => $action->retentionClass(),
            'reason' => null,
            'reports_count' => null,
            'role_before' => UserRole::Player,
            'role_after' => UserRole::Curator,
        ];
    }

    /**
     * Auteur du geste, avec son nom figé recopié depuis le compte — l'instantané
     * que l'anonymisation ne touche jamais.
     */
    public function byActor(User $actor): static
    {
        return $this->state(fn (array $attributes): array => [
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
        ]);
    }

    /**
     * Geste quelconque sur un sujet donné : action, sujet et classe de conservation
     * ensemble, cette dernière toujours dérivée de l'action.
     *
     * `role_before` / `role_after` ne survivent qu'à `role.changed` : les laisser
     * sur un retrait de film ferait croire à un changement de rôle qui n'a pas eu
     * lieu.
     */
    public function of(AdminActionType $action, ?int $subjectId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => $action,
            'subject_type' => $action->subject(),
            'subject_id' => $subjectId,
            'retention_class' => $action->retentionClass(),
            'role_before' => $action === AdminActionType::RoleChanged ? UserRole::Player : null,
            'role_after' => $action === AdminActionType::RoleChanged ? UserRole::Curator : null,
        ]);
    }

    /**
     * Geste AUTOMATIQUE, déclenché par le seuil de deux signaleurs distincts et
     * non par une personne : `actor_id` nul et `actor_name` = `system`.
     *
     * `reports_count` est porté ici, et c'est **cette ligne permanente — et non les
     * lignes `report` purgées à 12 mois — qui justifie l'état** : sans elle, à
     * 13 mois, un pseudo masqué n'a plus aucune justification en base.
     *
     * @throws InvalidArgumentException Si l'action n'est pas automatique — le
     *                                  modèle lèverait de toute façon à l'insertion.
     */
    public function system(
        AdminActionType $action = AdminActionType::NicknameMasked,
        int $reportsCount = 2,
    ): static {
        if (! $action->isAutomatic()) {
            throw new InvalidArgumentException(
                'La valeur réservée ['.AdminAction::SYSTEM_ACTOR.'] est refusée à l\'action ['.$action->value.'] : seuls les gestes automatiques s\'écrivent sans acteur.',
            );
        }

        return $this->state(fn (array $attributes): array => [
            'actor_id' => null,
            'actor_name' => AdminAction::SYSTEM_ACTOR,
            'action' => $action,
            'subject_type' => $action->subject(),
            'retention_class' => $action->retentionClass(),
            'reports_count' => $reportsCount,
            'role_before' => null,
            'role_after' => null,
        ]);
    }

    /**
     * Geste pris **en exécution** d'une demande de retrait. Sans cette clé, la
     * seule jointure disponible serait une corrélation d'horodatages, qui ne prouve
     * rien quand deux demandes visent le même film la même semaine.
     */
    public function forTakedown(TakedownRequest $request): static
    {
        return $this->state(fn (array $attributes): array => [
            'action' => AdminActionType::TakedownDecided,
            'subject_type' => AdminActionType::TakedownDecided->subject(),
            'subject_id' => $request->id,
            'takedown_request_id' => $request->id,
            'retention_class' => AdminActionType::TakedownDecided->retentionClass(),
            'role_before' => null,
            'role_after' => null,
        ]);
    }

    /**
     * Motif libre du geste — 500 caractères, jamais une clé de traduction : c'est
     * l'administrateur qui l'écrit, et il doit rester lisible tel quel dans un
     * dossier.
     */
    public function because(string $reason): static
    {
        return $this->state(fn (array $attributes): array => [
            'reason' => $reason,
        ]);
    }
}
