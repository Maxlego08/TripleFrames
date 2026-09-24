<?php

namespace App\Support\Admin;

use App\Enums\AdminActionType;
use App\Enums\UserRole;
use App\Models\AdminAction;
use App\Models\TakedownRequest;
use App\Models\User;
use LogicException;

/**
 * L'écrivain UNIQUE du journal `admin_action` (contrat C14, § 2.7 de `20`).
 *
 * Trois portes, une par nature d'auteur, et jamais un tableau d'attributs :
 *
 * - {@see self::record()} — le geste d'une personne, signé de son NOM RÉEL
 *   (`users.real_name`, D12 du 23/09), figé ici, à l'instant du geste : une
 *   correction ultérieure du nom ne réécrit jamais une ligne déjà signée ;
 * - {@see self::recordFromConsole()} — la ligne de commande, sous l'acteur
 *   réservé {@see AdminAction::CONSOLE_ACTOR} ;
 * - {@see self::recordAutomatic()} — un seuil de signalement, sous l'acteur
 *   réservé {@see AdminAction::SYSTEM_ACTOR} (gestes de `40`, jalon 2).
 *
 * `subject_type` et `retention_class` ne se passent jamais : la garde
 * `creating` de {@see AdminAction} les dérive de l'action, et c'est elle qui
 * refuse une combinaison hors contrat (acteur réservé hors de ses gestes, motif
 * manquant, rôles incohérents, sujet mal identifié).
 *
 * **Dans la transaction de l'état qu'elle justifie, ou pas du tout** : toute
 * écriture hors transaction lève. Un film dépublié dont la ligne n'a pas été
 * écrite — ou une ligne écrite pour un geste qui a échoué ensuite — est
 * exactement la preuve fausse qu'un audit ne rattrape plus.
 */
final class AdminJournal
{
    /**
     * Le geste d'une personne identifiée. `actor_name` reçoit l'instantané
     * rogné de son nom réel, jamais `users.name` : un compte sans nom réel ne
     * signe rien, et la garde du modèle le refuse.
     */
    public function record(
        User $actor,
        AdminActionType $action,
        ?int $subjectId,
        ?string $reason = null,
        ?TakedownRequest $takedownRequest = null,
        ?UserRole $roleBefore = null,
        ?UserRole $roleAfter = null,
    ): AdminAction {
        $this->assertInTransaction($action);

        return $this->write(
            actorId: $actor->id,
            actorName: trim((string) $actor->real_name),
            action: $action,
            subjectId: $subjectId,
            reason: $reason,
            takedownRequestId: $takedownRequest?->id,
            roleBefore: $roleBefore,
            roleAfter: $roleAfter,
        );
    }

    /**
     * Un geste passé par la ligne de commande — `role.changed` du premier
     * administrateur, `site.closed` et `site.reopened` de `100`. L'opérateur
     * du shell n'est pas un compte : `actor_id` reste NULL.
     */
    public function recordFromConsole(
        AdminActionType $action,
        ?int $subjectId,
        ?string $reason = null,
        ?UserRole $roleBefore = null,
        ?UserRole $roleAfter = null,
    ): AdminAction {
        $this->assertInTransaction($action);

        return $this->write(
            actorId: null,
            actorName: AdminAction::CONSOLE_ACTOR,
            action: $action,
            subjectId: $subjectId,
            reason: $reason,
            takedownRequestId: null,
            roleBefore: $roleBefore,
            roleAfter: $roleAfter,
        );
    }

    /**
     * Un geste AUTOMATIQUE — `avatar.hidden` ou `nickname.masked`, déclenché
     * par le seuil de signaleurs distincts. `reports_count` fige ce nombre :
     * c'est cette ligne permanente, et non les lignes `report` purgées à
     * 12 mois, qui justifie l'état. Écrite par `40` au jalon 2.
     */
    public function recordAutomatic(AdminActionType $action, int $subjectId, int $reportsCount): AdminAction
    {
        $this->assertInTransaction($action);

        return $this->write(
            actorId: null,
            actorName: AdminAction::SYSTEM_ACTOR,
            action: $action,
            subjectId: $subjectId,
            reason: null,
            takedownRequestId: null,
            roleBefore: null,
            roleAfter: null,
            reportsCount: $reportsCount,
        );
    }

    /**
     * Invariant 8 : la ligne s'écrit DANS la transaction de l'état qu'elle
     * justifie. La connexion interrogée est celle du modèle, pas la connexion
     * par défaut : c'est sur elle que la ligne partira.
     */
    private function assertInTransaction(AdminActionType $action): void
    {
        if ((new AdminAction)->getConnection()->transactionLevel() > 0) {
            return;
        }

        throw new LogicException(
            'Le journal d\'administration s\'écrit dans la transaction de l\'état qu\'il justifie : l\'action ['.$action->value.'] a été demandée hors transaction.',
        );
    }

    private function write(
        ?int $actorId,
        string $actorName,
        AdminActionType $action,
        ?int $subjectId,
        ?string $reason,
        ?int $takedownRequestId,
        ?UserRole $roleBefore,
        ?UserRole $roleAfter,
        ?int $reportsCount = null,
    ): AdminAction {
        $line = new AdminAction;
        $line->actor_id = $actorId;
        $line->actor_name = $actorName;
        $line->action = $action;
        $line->subject_id = $subjectId;
        $line->reason = $reason;
        $line->takedown_request_id = $takedownRequestId;
        $line->role_before = $roleBefore;
        $line->role_after = $roleAfter;
        $line->reports_count = $reportsCount;
        $line->save();

        return $line;
    }
}
