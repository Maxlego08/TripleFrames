<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ContentReport;
use App\Models\User;

/**
 * Autorisations de la file des signalements de contenu (D63 du 07/10, spec 20
 * § 11.6) : curateur et au-delà, à la différence de la modération des pseudos
 * et des avatars, réservée à l'administrateur. La file ne modère personne :
 * elle aide à curer le catalogue.
 *
 * `resolve` ne décide que l'accès à la file ; chaque geste qui change l'état
 * d'un film ou d'une image repasse en plus par sa propre policy
 * (`MoviePolicy::unpublish`, `FramePolicy::unpublish`), sous verrou, dans
 * l'action.
 *
 * Aucune condition sur la cible : un film ou une image **retirés** gardent
 * l'accès au geste « Ignorer », qui clôt leurs signalements ouverts en
 * `resolved` / `already_handled` (§ 11.6) — sinon ces lignes resteraient
 * ouvertes jusqu'à la purge. Les dépublications, elles, restent refusées par
 * leurs propres policies sur une cible retirée.
 */
final class ContentReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }

    public function resolve(User $user, ContentReport $contentReport): bool
    {
        return $user->role->atLeast(UserRole::Curator);
    }
}
